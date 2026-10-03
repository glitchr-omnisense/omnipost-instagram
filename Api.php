<?php

namespace Omnipost\Instagram;

use Omnipost\Exception\InvalidConfigException;
use Omnipost\Exception\ProviderException;
use Omnipost\Exception\UnauthorizedException;
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
 */
final class Api
{
    public const PROVIDER = 'instagram';
    public const BASE_URI = 'https://graph.instagram.com';
    public const VERSION = 'v23.0';

    /** Meta's codes for "too many calls": the application's, the user's, the page's, a custom limit. */
    public const RATE_LIMIT_CODES = [4, 17, 32, 613];

    private readonly HttpClientInterface $http;

    public function __construct(
        private ?string $accessToken = null,
        private readonly string $baseUri = self::BASE_URI,
        private readonly string $version = self::VERSION,
        ?HttpClientInterface $http = null,
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
        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $data = json_decode($response->getContent(false), true);
        } catch (HttpExceptionInterface $e) {
            // Not chained: the transport's message carries the URL, the token in it.
            throw new ProviderException(self::PROVIDER, 'Instagram could not be reached: '.$this->redact($e->getMessage()));
        }
        $data = \is_array($data) ? $data : [];
        if ($status >= 400 || isset($data['error']) || isset($data['error_type'])) {
            throw $this->error($status, $data);
        }

        return $data;
    }

    public function url(string $path, bool $versioned = true): string
    {
        return rtrim($this->baseUri, '/').($versioned && '' !== $this->version ? '/'.trim($this->version, '/') : '').'/'.ltrim($path, '/');
    }

    private function token(): string
    {
        return $this->accessToken ?: throw new InvalidConfigException('The "instagram" provider needs: access_token.');
    }

    /** @param array<string, mixed> $data */
    private function error(int $status, array $data): ProviderException
    {
        // The Graph API: {error: {message, type, code, error_subcode}}; the OAuth endpoint: {error_type, code, error_message}.
        $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = $this->redact((string) ($error['message'] ?? $data['error_message'] ?? \sprintf('HTTP %d.', $status)));
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $providerCode = null === $code ? null : (string) $code;

        if (401 === $status || 190 === $code || \in_array($subcode, [463, 467], true)) {
            return new UnauthorizedException(self::PROVIDER, $message, $status, $providerCode);
        }
        if (\in_array($code, self::RATE_LIMIT_CODES, true)) {
            return new ProviderException(self::PROVIDER, 'Too many calls, try again later: '.$message, $status, $providerCode);
        }

        return new ProviderException(self::PROVIDER, $message, $status, $providerCode);
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
