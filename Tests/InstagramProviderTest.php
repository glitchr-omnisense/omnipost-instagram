<?php

namespace Omnipost\Instagram\Tests;

use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\InvalidPostException;
use Omnipost\Exception\NotSupportedException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\Instagram\InstagramProvider;
use Omnipost\Instagram\InstagramProviderFactory;
use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Model\PostKind;
use Omnipost\Model\PublicationState;
use Omnipost\Platform;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class InstagramProviderTest extends TestCase
{
    /** @var list<array{string, string, array<string, string>}> method, path, parameters (query and body) */
    private array $calls = [];

    private int $created = 0;

    /** @var array<string, string> the containers' states, as Instagram would answer them */
    private array $containers = [];

    private function provider(array $options = []): InstagramProvider
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $params);
            if (\is_string($options['body'] ?? null)) {
                parse_str($options['body'], $body);
                $params += $body;
            }
            $this->calls[] = [$method, $path, $params];
            if (\in_array($params['access_token'] ?? null, ['dead'], true)) {
                return self::json(['error' => ['message' => 'Error validating access token: Session has expired.', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 463]], 400);
            }
            if ('busy' === ($params['access_token'] ?? null)) {
                return self::json(['error' => ['message' => 'Application request limit reached', 'type' => 'OAuthException', 'code' => 4]], 400);
            }

            return match (true) {
                'POST' === $method && '/v23.0/me/media' === $path => self::json(['id' => 'c'.++$this->created]),
                'GET' === $method && '/v23.0/c1' === $path => self::json(['status_code' => $this->containers['c1'] ?? 'IN_PROGRESS', 'status' => 'Finished: Media has been uploaded and it is ready to be published.', 'id' => 'c1']),
                'GET' === $method && '/v23.0/c9' === $path => self::json(['status_code' => 'ERROR', 'status' => 'Error: Media upload has failed with error code 2207026.', 'id' => 'c9']),
                'GET' === $method && '/v23.0/178' === $path && 'status_code,status' === ($params['fields'] ?? null) => self::json(['error' => ['message' => '(#100) Tried accessing nonexisting field (status_code) on node type (Media)', 'type' => 'OAuthException', 'code' => 100]], 400),
                'GET' === $method && '/v23.0/178' === $path => self::json(['id' => '178', 'permalink' => 'https://www.instagram.com/reel/DAbc/', 'timestamp' => '2026-10-03T09:00:00+0000', 'media_type' => 'VIDEO']),
                'POST' === $method && '/v23.0/me/media_publish' === $path => self::json(['id' => '178']),
                'GET' === $method && '/v23.0/me/media' === $path => self::json(self::feedPage()),
                'GET' === $method && '/v23.0/me' === $path => self::json(['id' => '178414', 'username' => 'claire.violin', 'name' => 'Claire', 'profile_picture_url' => 'https://cdn.example/p.jpg', 'followers_count' => 1520, 'media_count' => 87, 'account_type' => 'MEDIA_CREATOR']),
                'GET' === $method && '/refresh_access_token' === $path => self::json(['access_token' => 'IGAA-fresh', 'token_type' => 'bearer', 'expires_in' => 5183944]),
                'POST' === $method && 'api.instagram.com' === parse_url($url, \PHP_URL_HOST) => self::json(['data' => [['access_token' => 'IGAA-short', 'user_id' => '178414', 'permissions' => 'instagram_business_basic,instagram_business_content_publish']]]),
                'GET' === $method && '/access_token' === $path => self::json(['access_token' => 'IGAA-long', 'token_type' => 'bearer', 'expires_in' => 5183944]),
                default => self::json(['error' => ['message' => 'Unknown path '.$method.' '.$path, 'code' => 803]], 404),
            };
        });

        return (new InstagramProviderFactory($http))->create($options + ['access_token' => 'IGAA-token', 'app_id' => '990', 'app_secret' => 's3cret']);
    }

    private static function json(array $data, int $status = 200): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status]);
    }

    private static function reel(): Post
    {
        return Post::reel(Media::video('https://site.example/reels/strauss.mp4', null, 42.0, 1080, 1920, 'https://site.example/reels/strauss.jpg'), 'Strauss, tonight.', null, ['violin']);
    }

    public function testAReelIsPublishedInThreeSteps(): void
    {
        $instagram = $this->provider();

        // 1. The container: Instagram fetches the video from its URL.
        $publication = $instagram->publish(self::reel());
        self::assertSame(PublicationState::PENDING, $publication->state);
        self::assertSame('c1', $publication->id);
        self::assertSame(Platform::INSTAGRAM, $publication->platform);
        [$method, $path, $sent] = $this->calls[0];
        self::assertSame(['POST', '/v23.0/me/media'], [$method, $path]);
        self::assertSame('REELS', $sent['media_type']);
        self::assertSame('https://site.example/reels/strauss.mp4', $sent['video_url']);
        self::assertSame("Strauss, tonight.\n\n#violin", $sent['caption']);
        self::assertSame('https://site.example/reels/strauss.jpg', $sent['cover_url']);
        self::assertSame('true', $sent['share_to_feed']);
        self::assertSame('IGAA-token', $sent['access_token']);
        self::assertArrayNotHasKey('thumb_offset', $sent, 'nothing sent that was not given');

        // 2. Transcoding.
        $this->containers['c1'] = 'IN_PROGRESS';
        self::assertSame(PublicationState::PROCESSING, $instagram->status('c1')->state);
        self::assertCount(2, $this->calls, 'nothing published while in progress');

        // 3. Finished: published, and the media's id and permalink answered.
        $this->containers['c1'] = 'FINISHED';
        $published = $instagram->status('c1');
        self::assertTrue($published->isPublished());
        self::assertSame('178', $published->id, 'the media\'s id from now on');
        self::assertSame('https://www.instagram.com/reel/DAbc/', $published->permalink);
        self::assertEquals(new \DateTimeImmutable('2026-10-03T09:00:00+0000'), $published->publishedAt);
        [$method, $path, $sent] = $this->calls[3];
        self::assertSame(['POST', '/v23.0/me/media_publish', 'c1'], [$method, $path, $sent['creation_id']]);

        // The media's id read again: published.
        $again = $instagram->status('178');
        self::assertTrue($again->isPublished());
        self::assertSame('https://www.instagram.com/reel/DAbc/', $again->permalink);
    }

    public function testAContainerInErrorIsAFailure(): void
    {
        $failed = $this->provider()->status('c9');

        self::assertTrue($failed->isFailed());
        self::assertSame('Error: Media upload has failed with error code 2207026.', $failed->error);
    }

    public function testACarouselIsAContainerPerItemThenTheirParent(): void
    {
        $post = Post::carousel([Media::image('https://site.example/a.jpg', null, 1080, 1350), Media::video('https://site.example/b.mp4', null, 12.0, 1080, 1920), Media::image('https://site.example/c.jpg')], 'Backstage', ['concert']);

        $publication = $this->provider()->publish($post);

        self::assertSame('c4', $publication->id, 'the parent');
        self::assertCount(4, $this->calls);
        self::assertSame(['is_carousel_item' => 'true', 'image_url' => 'https://site.example/a.jpg'], array_diff_key($this->calls[0][2], ['access_token' => 1]));
        self::assertSame(['is_carousel_item' => 'true', 'media_type' => 'VIDEO', 'video_url' => 'https://site.example/b.mp4'], array_diff_key($this->calls[1][2], ['access_token' => 1]));
        $parent = $this->calls[3][2];
        self::assertSame('CAROUSEL', $parent['media_type']);
        self::assertSame('c1,c2,c3', $parent['children']);
        self::assertSame("Backstage\n\n#concert", $parent['caption']);
    }

    public function testAPictureAndAStory(): void
    {
        $instagram = $this->provider();
        $instagram->publish(Post::image(Media::image('https://site.example/a.jpg', null, 1080, 1080, 'Claire on stage'), 'Tonight'));
        $instagram->publish(new Post(PostKind::STORY, [Media::video('https://site.example/s.mp4', null, 10.0, 1080, 1920)]));

        self::assertSame(['image_url' => 'https://site.example/a.jpg', 'caption' => 'Tonight', 'alt_text' => 'Claire on stage'], array_diff_key($this->calls[0][2], ['access_token' => 1]));
        self::assertSame(['media_type' => 'STORIES', 'video_url' => 'https://site.example/s.mp4'], array_diff_key($this->calls[1][2], ['access_token' => 1]));
    }

    public function testAPostTheValidatorRefusesSendsNothing(): void
    {
        $instagram = $this->provider();
        $landscape = Post::reel(Media::video('https://site.example/wide.mp4', null, 1.5, 1920, 1080), str_repeat('x', 2300));

        try {
            $instagram->publish($landscape);
            self::fail('A landscape reel of a second and a half is refused.');
        } catch (InvalidPostException $e) {
            self::assertSame('instagram', $e->provider);
            $paths = array_map(static fn ($v) => $v->path, $e->violations);
            self::assertContains('media[0].duration', $paths);
            self::assertContains('media[0].ratio', $paths);
            self::assertContains('caption', $paths);
            self::assertCount(1, array_keys($paths, 'media[0].ratio'), 'said once');
        }
        self::assertSame([], $this->calls);

        self::assertNotEmpty($instagram->validate(Post::carousel([Media::image('https://site.example/a.jpg')])), 'a carousel of one');
        self::assertNotEmpty($instagram->validate(Post::reel(Media::video('https://site.example/a.mp4', null, 10.0, 1080, 1350))), '4:5 is a picture\'s ratio, not a reel\'s');
        self::assertSame([], $instagram->validate(Post::image(Media::image('https://site.example/a.jpg', null, 1080, 1350))));
        self::assertNotEmpty($instagram->validate(self::reel()->withScheduledAt(new \DateTimeImmutable('+1 day'))), 'no scheduling');
    }

    public function testTheFeedMapsEachKind(): void
    {
        $feed = $this->provider()->feed(null, 3);

        self::assertSame('QVFIUk', $feed->next);
        [$reel, $carousel, $image] = $feed->items;
        self::assertSame(PostKind::REEL, $reel->kind);
        self::assertSame('https://cdn.example/r.jpg', $reel->thumbnailUrl);
        self::assertSame('https://cdn.example/r.mp4', $reel->url);
        self::assertSame(120, $reel->likes);
        self::assertSame(4, $reel->comments);
        self::assertEquals(new \DateTimeImmutable('2026-09-30T18:00:00+0000'), $reel->publishedAt);
        self::assertSame(PostKind::CAROUSEL, $carousel->kind);
        self::assertCount(2, $carousel->children);
        self::assertSame('https://cdn.example/c1.jpg', $carousel->thumbnailUrl, 'its first child');
        self::assertSame(PostKind::IMAGE, $image->kind);
        self::assertSame('https://www.instagram.com/p/C3/', $image->permalink);
        [, $path, $query] = $this->calls[0];
        self::assertSame('/v23.0/me/media', $path);
        self::assertSame('3', $query['limit']);
        self::assertStringContainsString('children{id,media_type,media_url,thumbnail_url}', $query['fields']);
    }

    public function testTheAccount(): void
    {
        $account = $this->provider()->account();

        self::assertSame('claire.violin', $account->username);
        self::assertSame('https://www.instagram.com/claire.violin/', $account->url);
        self::assertSame(1520, $account->followers);
        self::assertSame(87, $account->posts);
        self::assertSame('MEDIA_CREATOR', $account->kind);
    }

    public function testTheLongLivedTokenIsRefreshedAndUsedFromThen(): void
    {
        $instagram = $this->provider();
        $token = $instagram->refresh();

        self::assertSame('IGAA-fresh', $token->accessToken);
        self::assertFalse($token->isExpiring(30));
        self::assertTrue($token->isExpiring(61));
        [, $path, $query] = $this->calls[0];
        self::assertSame('/refresh_access_token', $path, 'not versioned');
        self::assertSame(['grant_type' => 'ig_refresh_token', 'access_token' => 'IGAA-token'], $query);
        self::assertSame('IGAA-fresh', $instagram->token()->accessToken);
        $instagram->account();
        self::assertSame('IGAA-fresh', $this->calls[1][2]['access_token']);
    }

    public function testTheAccountIsConnectedThroughOAuth(): void
    {
        $instagram = $this->provider(['access_token' => null]);
        self::assertNull($instagram->token());

        $url = $instagram->authorizationUrl('https://site.example/admin/instagram', 'xyz');
        self::assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['client_id' => '990', 'redirect_uri' => 'https://site.example/admin/instagram', 'scope' => 'instagram_business_basic,instagram_business_content_publish', 'response_type' => 'code', 'state' => 'xyz'], $query);

        $token = $instagram->exchange('AQB-code#_', 'https://site.example/admin/instagram');
        self::assertSame('IGAA-long', $token->accessToken);
        self::assertSame('178414', $token->accountId);
        self::assertSame(['instagram_business_basic', 'instagram_business_content_publish'], $token->scopes);
        self::assertSame('AQB-code', $this->calls[0][2]['code'], 'Instagram\'s "#_" taken off');
        self::assertSame('authorization_code', $this->calls[0][2]['grant_type']);
        self::assertSame(['grant_type' => 'ig_exchange_token', 'client_secret' => 's3cret', 'access_token' => 'IGAA-short'], $this->calls[1][2]);
    }

    public function testErrorsAreMapped(): void
    {
        try {
            $this->provider(['access_token' => 'dead'])->account();
            self::fail('A dead token is refused.');
        } catch (UnauthorizedException $e) {
            self::assertSame('190', $e->providerCode);
            self::assertSame(400, $e->status);
            self::assertStringContainsString('Session has expired', $e->getMessage());
        }
        try {
            $this->provider(['access_token' => 'busy'])->feed();
            self::fail('A rate limit is an error.');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(UnauthorizedException::class, $e);
            self::assertSame('4', $e->providerCode);
        }

        $offline = (new InstagramProviderFactory(new MockHttpClient(static fn () => new MockResponse('', ['error' => 'Could not resolve host for https://graph.instagram.com/v23.0/me?access_token=IGAA-token']))))->create(['access_token' => 'IGAA-token']);
        try {
            $offline->account();
            self::fail('A transport error is an error.');
        } catch (ProviderException $e) {
            self::assertStringNotContainsString('IGAA-token', $e->getMessage(), 'the token struck out');
        }
    }

    public function testWithoutATokenOnlyWhatNeedsNoneWorks(): void
    {
        $instagram = (new InstagramProviderFactory(new MockHttpClient()))->create();

        self::assertSame(10, $instagram->capabilities()->mediaMaxCount);
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "instagram" provider needs: access_token.');
        $instagram->feed();
    }

    public function testNothingIsDeleted(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->provider()->delete('178');
    }

    private static function feedPage(): array
    {
        return [
            'data' => [
                ['id' => 'R1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'media_url' => 'https://cdn.example/r.mp4', 'thumbnail_url' => 'https://cdn.example/r.jpg', 'permalink' => 'https://www.instagram.com/reel/R1/', 'caption' => 'Strauss', 'timestamp' => '2026-09-30T18:00:00+0000', 'like_count' => 120, 'comments_count' => 4],
                ['id' => 'C2', 'media_type' => 'CAROUSEL_ALBUM', 'permalink' => 'https://www.instagram.com/p/C2/', 'timestamp' => '2026-09-20T18:00:00+0000', 'children' => ['data' => [
                    ['id' => 'C2a', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/c1.jpg'],
                    ['id' => 'C2b', 'media_type' => 'VIDEO', 'media_url' => 'https://cdn.example/c2.mp4', 'thumbnail_url' => 'https://cdn.example/c2.jpg'],
                ]]],
                ['id' => 'C3', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.example/i.jpg', 'permalink' => 'https://www.instagram.com/p/C3/', 'timestamp' => '2026-09-10T18:00:00+0000'],
            ],
            'paging' => ['cursors' => ['before' => 'QVFIUa', 'after' => 'QVFIUk'], 'next' => 'https://graph.instagram.com/v23.0/me/media?after=QVFIUk'],
        ];
    }

    /**
     * Not reached, or answering an error: nothing of a credential anywhere in what a log of the whole
     * chain writes - the messages, the previous exceptions (copies of the HTTP client's, their class
     * kept), the traces' arguments (the options, a response holding them) when
     * zend.exception_ignore_args is off.
     */
    public function testNoPartOfAFailureChainHoldsACredential(): void
    {
        $ignoreArgs = ini_set('zend.exception_ignore_args', '0');
        $answers = [
            static fn (string $method, string $url): never => throw new \Symfony\Component\HttpClient\Exception\TransportException(\sprintf('Could not resolve host: graph.instagram.com for "%s".', $url)),
            static fn (string $method, string $url): MockResponse => new MockResponse('{"error": {"message": "Invalid credentials", "code": 401}}', ['http_code' => 401, 'response_headers' => ['Content-Type' => 'application/json']]),
        ];
        try {
            foreach ($answers as $answer) {
                try {
                    (new InstagramProviderFactory(new \Symfony\Component\HttpClient\MockHttpClient($answer)))->create(['access_token' => 'instagram-token-123', 'app_id' => '990', 'app_secret' => 'instagram-secret-456'])->account();
                    self::fail('no exception');
                } catch (\Omnipost\Exception\OmnipostException $e) {
                    for ($one = $e; null !== $one; $one = $one->getPrevious()) {
                        foreach (['instagram-token-123', 'instagram-secret-456'] as $secret) {
                            self::assertFalse(str_contains($one->getMessage(), $secret), $one->getMessage());
                            self::assertFalse(str_contains(print_r($one->getTrace(), true), $secret), 'a trace holds '.$secret);
                        }
                    }
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', false !== $ignoreArgs ? $ignoreArgs : '1');
        }
    }
}
