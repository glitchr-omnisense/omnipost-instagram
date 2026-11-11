<?php

namespace Omnipost\Instagram;

use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\RateLimitedException;
use Omnipost\Exception\UnauthorizedException;
use Omnipost\Http\Redaction;
use Omnipost\Http\Throttle;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The Instagram API with Instagram Login (graph.instagram.com), thin: GET
 * and POST with the account's token, and Meta's errors as Omnipost's - a
 * dead token (code 190, subcodes 463 and 467, HTTP 401) is an
 * UnauthorizedException, anything else a ProviderException with Meta's code
 * (4, 17, 32, 613: the rate limits). Through the application's HTTP client
 * when one is given: the profiler sees the calls, the tests mock them.
 *
 * The token travels in the query, as Meta wants it: it is struck out of
 * every message an exception carries.
 *
 * Given the provider's Backoff (the factory's Config::backoff()), a call is
 * refused while a wait lasts (UsageLimitReachedException, nothing sent); a
 * rate-limit answer is a RateLimitedException (a ProviderException), and
 * the wait Meta estimates (X-Business-Use-Case-Usage's
 * estimated_time_to_regain_access, in minutes) or a Retry-After holds the
 * next calls. Every call is sent once: nothing here retries, so a
 * container or a media_publish is never sent twice by omnipost.
 */
final class Api
{
    public const PROVIDER = 'instagram';
    public const BASE_URI = 'https://graph.instagram.com';
    public const VERSION = 'v23.0';

    /** Meta's codes for "too many calls": the application's, the user's, the page's, a custom limit, Instagram's business use case. */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613, 80002];

    private readonly HttpClientInterface $http;

    public function __construct(
        private ?string $accessToken = null,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $version = self::VERSION,
        ?HttpClientInterface $http = null,
        private readonly ?Backoff $backoff = null,
    ) {
        $this->http = $http ?? HttpClient::create();
    }

    public function accessToken(): ?string
    {
        return $this->accessToken;
    }

    /** The token every call from now on goes out with (a refreshed one, the one just exchanged). */
    public function setAccessToken(string $accessToken): void
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @param array<string, scalar|null> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = [], bool $versioned = true): array
    {
        return $this->call('GET', $this->url($path, $versioned), ['query' => self::filled($query) + ['access_token' => $this->token()]]);
    }

    /**
     * @param array<string, scalar|null> $fields
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $fields = []): array
    {
        return $this->call('POST', $this->url($path), ['body' => self::filled($fields) + ['access_token' => $this->token()]]);
    }

    /**
     * A call as it is, no token added: the OAuth endpoints.
     *
     * @param array<string, mixed> $options the HTTP client's
     *
     * @return array<string, mixed>
     */
    public function call(string $method, string $url, array $options = []): array
    {
        Throttle::before($this->backoff);
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            // Not chained: the transport's message carries the URL, the token in it.
            // The token, in the URL or the body, masked in the message and the previous, a copy; no trace keeps the options.
            $secrets = Redaction::secrets($url, $options);

            throw Redaction::withoutArguments(new ProviderException(self::PROVIDER, 'Instagram could not be reached: '.Redaction::text($e->getMessage(), $secrets), null, null, Redaction::copy($e, $secrets)));
        }
        $wait = Throttle::after($this->backoff, $status, $headers);
        $data = Data::map($data);
        if ($status >= 400 || isset($data['error']) || isset($data['error_type'])) {
            throw Redaction::withoutArguments($this->error($status, $data, $headers, $wait));
        }

        return $data;
    }

    public function url(string $path, bool $versioned = true): string
    {
        return rtrim($this->baseUri, '/').($versioned && '' !== $this->version ? '/'.trim($this->version, '/') : '').'/'.ltrim($path, '/');
    }

    private function token(): string
    {
        return $this->accessToken ?: throw Redaction::withoutArguments(new InvalidConfigException('The "instagram" provider needs: access_token.'));
    }

    /**
     * @param array<string, mixed>        $data
     * @param array<string, list<string>> $headers
     */
    private function error(int $status, array $data, array $headers = [], ?int $wait = null): ProviderException
    {
        // The Graph API: {error: {message, type, code, error_subcode}}; the OAuth endpoint: {error_type, code, error_message}.
        $error = Data::map($data['error'] ?? null);
        $message = $this->redact(Data::cast($error['message'] ?? $data['error_message'] ?? \sprintf('HTTP %d.', $status)));
        $code = isset($error['code']) ? Data::integer($error['code']) : null;
        $subcode = isset($error['error_subcode']) ? Data::integer($error['error_subcode']) : null;
        $providerCode = null === $code ? null : (string) $code;

        if (401 === $status || 190 === $code || \in_array($subcode, [463, 467], true)) {
            return new UnauthorizedException(self::PROVIDER, $message, $status, $providerCode);
        }
        if (429 === $status || \in_array($code, self::RATE_LIMIT_CODES, true)) {
            $regain = self::regain($headers);
            if (null !== $regain) {
                Throttle::hold($this->backoff, $regain, 'Instagram asked to wait');
            }

            return new RateLimitedException(self::PROVIDER, 'Too many calls, try again later: '.$message, $status, $providerCode, $regain ?? $wait);
        }

        return new ProviderException(self::PROVIDER, $message, $status, $providerCode);
    }

    /**
     * The seconds Meta estimates before calls are no longer throttled: the
     * longest estimated_time_to_regain_access (minutes) among the business
     * use cases of X-Business-Use-Case-Usage; null when it gives none.
     *
     * @param array<string, list<string>> $headers
     */
    public static function regain(array $headers): ?int
    {
        $minutes = null;
        foreach ($headers['x-business-use-case-usage'] ?? [] as $value) {
            $usage = json_decode($value, true);
            foreach (\is_array($usage) ? $usage : [] as $cases) {
                foreach (\is_array($cases) ? $cases : [] as $case) {
                    if (\is_array($case) && is_numeric($case['estimated_time_to_regain_access'] ?? null)) {
                        $minutes = max($minutes ?? 0, (int) $case['estimated_time_to_regain_access']);
                    }
                }
            }
        }

        return null !== $minutes && $minutes > 0 ? $minutes * 60 : null;
    }

    private function redact(string $message): string
    {
        return (string) preg_replace('/\b(access_token|client_secret|code)=[^&\s"\']+/', '$1=***', $message);
    }

    /**
     * @param array<string, scalar|null> $values
     *
     * @return array<string, scalar>
     */
    private static function filled(array $values): array
    {
        return array_map(static fn ($v) => \is_bool($v) ? ($v ? 'true' : 'false') : $v, array_filter($values, static fn ($v) => null !== $v && '' !== $v));
    }
}
