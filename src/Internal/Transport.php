<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Internal;

use Closure;
use Elvesora\Allocora\Exception\ApiException;
use Elvesora\Allocora\Exception\AuthenticationException;
use Elvesora\Allocora\Exception\AuthorizationException;
use Elvesora\Allocora\Exception\InvalidResponseException;
use Elvesora\Allocora\Exception\NotFoundException;
use Elvesora\Allocora\Exception\RateLimitException;
use Elvesora\Allocora\Exception\ServerException;
use Elvesora\Allocora\Exception\TransportException;
use Elvesora\Allocora\Exception\ValidationException;
use Elvesora\Allocora\Response\ApiResponse;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use SensitiveParameterValue;
use Throwable;

/** @internal */
final readonly class Transport
{
    public const MAX_REQUEST_BYTES = 2_097_152;

    public const MAX_RESPONSE_BYTES = 10_485_760;

    public const MAX_QUERY_BYTES = 8_192;

    public const MAX_RETRY_AFTER_SECONDS = 604_800;

    public const MAX_RETRY_AFTER_BYTES = 128;

    public const MAX_RESPONSE_HEADER_COUNT = 100;

    public const MAX_RESPONSE_HEADER_VALUE_COUNT = 200;

    public const MAX_RESPONSE_HEADER_NAME_BYTES = 128;

    public const MAX_RESPONSE_HEADER_VALUE_BYTES = 8_192;

    public const MAX_RESPONSE_HEADERS_BYTES = 32_768;

    private const UUID_PATTERN = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /** @var list<string> */
    private const RESERVED_HEADERS = [
        'authorization',
        'connection',
        'content-length',
        'content-type',
        'cookie',
        'expect',
        'host',
        'keep-alive',
        'proxy-authenticate',
        'proxy-authorization',
        'proxy-connection',
        'te',
        'trailer',
        'transfer-encoding',
        'upgrade',
        'user-agent',
    ];

    private SensitiveParameterValue $apiKey;

    private SensitiveParameterValue $baseUrl;

    private SensitiveParameterValue $defaultHeaders;

    private SensitiveParameterValue $httpClient;

    private SensitiveParameterValue $clock;

    /**
     * @param  array<array-key, mixed>  $defaultHeaders
     * @param  (Closure(): float)|null  $clock
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter]
        ClientInterface $httpClient,
        #[\SensitiveParameter]
        string $baseUrl,
        private float $timeout,
        private float $connectTimeout,
        #[\SensitiveParameter]
        array $defaultHeaders = [],
        #[\SensitiveParameter]
        ?Closure $clock = null,
    ) {
        $this->assertApiKey($apiKey);
        $this->apiKey = new SensitiveParameterValue($apiKey);
        $this->assertBaseUrl($baseUrl);
        $this->assertTimeout($timeout, 'request');
        $this->assertTimeout($connectTimeout, 'connection');

        if ($connectTimeout > $timeout) {
            throw new InvalidArgumentException('The connection timeout cannot exceed the request timeout.');
        }

        $this->baseUrl = new SensitiveParameterValue($baseUrl);
        $this->defaultHeaders = new SensitiveParameterValue($this->normalizeDefaultHeaders($defaultHeaders));
        $this->httpClient = new SensitiveParameterValue($httpClient);
        $this->clock = new SensitiveParameterValue(
            $clock ?? static fn (): float => (float) hrtime(true) / 1_000_000_000,
        );
    }

    public function baseUrl(): string
    {
        return $this->protectedBaseUrl();
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    public function connectTimeout(): float
    {
        return $this->connectTimeout;
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @param  array<string, mixed>|null  $payload
     */
    public function request(
        string $method,
        #[\SensitiveParameter]
        string $path,
        #[\SensitiveParameter]
        array $query = [],
        #[\SensitiveParameter]
        ?array $payload = null,
    ): ApiResponse {
        $deadline = $this->monotonicNow() + $this->timeout;
        $this->assertRequestTarget($method, $path);
        $query = $this->normalizeQuery($query);

        $headers = $this->protectedDefaultHeaders();
        $headers['Accept'] = 'application/json';
        $headers['Authorization'] = 'Bearer '.$this->apiKey();
        $headers['User-Agent'] = 'elvesora-allocora-php/0.1.0';

        $encodedPayload = null;
        if ($payload !== null) {
            $encodedPayload = $this->encodePayload($payload);
            $headers['Content-Type'] = 'application/json';
        }

        $remainingTimeout = $this->remainingTime($deadline);

        /** @var array<string, mixed> $options */
        $options = [
            'allow_redirects' => false,
            'connect_timeout' => min($this->connectTimeout, $remainingTimeout),
            'headers' => $headers,
            'http_errors' => false,
            'on_headers' => function (#[\SensitiveParameter] ResponseInterface $response) use ($deadline): void {
                $this->assertWithinDeadline($deadline);
            },
            'progress' => function (
                float $downloadTotal,
                float $downloadedBytes,
                float $uploadTotal,
                float $uploadedBytes,
            ) use ($deadline): void {
                $this->assertWithinDeadline($deadline);
            },
            'stream' => true,
            'timeout' => $remainingTimeout,
            'verify' => true,
        ];

        if ($query !== []) {
            $options['query'] = $query;
        }

        if ($encodedPayload !== null) {
            $options['body'] = $encodedPayload;
        }

        try {
            $this->assertWithinDeadline($deadline);
            $response = $this->protectedHttpClient()->request($method, $this->protectedBaseUrl().$path, $options);
            $this->assertWithinDeadline($deadline);
        } catch (TransportException $exception) {
            throw $exception;
        } catch (Throwable) {
            if ($this->deadlineReached($deadline)) {
                throw $this->deadlineExceeded();
            }

            throw new TransportException(
                message: 'The Allocora API request could not be completed.',
                errorType: 'transport_error',
                retryable: true,
            );
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            $responseHeaders = $this->normalizeResponseHeaders($response, $deadline);
            $body = $this->decodeResponse($response, $deadline);
            $this->assertWithinDeadline($deadline);

            return new ApiResponse($status, $body, $responseHeaders);
        }

        $body = $this->decodeErrorResponse($response, $deadline);
        $this->throwForErrorResponse($response, $body, $deadline);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'base_url' => '[REDACTED]',
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connectTimeout,
            'default_header_count' => count($this->protectedDefaultHeaders()),
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Allocora clients cannot be serialized.');
    }

    private function assertApiKey(#[\SensitiveParameter] string $apiKey): void
    {
        if (preg_match('/^[\x21-\x7E]{8,4096}$/D', $apiKey) !== 1) {
            throw new InvalidArgumentException('The Allocora API key must be 8 to 4096 printable ASCII characters without whitespace.');
        }
    }

    private function apiKey(): string
    {
        $apiKey = $this->apiKey->getValue();

        if (! is_string($apiKey)) {
            throw new LogicException('The Allocora API credential holder is invalid.');
        }

        return $apiKey;
    }

    private function protectedBaseUrl(): string
    {
        $baseUrl = $this->baseUrl->getValue();

        if (! is_string($baseUrl)) {
            throw new LogicException('The Allocora base URL holder is invalid.');
        }

        return $baseUrl;
    }

    /** @return array<string, list<string>> */
    private function protectedDefaultHeaders(): array
    {
        $headers = $this->defaultHeaders->getValue();

        if (! is_array($headers)) {
            throw new LogicException('The Allocora default header holder is invalid.');
        }

        /** @var array<string, list<string>> $headers */
        return $headers;
    }

    private function protectedHttpClient(): ClientInterface
    {
        $httpClient = $this->httpClient->getValue();

        if (! $httpClient instanceof ClientInterface) {
            throw new LogicException('The Allocora HTTP client holder is invalid.');
        }

        return $httpClient;
    }

    private function assertBaseUrl(#[\SensitiveParameter] string $baseUrl): void
    {
        if ($baseUrl !== trim($baseUrl) || str_ends_with($baseUrl, '/')) {
            throw new InvalidArgumentException('The Allocora base URL cannot contain surrounding whitespace or a trailing slash.');
        }

        $parts = parse_url($baseUrl);
        $path = is_array($parts) && is_string($parts['path'] ?? null) ? rtrim($parts['path'], '/') : '';

        if (
            filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || $parts['host'] === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || ! str_ends_with($path, '/api/v1')
        ) {
            throw new InvalidArgumentException('The Allocora base URL must be an HTTPS URL ending in /api/v1 without credentials, a query string, or a fragment.');
        }
    }

    private function assertTimeout(float $timeout, string $label): void
    {
        if (! is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new InvalidArgumentException(sprintf('The %s timeout must be greater than zero and no more than 300 seconds.', $label));
        }
    }

    private function monotonicNow(): float
    {
        $clock = $this->clock->getValue();

        if (! $clock instanceof Closure) {
            throw new LogicException('The Allocora monotonic clock holder is invalid.');
        }

        $now = $clock();

        if (! is_float($now)) {
            throw new LogicException('The Allocora monotonic clock returned an invalid value.');
        }

        return $now;
    }

    private function remainingTime(float $deadline): float
    {
        $remaining = $deadline - $this->monotonicNow();

        if (! is_finite($remaining) || $remaining <= 0) {
            throw $this->deadlineExceeded();
        }

        return min($this->timeout, $remaining);
    }

    private function deadlineReached(float $deadline): bool
    {
        $now = $this->monotonicNow();

        return ! is_finite($now) || $now >= $deadline;
    }

    private function assertWithinDeadline(float $deadline): void
    {
        if ($this->deadlineReached($deadline)) {
            throw $this->deadlineExceeded();
        }
    }

    private function deadlineExceeded(): TransportException
    {
        return new TransportException(
            message: 'The Allocora API request exceeded the configured timeout.',
            errorType: 'transport_timeout',
            retryable: true,
        );
    }

    /**
     * @param  array<array-key, mixed>  $headers
     * @return array<string, list<string>>
     */
    private function normalizeDefaultHeaders(#[\SensitiveParameter] array $headers): array
    {
        $totalBytes = 0;
        $normalized = [];

        foreach ($headers as $name => $values) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Custom header names must be strings.');
            }

            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]{1,128}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Custom header names must use valid HTTP token characters.');
            }

            if (in_array(strtolower($name), self::RESERVED_HEADERS, true)) {
                throw new InvalidArgumentException(sprintf('The custom header "%s" is reserved by the Allocora client.', $name));
            }

            if (! is_string($values) && (! is_array($values) || ! array_is_list($values))) {
                throw new InvalidArgumentException(sprintf('The custom header "%s" must be a string or a list of strings.', $name));
            }

            $headerValues = is_string($values) ? [$values] : $values;
            if ($headerValues === []) {
                throw new InvalidArgumentException(sprintf('The custom header "%s" must have at least one value.', $name));
            }

            $normalizedValues = [];
            foreach ($headerValues as $value) {
                if (! is_string($value)) {
                    throw new InvalidArgumentException(sprintf('The custom header "%s" must contain only string values.', $name));
                }

                if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1 || mb_strlen($value, 'UTF-8') > 8_192) {
                    throw new InvalidArgumentException(sprintf('The custom header "%s" contains an invalid or oversized value.', $name));
                }

                $totalBytes += strlen($name) + strlen($value);
                $normalizedValues[] = $value;
            }

            $normalized[$name] = $normalizedValues;
        }

        if ($totalBytes > 32_768) {
            throw new InvalidArgumentException('Custom headers cannot exceed 32768 bytes in total.');
        }

        return $normalized;
    }

    private function assertRequestTarget(string $method, #[\SensitiveParameter] string $path): void
    {
        $uuid = self::UUID_PATTERN;
        $patterns = match ($method) {
            'GET' => [
                '#^/(?:payees|products|revenues|rules)$#D',
                '#^/(?:payees|products|revenues|rules)/'.$uuid.'$#D',
                '#^/rules/'.$uuid.'/versions$#D',
            ],
            'POST' => [
                '#^/(?:payees|products|revenues|rules)$#D',
                '#^/(?:payees|products|revenues|rules)/batch$#D',
                '#^/(?:payees|products|rules)/upsert$#D',
                '#^/(?:payees|products|rules)/batch/upsert$#D',
                '#^/rules/'.$uuid.'/toggle-status$#D',
            ],
            'PUT' => [
                '#^/(?:payees|products|rules)/batch$#D',
                '#^/(?:payees|products|rules)/'.$uuid.'$#D',
            ],
            'DELETE' => [
                '#^/products/batch$#D',
                '#^/products/'.$uuid.'$#D',
            ],
            default => [],
        };

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return;
            }
        }

        throw new LogicException('The Allocora client attempted an unsupported API operation.');
    }

    /**
     * @param  array<array-key, mixed>  $query
     * @return array<string, scalar|list<scalar|null>|null>
     */
    private function normalizeQuery(#[\SensitiveParameter] array $query): array
    {
        $normalized = [];

        foreach ($query as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('Query parameter names must be strings.');
            }

            if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,63}$/D', $name) !== 1) {
                throw new InvalidArgumentException('Query parameter names must start with a letter and contain only letters, numbers, dots, underscores, or hyphens.');
            }

            if (is_array($value)) {
                if (! array_is_list($value)) {
                    throw new InvalidArgumentException(sprintf('The query parameter "%s" must be a scalar or a list of scalars.', $name));
                }

                $normalizedItems = [];
                foreach ($value as $item) {
                    if ($item !== null && ! is_scalar($item)) {
                        throw new InvalidArgumentException(sprintf('The query parameter "%s" contains an unsupported value.', $name));
                    }

                    $normalizedItems[] = $item;
                }

                $normalized[$name] = $normalizedItems;
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('The query parameter "%s" must be a scalar or a list of scalars.', $name));
            } else {
                $normalized[$name] = $value;
            }
        }

        if (strlen(http_build_query($normalized, '', '&', PHP_QUERY_RFC3986)) > self::MAX_QUERY_BYTES) {
            throw new InvalidArgumentException(sprintf('The encoded query string cannot exceed %d bytes.', self::MAX_QUERY_BYTES));
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodePayload(#[\SensitiveParameter] array $payload): string
    {
        try {
            $encoded = json_encode(
                $payload === [] ? (object) [] : $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw new InvalidArgumentException('The request payload must contain JSON-encodable values.');
        }

        if (strlen($encoded) > self::MAX_REQUEST_BYTES) {
            throw new InvalidArgumentException(sprintf('The encoded request body cannot exceed %d bytes.', self::MAX_REQUEST_BYTES));
        }

        return $encoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(
        #[\SensitiveParameter] ResponseInterface $response,
        float $deadline,
    ): array {
        $this->assertWithinDeadline($deadline);
        $contentType = trim($response->getHeaderLine('Content-Type'));
        if ($contentType === '' || preg_match('#^application/(?:[A-Za-z0-9.+-]+\+)?json(?:\s*;|$)#i', $contentType) !== 1) {
            throw new InvalidResponseException(
                message: 'The Allocora API returned a response without a supported JSON content type.',
                statusCode: $response->getStatusCode(),
                errorType: 'invalid_response',
                retryable: $response->getStatusCode() >= 500,
            );
        }

        $this->assertWithinDeadline($deadline);
        $declaredLength = trim($response->getHeaderLine('Content-Length'));
        if (ctype_digit($declaredLength) && (int) $declaredLength > self::MAX_RESPONSE_BYTES) {
            throw $this->oversizedResponse($response->getStatusCode());
        }

        $this->assertWithinDeadline($deadline);
        $stream = $response->getBody();
        $streamSize = $stream->getSize();
        if ($streamSize !== null && $streamSize > self::MAX_RESPONSE_BYTES) {
            throw $this->oversizedResponse($response->getStatusCode());
        }

        try {
            if ($stream->isSeekable()) {
                $this->assertWithinDeadline($deadline);
                $stream->rewind();
                $this->assertWithinDeadline($deadline);
            }

            $contents = '';
            while (true) {
                $this->assertWithinDeadline($deadline);
                $isEndOfFile = $stream->eof();
                $this->assertWithinDeadline($deadline);

                if ($isEndOfFile) {
                    break;
                }

                $remaining = self::MAX_RESPONSE_BYTES + 1 - strlen($contents);
                if ($remaining <= 0) {
                    throw $this->oversizedResponse($response->getStatusCode());
                }

                $this->assertWithinDeadline($deadline);
                $chunk = $stream->read(min(8_192, $remaining));
                $this->assertWithinDeadline($deadline);
                if ($chunk === '') {
                    throw new RuntimeException('The response stream stopped before reaching EOF.');
                }

                $contents .= $chunk;
            }
        } catch (TransportException $exception) {
            throw $exception;
        } catch (InvalidResponseException $exception) {
            throw $exception;
        } catch (RuntimeException) {
            throw new InvalidResponseException(
                message: 'The Allocora API response body could not be read safely.',
                statusCode: $response->getStatusCode(),
                errorType: 'invalid_response',
                retryable: $response->getStatusCode() >= 500,
            );
        }

        if (strlen($contents) > self::MAX_RESPONSE_BYTES) {
            throw $this->oversizedResponse($response->getStatusCode());
        }

        $this->assertWithinDeadline($deadline);
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidResponseException(
                message: 'The Allocora API returned malformed JSON.',
                statusCode: $response->getStatusCode(),
                errorType: 'invalid_response',
                retryable: $response->getStatusCode() >= 500,
            );
        }
        $this->assertWithinDeadline($deadline);

        $trimmedContents = ltrim($contents);
        if (! is_array($decoded) || ! str_starts_with($trimmedContents, '{')) {
            throw new InvalidResponseException(
                message: 'The Allocora API response must be a JSON object.',
                statusCode: $response->getStatusCode(),
                errorType: 'invalid_response',
                retryable: $response->getStatusCode() >= 500,
            );
        }

        $body = [];
        foreach ($decoded as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidResponseException(
                    message: 'The Allocora API response contains an invalid object field name.',
                    statusCode: $response->getStatusCode(),
                    errorType: 'invalid_response',
                    retryable: $response->getStatusCode() >= 500,
                );
            }

            $body[$name] = $value;
        }

        $this->assertWithinDeadline($deadline);

        return $body;
    }

    /**
     * Error payloads are bounded and parsed only for safe derived metadata.
     * They are never retained on the exception, and invalid payloads are
     * discarded so the HTTP status remains the authoritative classification.
     *
     * @return array<string, mixed>
     */
    private function decodeErrorResponse(
        #[\SensitiveParameter] ResponseInterface $response,
        float $deadline,
    ): array {
        try {
            return $this->decodeResponse($response, $deadline);
        } catch (InvalidResponseException) {
            return [];
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function normalizeResponseHeaders(
        #[\SensitiveParameter] ResponseInterface $response,
        float $deadline,
    ): array {
        $headers = [];
        $headerCount = 0;
        $headerValueCount = 0;
        $totalBytes = 0;

        foreach ($response->getHeaders() as $name => $values) {
            $this->assertWithinDeadline($deadline);

            if (
                ! is_string($name)
                || strlen($name) > self::MAX_RESPONSE_HEADER_NAME_BYTES
                || preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/D', $name) !== 1
            ) {
                throw $this->invalidResponseHeaders($response->getStatusCode());
            }

            $headerCount++;
            $totalBytes += strlen($name);
            if ($headerCount > self::MAX_RESPONSE_HEADER_COUNT || $totalBytes > self::MAX_RESPONSE_HEADERS_BYTES) {
                throw $this->invalidResponseHeaders($response->getStatusCode());
            }

            $normalizedValues = [];
            foreach ($values as $value) {
                $this->assertWithinDeadline($deadline);

                if (
                    strlen($value) > self::MAX_RESPONSE_HEADER_VALUE_BYTES
                    || preg_match('/[\x00-\x08\x0A-\x1F\x7F]/', $value) === 1
                ) {
                    throw $this->invalidResponseHeaders($response->getStatusCode());
                }

                $headerValueCount++;
                $totalBytes += strlen($value);
                if (
                    $headerValueCount > self::MAX_RESPONSE_HEADER_VALUE_COUNT
                    || $totalBytes > self::MAX_RESPONSE_HEADERS_BYTES
                ) {
                    throw $this->invalidResponseHeaders($response->getStatusCode());
                }

                $normalizedValues[] = $value;
            }

            $headers[$name] = $normalizedValues;
        }

        $this->assertWithinDeadline($deadline);

        return $headers;
    }

    private function invalidResponseHeaders(int $statusCode): InvalidResponseException
    {
        return new InvalidResponseException(
            message: 'The Allocora API returned unsafe or oversized response headers.',
            statusCode: $statusCode,
            errorType: 'invalid_response',
            retryable: $statusCode >= 500,
        );
    }

    private function oversizedResponse(int $statusCode): InvalidResponseException
    {
        return new InvalidResponseException(
            message: sprintf('The Allocora API response exceeded the %d-byte safety limit.', self::MAX_RESPONSE_BYTES),
            statusCode: $statusCode,
            errorType: 'invalid_response',
            retryable: $statusCode >= 500,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function throwForErrorResponse(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] array $body,
        float $deadline,
    ): never {
        $this->assertWithinDeadline($deadline);
        $status = $response->getStatusCode();
        $retryAfter = $this->retryAfter($response, $body, $deadline);

        $exceptionClass = match (true) {
            $status === 401 => AuthenticationException::class,
            $status === 403 => AuthorizationException::class,
            $status === 404 => NotFoundException::class,
            $status === 422 => ValidationException::class,
            $status === 429 => RateLimitException::class,
            $status >= 500 && $status <= 599 => ServerException::class,
            default => ApiException::class,
        };

        $message = match ($status) {
            401 => 'Allocora API authentication failed.',
            403 => 'The API key does not have permission to perform this Allocora operation.',
            404 => 'The requested Allocora resource was not found.',
            422 => 'The Allocora API rejected the request data.',
            429 => 'The Allocora API rate limit was exceeded.',
            default => $status >= 500
                ? 'The Allocora API is temporarily unavailable.'
                : 'The Allocora API returned an error.',
        };

        $errorType = match ($status) {
            401 => 'authentication_error',
            403 => 'authorization_error',
            404 => 'not_found',
            422 => 'validation_error',
            429 => 'rate_limit_error',
            default => $status >= 500 ? 'server_error' : 'api_error',
        };

        throw new $exceptionClass(
            message: $message,
            statusCode: $status,
            errorType: $errorType,
            retryable: $status === 408 || $status === 429 || $status >= 500,
            retryAfter: $retryAfter,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function retryAfter(
        #[\SensitiveParameter] ResponseInterface $response,
        #[\SensitiveParameter] array $body,
        float $deadline,
    ): ?int {
        $this->assertWithinDeadline($deadline);
        $value = $body['retry_after'] ?? $response->getHeaderLine('Retry-After');
        $this->assertWithinDeadline($deadline);

        if (is_int($value) || is_float($value)) {
            return min(self::MAX_RETRY_AFTER_SECONDS, max(0, (int) $value));
        }

        if (! is_string($value) || strlen($value) > self::MAX_RETRY_AFTER_BYTES) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min(self::MAX_RETRY_AFTER_SECONDS, (int) $value);
        }

        if (is_numeric($value)) {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return min(self::MAX_RETRY_AFTER_SECONDS, max(0, $timestamp - time()));
        }

        return null;
    }
}
