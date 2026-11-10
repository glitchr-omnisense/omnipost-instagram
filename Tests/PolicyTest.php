<?php

namespace Omnipost\Instagram\Tests;

use Omnipost\Exception\ProviderException;
use Omnipost\Exception\RateLimitedException;
use Omnipost\Exception\UsageLimitReachedException;
use Omnipost\Instagram\Api;
use Omnipost\Instagram\InstagramProvider;
use Omnipost\Instagram\InstagramProviderFactory;
use Omnipost\Model\Media;
use Omnipost\Model\Post;
use Omnipost\Model\PublicationState;
use Omnipost\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Instagram's usage policy (policy.json) through the registry, and the
 * writes - a container created, a media_publish - sent once whatever the
 * answer: a 429, a 500, Meta's throttling codes with the wait it estimates.
 */
final class PolicyTest extends TestCase
{
    /** @var list<string> method and path of every request sent */
    private array $sent = [];

    /**
     * @param array<string, MockResponse>                                      $answers by "METHOD /path", a 200 {"id": "c1"} otherwise
     * @param array{policy?: array<string, mixed>, usage?: array<string, mixed>} $entry
     */
    private function registry(array $answers = [], array $entry = []): Registry
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($answers): MockResponse {
            $key = $method.' '.parse_url($url, \PHP_URL_PATH);
            $this->sent[] = $key;

            return $answers[$key] ?? new MockResponse('{"id": "c1", "status_code": "FINISHED"}', ['response_headers' => ['Content-Type' => 'application/json']]);
        });

        return new Registry([new InstagramProviderFactory($http)], ['instagram' => ['factory' => 'instagram', 'options' => ['access_token' => 'IGAA-token']] + $entry]);
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    private static function json(int $status, array $data, array $headers = []): MockResponse
    {
        return new MockResponse((string) json_encode($data), ['http_code' => $status, 'response_headers' => ['Content-Type' => 'application/json'] + $headers]);
    }

    private static function picture(): Post
    {
        return Post::image(Media::image('https://site.example/p.jpg', null, 1080, 1350), 'Tonight.');
    }

    public function testThePolicyFileReadsAsDeclared(): void
    {
        $policy = UsagePolicy::fromFile(__DIR__.'/../policy.json');

        self::assertSame('instagram', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        [$limit] = $policy->limitsFor('publish');
        self::assertSame(100, $limit->max);
        self::assertSame(86400, $limit->seconds());
        self::assertSame([], $policy->limitsFor('feed'), 'the business use case limit depends on the audience: not declared');
        self::assertSame(Permission::Forbidden, $policy->rights->redistribution);
        $provider = (new InstagramProviderFactory(new MockHttpClient()))->create();
        self::assertInstanceOf(InstagramProvider::class, $provider);
        self::assertEquals($policy, $provider->usagePolicy());
    }

    public function testTheHundredAndFirstPostOfTheDayIsNeverSent(): void
    {
        $registry = $this->registry();
        $publisher = $registry->publisher('instagram');
        for ($i = 0; $i < 100; ++$i) {
            $publisher->publish(self::picture());
        }
        self::assertCount(100, $this->sent);

        try {
            $publisher->publish(self::picture());
            self::fail('Over the 100 a day.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(100, $e->limit?->max);
        }
        self::assertCount(100, $this->sent, 'nothing sent');
    }

    public function testAMediaPublishAnswered429IsSentOnceAndTheNextCallsWait(): void
    {
        $registry = $this->registry([
            'POST /v23.0/me/media_publish' => self::json(429, ['error' => ['message' => 'Too many requests', 'code' => 4]], ['Retry-After' => '60']),
        ]);
        $publisher = $registry->publisher('instagram');
        try {
            $publisher->status('c1');
            self::fail('A 429 is an error.');
        } catch (RateLimitedException $e) {
            self::assertInstanceOf(ProviderException::class, $e, 'a ProviderException as before');
            self::assertSame(429, $e->status);
            self::assertSame(60, $e->retryAfter);
        }
        self::assertSame(['GET /v23.0/c1', 'POST /v23.0/me/media_publish'], $this->sent, 'the publish sent once, not replayed');

        $this->expectException(UsageLimitReachedException::class);
        try {
            $publisher->status('c1');
        } finally {
            self::assertCount(2, $this->sent, 'held: nothing sent while the wait lasts');
        }
    }

    public function testAContainerAnswered500IsSentOnce(): void
    {
        $registry = $this->registry(['POST /v23.0/me/media' => self::json(500, ['error' => ['message' => 'An unexpected error has occurred.', 'code' => 2]])]);
        try {
            $registry->publisher('instagram')->publish(self::picture());
            self::fail('A 500 is an error.');
        } catch (ProviderException $e) {
            self::assertNotInstanceOf(RateLimitedException::class, $e);
            self::assertSame(500, $e->status);
        }
        self::assertSame(['POST /v23.0/me/media'], $this->sent);
        self::assertSame(99, $registry->guard('instagram')->remaining('publish', ['key' => 'instagram']), 'counted: Meta may have created it');
    }

    public function testMetasEstimatedWaitHoldsTheNextCalls(): void
    {
        $usage = (string) json_encode(['178414' => [['type' => 'instagram', 'call_count' => 100, 'total_cputime' => 30, 'total_time' => 40, 'estimated_time_to_regain_access' => 2]]]);
        $registry = $this->registry(['GET /v23.0/me' => self::json(400, ['error' => ['message' => 'Application request limit reached', 'code' => 80002]], ['X-Business-Use-Case-Usage' => $usage])]);
        $provider = $registry->get('instagram');
        self::assertInstanceOf(InstagramProvider::class, $provider);
        try {
            $provider->account();
            self::fail('Throttled.');
        } catch (RateLimitedException $e) {
            self::assertSame(120, $e->retryAfter, 'two minutes');
            self::assertStringStartsWith('[instagram] Too many calls, try again later: ', $e->getMessage());
        }

        $this->expectException(UsageLimitReachedException::class);
        $provider->feed();
    }

    public function testTheWaitIsReadFromEveryBusinessUseCase(): void
    {
        self::assertNull(Api::regain([]));
        self::assertNull(Api::regain(['x-business-use-case-usage' => ['{"1": [{"estimated_time_to_regain_access": 0}]}']]));
        self::assertSame(600, Api::regain(['x-business-use-case-usage' => ['{"1": [{"estimated_time_to_regain_access": 3}], "2": [{"estimated_time_to_regain_access": 10}]}']]));
    }

    public function testACommercialSiteIsAllowedAndATighteningCounts(): void
    {
        $registry = $this->registry([], ['usage' => ['commercial' => true], 'policy' => ['rateLimits' => [['max' => 50, 'window' => 'day', 'scope' => 'key', 'operations' => ['publish']]]]]);

        self::assertSame([100, 50], array_map(static fn ($l) => $l->max, $registry->policy('instagram')->limitsFor('publish')));
        self::assertSame(50, $registry->guard('instagram')->remaining('publish', ['key' => 'instagram']));
        self::assertSame(PublicationState::PENDING, $registry->publisher('instagram')->publish(self::picture())->state);
    }
}
