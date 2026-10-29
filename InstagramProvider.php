<?php

namespace Omnipost\Instagram;

use Omnipost\Auth\OAuthInterface;
use Omnipost\Auth\RefreshableInterface;
use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\InvalidPostException;
use Omnipost\Exception\NotSupportedException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\FeedInterface;
use Omnipost\Model\Account;
use Omnipost\Model\Capabilities;
use Omnipost\Model\Feed;
use Omnipost\Model\FeedItem;
use Omnipost\Model\Media;
use Omnipost\Model\MediaKind;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\Publication;
use Omnipost\Model\PublicationState;
use Omnipost\Model\Token;
use Omnipost\Model\Violation;
use Omnipost\Platform;
use Omnipost\PublisherInterface;
use Omnipost\Validator;

/**
 * An Instagram professional account: who it is, what it posted, and a post
 * published - in Instagram's three steps.
 *
 *   1. publish() creates a container (Instagram fetches the media from its
 *      public URL) and answers PENDING with the container's id;
 *   2. status(container) reads it: PROCESSING while Instagram transcodes;
 *   3. once the container is FINISHED, status() publishes it and answers
 *      PUBLISHED with the media's id and its permalink.
 *
 * The Publication's id changes at step 3, from the container's to the
 * media's: a caller keeps the latest one. status() takes either - a
 * media's id answers PUBLISHED with its permalink. Step 3 must not run
 * twice at once for one container (two workers polling): the caller holds
 * a lock around status().
 */
final class InstagramProvider implements PublisherInterface, FeedInterface, RefreshableInterface, OAuthInterface
{
    public const AUTHORIZE_URL = 'https://www.instagram.com/oauth/authorize';
    public const TOKEN_URL = 'https://api.instagram.com/oauth/access_token';

    /** Reading the account, publishing on it. */
    public const SCOPES = ['instagram_business_basic', 'instagram_business_content_publish'];

    private const FEED_FIELDS = 'id,media_type,media_product_type,media_url,thumbnail_url,permalink,caption,timestamp,like_count,comments_count,children{id,media_type,media_url,thumbnail_url}';

    public function __construct(
        private readonly Api $api,
        private readonly string $user = 'me',
        private readonly ?string $appId = null,
        private readonly ?string $appSecret = null,
        private readonly bool $shareToFeed = true,
        private readonly Validator $validator = new Validator(),
    ) {
    }

    public function getName(): string
    {
        return 'instagram';
    }

    public function getPlatform(): Platform
    {
        return Platform::INSTAGRAM;
    }

    /**
     * One set of bounds for every kind, the Validator's: the ten media are
     * the carousel's, the 3 s - 15 min the reel's, 9:16 the reel's and the
     * story's ratio, 4:5 and 1:1 the picture's. What depends on the kind is
     * checked by validate().
     */
    public function capabilities(): Capabilities
    {
        return new Capabilities(
            kinds: [PostKind::REEL, PostKind::IMAGE, PostKind::CAROUSEL, PostKind::STORY],
            minDuration: 3.0,
            maxDuration: 900.0,
            aspectRatios: ['9:16', '4:5', '1:1'],
            ratioTolerance: 0.01,
            maxVideoSize: 1 << 30,
            maxImageSize: 8 << 20,
            captionMaxLength: 2200,
            linkInCaption: false, // a caption's address is not a link on Instagram: the post's link goes nowhere
            maxTags: 30,
            mediaMaxCount: 10,
            videoFormats: ['video/mp4', 'video/quicktime', 'mp4', 'mov'],
            imageFormats: ['image/jpeg', 'jpg', 'jpeg'],
            minWidth: 320,
            scheduling: false,
        );
    }

    /**
     * The post against capabilities(), and against what they cannot say
     * because it depends on the kind: one media unless a carousel (2 to
     * 10), a video for a reel, a picture for a picture, 9:16 for a reel.
     *
     * @return list<Violation>
     */
    public function validate(Post $post): array
    {
        $violations = $this->validator->validate($post, $this->capabilities());
        $count = \count($post->media);
        if (PostKind::CAROUSEL === $post->kind) {
            if (1 === $count) {
                $violations[] = new Violation('media', 'A carousel has at least 2 media.');
            }
        } elseif ($count > 1) {
            $violations[] = new Violation('media', \sprintf('%d media, at most 1 unless a carousel.', $count));
        }
        $first = $post->media[0] ?? null;
        if (null !== $first && PostKind::REEL === $post->kind) {
            if (MediaKind::VIDEO !== $first->kind) {
                $violations[] = new Violation('media[0]', 'A reel is a video.');
            } elseif (null !== ($ratio = $first->ratio()) && $this->capabilities()->acceptsRatio($ratio) && abs($ratio - 9 / 16) > 0.01 * 9 / 16) {
                // A picture's ratio (4:5, 1:1): the Validator let it through.
                $violations[] = new Violation('media[0].ratio', \sprintf('%s: a reel is 9:16.', $first->aspectRatio()));
            }
        }
        if (null !== $first && PostKind::IMAGE === $post->kind && MediaKind::IMAGE !== $first->kind) {
            $violations[] = new Violation('media[0]', 'A picture is expected: a video goes out as a reel.');
        }

        return $violations;
    }

    public function publish(Post $post): Publication
    {
        if ($violations = $this->validate($post)) {
            throw new InvalidPostException($this->getName(), $violations);
        }
        $caption = $post->text($this->capabilities()->linkInCaption);
        $media = $post->media[0];

        $container = match ($post->kind) {
            PostKind::REEL => $this->container([
                'media_type' => 'REELS',
                'video_url' => $media->url,
                'caption' => $caption,
                'cover_url' => $post->coverUrl ?? $media->coverUrl,
                'share_to_feed' => filter_var($post->options['share_to_feed'] ?? $this->shareToFeed, \FILTER_VALIDATE_BOOL),
                'thumb_offset' => $post->options['thumb_offset'] ?? null,
            ]),
            PostKind::IMAGE => $this->container(['image_url' => $media->url, 'caption' => $caption, 'alt_text' => $media->alt]),
            PostKind::STORY => $this->container(['media_type' => 'STORIES'] + self::source($media)),
            PostKind::CAROUSEL => $this->container([
                'media_type' => 'CAROUSEL',
                'children' => implode(',', array_map(fn (Media $m) => $this->container(['is_carousel_item' => true] + self::source($m, true))['id'], $post->media)),
                'caption' => $caption,
            ]),
            default => throw NotSupportedException::operation($this->getName(), \sprintf('publish %s posts', $post->kind->value)),
        };

        return new Publication(Platform::INSTAGRAM, (string) $container['id'], PublicationState::PENDING, raw: $container);
    }

    /**
     * Where a publication stands, by its container's id - published here
     * once Instagram has finished with it - or by its media's id.
     */
    public function status(string $publicationId): Publication
    {
        try {
            $container = $this->api->get($publicationId, ['fields' => 'status_code,status']);
        } catch (UnauthorizedException $e) {
            throw $e;
        } catch (ProviderException $e) {
            if ('100' !== $e->providerCode) {
                throw $e;
            }

            // No such fields on that node: not a container, a media.
            return $this->published($publicationId);
        }

        switch ($container['status_code'] ?? null) {
            case 'FINISHED':
                $media = $this->api->post($this->user.'/media_publish', ['creation_id' => $publicationId]);

                return $this->published((string) $media['id']);
            case 'PUBLISHED':
                // Published by an earlier status(): the media's id was in its answer, the container does not give it back.
                return new Publication(Platform::INSTAGRAM, $publicationId, PublicationState::PUBLISHED, raw: $container);
            case 'ERROR':
            case 'EXPIRED':
                return new Publication(Platform::INSTAGRAM, $publicationId, PublicationState::FAILED, error: (string) ($container['status'] ?? $container['status_code']), raw: $container);
            default:
                return new Publication(Platform::INSTAGRAM, $publicationId, PublicationState::PROCESSING, raw: $container);
        }
    }

    public function delete(string $publicationId): void
    {
        throw NotSupportedException::operation($this->getName(), 'delete posts (the API cannot: the account does, in the application)');
    }

    public function account(): Account
    {
        $me = $this->api->get($this->user, ['fields' => 'id,username,name,profile_picture_url,followers_count,media_count,account_type']);
        $username = (string) ($me['username'] ?? '');

        return new Account(
            Platform::INSTAGRAM,
            (string) $me['id'],
            $username,
            $me['name'] ?? null,
            '' !== $username ? 'https://www.instagram.com/'.$username.'/' : null,
            $me['profile_picture_url'] ?? null,
            isset($me['followers_count']) ? (int) $me['followers_count'] : null,
            isset($me['media_count']) ? (int) $me['media_count'] : null,
            $me['account_type'] ?? null,
        );
    }

    public function feed(?string $cursor = null, int $limit = 25): Feed
    {
        $page = $this->api->get($this->user.'/media', ['fields' => self::FEED_FIELDS, 'limit' => max(1, min(100, $limit)), 'after' => $cursor]);

        return new Feed(
            array_map(self::item(...), array_values($page['data'] ?? [])),
            // The last page still has cursors: only "next" says there is one more.
            isset($page['paging']['next']) ? ($page['paging']['cursors']['after'] ?? null) : null,
        );
    }

    /** The token the provider holds; when it dies is known from refresh() and exchange() only. */
    public function token(): ?Token
    {
        return null !== ($token = $this->api->accessToken()) ? new Token($token) : null;
    }

    /**
     * Sixty more days for the long-lived token. Instagram refreshes a token
     * at least 24 hours old and not dead yet: past its end the account is
     * connected again.
     */
    public function refresh(): Token
    {
        $data = $this->api->get('refresh_access_token', ['grant_type' => 'ig_refresh_token'], false);

        return $this->hold($data);
    }

    public function authorizationUrl(string $redirectUri, string $state, array $scopes = []): string
    {
        return self::AUTHORIZE_URL.'?'.http_build_query([
            'client_id' => $this->appId ?? throw new InvalidConfigException('The "instagram" provider needs: app_id.'),
            'redirect_uri' => $redirectUri,
            'scope' => implode(',', $scopes ?: self::SCOPES),
            'response_type' => 'code',
            'state' => $state,
        ]);
    }

    /** The code for a short-lived token (an hour), that one for the long-lived one (60 days). */
    public function exchange(string $code, string $redirectUri): Token
    {
        if (!$this->appId || !$this->appSecret) {
            throw new InvalidConfigException('The "instagram" provider needs: app_id, app_secret.');
        }
        $short = $this->api->call('POST', self::TOKEN_URL, ['body' => [
            'client_id' => $this->appId,
            'client_secret' => $this->appSecret,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirectUri,
            // Instagram appends "#_" to the code on the way back.
            'code' => preg_replace('/#_$/', '', $code),
        ]]);
        // {data: [{access_token, user_id, permissions}]}, or the same flat.
        $short = \is_array($short['data'][0] ?? null) ? $short['data'][0] : $short;
        $permissions = $short['permissions'] ?? [];
        $long = $this->api->call('GET', $this->api->url('access_token', false), ['query' => [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->appSecret,
            'access_token' => (string) ($short['access_token'] ?? ''),
        ]]);

        return $this->hold($long, \is_string($permissions) ? explode(',', $permissions) : array_values((array) $permissions), isset($short['user_id']) ? (string) $short['user_id'] : null);
    }

    /**
     * @param array<string, scalar|null> $fields
     *
     * @return array<string, mixed>
     */
    private function container(array $fields): array
    {
        return $this->api->post($this->user.'/media', $fields);
    }

    /** @return array<string, string> */
    private static function source(Media $media, bool $carouselItem = false): array
    {
        if (MediaKind::VIDEO === $media->kind) {
            return ($carouselItem ? ['media_type' => 'VIDEO'] : []) + ['video_url' => $media->url];
        }

        return ['image_url' => $media->url];
    }

    private function published(string $mediaId): Publication
    {
        $media = $this->api->get($mediaId, ['fields' => 'id,permalink,timestamp,media_type']);

        return new Publication(Platform::INSTAGRAM, (string) ($media['id'] ?? $mediaId), PublicationState::PUBLISHED, $media['permalink'] ?? null, null, self::date($media['timestamp'] ?? null), $media);
    }

    /**
     * @param array<string, mixed> $data   {access_token, token_type, expires_in}
     * @param list<string>         $scopes
     */
    private function hold(array $data, array $scopes = [], ?string $accountId = null): Token
    {
        $token = (string) ($data['access_token'] ?? '');
        if ('' === $token) {
            throw new ProviderException($this->getName(), 'No token in Instagram\'s answer.');
        }
        $this->api->setAccessToken($token);

        return new Token($token, isset($data['expires_in']) ? new \DateTimeImmutable('+'.(int) $data['expires_in'].' seconds') : null, null, $scopes, $accountId);
    }

    /** @param array<string, mixed> $m */
    private static function item(array $m): FeedItem
    {
        $type = $m['media_type'] ?? 'IMAGE';
        $kind = match ($type) {
            'VIDEO' => 'REELS' === ($m['media_product_type'] ?? 'REELS') ? PostKind::REEL : PostKind::VIDEO,
            'CAROUSEL_ALBUM' => PostKind::CAROUSEL,
            default => 'STORY' === ($m['media_product_type'] ?? null) ? PostKind::STORY : PostKind::IMAGE,
        };
        $children = array_map(self::item(...), array_values($m['children']['data'] ?? []));

        return new FeedItem(
            (string) $m['id'],
            $kind,
            (string) ($m['permalink'] ?? ''),
            $m['media_url'] ?? null,
            // A video's still; a picture is its own; a carousel shows its first child.
            $m['thumbnail_url'] ?? ('VIDEO' === $type ? null : ($m['media_url'] ?? ($children[0]->thumbnailUrl ?? null))),
            $m['caption'] ?? null,
            self::date($m['timestamp'] ?? null),
            null,
            $children,
            isset($m['like_count']) ? (int) $m['like_count'] : null,
            isset($m['comments_count']) ? (int) $m['comments_count'] : null,
            null,
            $m,
        );
    }

    private static function date(?string $timestamp): ?\DateTimeImmutable
    {
        try {
            return $timestamp ? new \DateTimeImmutable($timestamp) : null;
        } catch (\Exception) {
            return null;
        }
    }
}
