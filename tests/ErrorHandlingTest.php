<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Tests;

use Elvesora\Allocora\Exception\ApiException;
use Elvesora\Allocora\Exception\AuthenticationException;
use Elvesora\Allocora\Exception\AuthorizationException;
use Elvesora\Allocora\Exception\InvalidResponseException;
use Elvesora\Allocora\Exception\NotFoundException;
use Elvesora\Allocora\Exception\RateLimitException;
use Elvesora\Allocora\Exception\ServerException;
use Elvesora\Allocora\Exception\TransportException;
use Elvesora\Allocora\Exception\ValidationException;
use Elvesora\Allocora\Internal\Transport;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class ErrorHandlingTest extends TestCase
{
    /**
     * @param  class-string<ApiException>  $expectedClass
     *
     * @throws JsonException
     */
    #[DataProvider('httpErrorCases')]
    public function test_http_errors_are_typed_bodyless_and_never_retried(
        int $status,
        string $expectedClass,
        string $errorType,
        bool $retryable,
    ): void {
        $history = [];
        $responseBody = [
            'message' => 'Server echoed Bearer '.self::API_KEY,
            'api_key' => self::API_KEY,
            'errors' => [
                'name' => ['A useful validation message.'],
                'authorization' => self::API_KEY,
            ],
            'trace' => '/example/not-a-real-server-path/application.php:99',
        ];
        $client = $this->client([
            new Response(
                $status,
                [
                    'Content-Type' => 'application/json',
                    'Retry-After' => '17',
                ],
                json_encode($responseBody, JSON_THROW_ON_ERROR),
            ),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a typed API exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame($status, $exception->statusCode());
            self::assertSame($errorType, $exception->errorType());
            self::assertSame($retryable, $exception->retryable());
            self::assertSame(17, $exception->retryAfter());
            self::assertStringNotContainsString(self::API_KEY, $exception->getMessage());
            self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
            self::assertSame([], $exception->responseBody());
            self::assertCount(1, $history);
        }
    }

    public function test_validation_exception_discards_untrusted_field_errors(): void
    {
        $history = [];
        $client = $this->client([
            new Response(422, ['Content-Type' => 'application/json'], json_encode([
                'message' => 'The given data was invalid.',
                'errors' => ['name' => ['The name field is required.']],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        try {
            $client->payees()->create([]);
            self::fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            self::assertSame([], $exception->responseBody());
            self::assertSame([], $exception->errors());
        }
    }

    #[DataProvider('sensitiveUpstreamErrorCases')]
    public function test_upstream_error_details_never_escape_exception_surfaces(
        int $status,
        string $upstreamField,
        string $fixture,
    ): void {
        [$upstreamDetails, $sensitiveFragments] = self::sensitiveUpstreamErrorFixture($fixture);
        $previousSetting = ini_get('zend.exception_ignore_args');
        self::assertIsString($previousSetting);
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));

        try {
            $history = [];
            $client = $this->client([
                new Response($status, ['Content-Type' => 'application/json'], json_encode([
                    $upstreamField => $upstreamDetails,
                    'code' => 'DATABASE'.'_PASSWORD_EXPOSED',
                    'error_code' => 'AWS'.'_SECRET_ACCESS_KEY',
                    'errors' => [
                        'name' => [$upstreamDetails],
                    ],
                ], JSON_THROW_ON_ERROR)),
            ], $history);

            try {
                $client->payees()->list();
                self::fail('Expected a typed API exception.');
            } catch (ApiException $exception) {
                $surfaces = [
                    $exception->getMessage(),
                    (string) $exception,
                    print_r($exception->responseBody(), true),
                    var_export($exception->responseBody(), true),
                    print_r($exception, true),
                    var_export($exception, true),
                    var_export($exception->__debugInfo(), true),
                    print_r($exception->getTrace(), true),
                ];

                try {
                    $surfaces[] = serialize($exception);
                } catch (Throwable $serializationFailure) {
                    $surfaces[] = (string) $serializationFailure;
                }

                self::assertSame([], $exception->responseBody());
                if ($exception instanceof ValidationException) {
                    self::assertSame([], $exception->errors());
                }

                foreach ($surfaces as $surface) {
                    self::assertStringNotContainsString($upstreamDetails, $surface);
                    self::assertStringNotContainsString('DATABASE'.'_PASSWORD_EXPOSED', $surface);
                    self::assertStringNotContainsString('AWS'.'_SECRET_ACCESS_KEY', $surface);

                    foreach ($sensitiveFragments as $sensitiveFragment) {
                        self::assertStringNotContainsString($sensitiveFragment, $surface);
                    }
                }

                self::assertCount(1, $history);
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }

    public function test_transport_failures_discard_the_sensitive_original_exception(): void
    {
        $history = [];
        $request = new Request('GET', self::BASE_URL.'/payees', [
            'Authorization' => 'Bearer '.self::API_KEY,
        ]);
        $client = $this->client([
            new ConnectException('Connection failed with Bearer '.self::API_KEY, $request),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a transport exception.');
        } catch (TransportException $exception) {
            self::assertTrue($exception->retryable());
            self::assertSame('transport_error', $exception->errorType());
            self::assertNull($exception->getPrevious());
            self::assertStringNotContainsString(self::API_KEY, (string) $exception);
            self::assertStringNotContainsString(self::API_KEY, print_r($exception, true));
            self::assertCount(1, $history);
        }
    }

    /**
     * @param  class-string<ApiException>  $expectedClass
     */
    #[DataProvider('invalidErrorResponseCases')]
    public function test_invalid_error_bodies_preserve_status_typed_exceptions(
        int $status,
        string $contentType,
        string $body,
        string $expectedClass,
    ): void {
        $history = [];
        $client = $this->client([
            new Response($status, ['Content-Type' => $contentType], $body),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a status-typed API exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame($status, $exception->statusCode());
            self::assertSame([], $exception->responseBody());
            self::assertStringNotContainsString(self::API_KEY, (string) $exception);
            self::assertCount(1, $history);
        }
    }

    #[DataProvider('invalidResponseCases')]
    public function test_invalid_responses_fail_closed(Response $response): void
    {
        $history = [];
        $client = $this->client([$response], $history);

        $this->expectException(InvalidResponseException::class);
        $client->payees()->list();
    }

    public function test_invalid_response_traces_never_retain_raw_response_content(): void
    {
        $secret = 'fictional_malformed_response_secret_not_a_credential';
        $previousSetting = ini_get('zend.exception_ignore_args');
        self::assertIsString($previousSetting);
        self::assertNotFalse(ini_set('zend.exception_ignore_args', '0'));

        try {
            $history = [];
            $client = $this->client([
                new Response(
                    200,
                    ['Content-Type' => 'application/json'],
                    '{"data":"'.$secret,
                ),
            ], $history);

            try {
                $client->payees()->list();
                self::fail('Expected an invalid-response exception.');
            } catch (InvalidResponseException $exception) {
                $diagnosticViews = [
                    print_r($exception->getTrace(), true),
                    var_export($exception->getTrace(), true),
                    print_r($exception, true),
                    var_export($exception, true),
                ];

                foreach ($diagnosticViews as $diagnosticView) {
                    self::assertStringNotContainsString($secret, $diagnosticView);
                }
            }
        } finally {
            ini_set('zend.exception_ignore_args', $previousSetting);
        }
    }

    public function test_declared_oversized_response_is_rejected_before_body_parsing(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json',
                'Content-Length' => (string) (Transport::MAX_RESPONSE_BYTES + 1),
            ], '{}'),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected an oversized response to fail.');
        } catch (InvalidResponseException $exception) {
            self::assertStringContainsString((string) Transport::MAX_RESPONSE_BYTES, $exception->getMessage());
        }
    }

    /**
     * @param  class-string<AuthenticationException>  $expectedClass
     */
    #[DataProvider('oversizedErrorResponseCases')]
    public function test_oversized_error_bodies_preserve_status_typed_exceptions(
        bool $declareOversizedLength,
        string $expectedClass,
    ): void {
        $history = [];
        $headers = ['Content-Type' => 'application/json'];
        $body = str_repeat('x', Transport::MAX_RESPONSE_BYTES + 1);

        if ($declareOversizedLength) {
            $headers['Content-Length'] = (string) (Transport::MAX_RESPONSE_BYTES + 1);
            $body = '{"message":"fictional oversized error"}';
        }

        $client = $this->client([
            new Response(401, $headers, $body),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a status-typed authentication exception.');
        } catch (ApiException $exception) {
            self::assertInstanceOf($expectedClass, $exception);
            self::assertSame(401, $exception->statusCode());
            self::assertSame([], $exception->responseBody());
            self::assertFalse($exception->retryable());
            self::assertCount(1, $history);
        }
    }

    public function test_empty_json_object_is_accepted_but_json_lists_are_not(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], " {\n} "),
        ], $history);

        $response = $client->payees()->list();

        self::assertSame([], $response->body());
        self::assertNull($response->data());
        self::assertNull($response->meta());
    }

    #[DataProvider('oversizedRetryAfterValues')]
    public function test_retry_after_metadata_is_bounded_to_seven_days(string $retryAfter): void
    {
        $history = [];
        $client = $this->client([
            new Response(429, [
                'Content-Type' => 'application/json',
                'Retry-After' => $retryAfter,
            ], '{}'),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a rate-limit exception.');
        } catch (RateLimitException $exception) {
            self::assertSame(Transport::MAX_RETRY_AFTER_SECONDS, $exception->retryAfter());
        }
    }

    public function test_safe_retry_after_body_metadata_is_retained_without_retaining_the_body(): void
    {
        $history = [];
        $client = $this->client([
            new Response(429, ['Content-Type' => 'application/json'], json_encode([
                'retry_after' => 29,
                'message' => 'Server detail that must not be retained.',
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        try {
            $client->payees()->list();
            self::fail('Expected a rate-limit exception.');
        } catch (RateLimitException $exception) {
            self::assertSame(29, $exception->retryAfter());
            self::assertSame([], $exception->responseBody());
            self::assertStringNotContainsString('Server detail that must not be retained.', print_r($exception, true));
            self::assertCount(1, $history);
        }
    }

    #[DataProvider('oversizedRetryAfterMetadata')]
    public function test_oversized_retry_after_strings_are_discarded_before_parsing(bool $fromHeader): void
    {
        $oversized = str_repeat('9', Transport::MAX_RETRY_AFTER_BYTES + 1);
        $headers = ['Content-Type' => 'application/json'];
        $body = [];

        if ($fromHeader) {
            $headers['Retry-After'] = $oversized;
        } else {
            $body['retry_after'] = $oversized;
        }

        $client = $this->client([
            new Response(429, $headers, json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR)),
        ]);

        try {
            $client->payees()->list();
            self::fail('Expected a rate-limit exception.');
        } catch (RateLimitException $exception) {
            self::assertNull($exception->retryAfter());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function oversizedRetryAfterValues(): iterable
    {
        yield 'numeric seconds' => ['999999999'];
        yield 'HTTP date' => [gmdate('D, d M Y H:i:s \\G\\M\\T', time() + 999_999_999)];
    }

    /** @return iterable<string, array{bool}> */
    public static function oversizedRetryAfterMetadata(): iterable
    {
        yield 'header' => [true];
        yield 'body' => [false];
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function sensitiveUpstreamErrorCases(): iterable
    {
        yield 'validation environment disclosure' => [
            422,
            'message',
            'environment',
        ];
        yield 'server SQL disclosure' => [
            500,
            'error',
            'sql',
        ];
        yield 'server Windows path disclosure' => [
            503,
            'message',
            'windows_path',
        ];
        yield 'unknown status secret disclosure' => [
            418,
            'error',
            'provider_secret',
        ];
    }

    /**
     * @return array{string, list<string>}
     */
    private static function sensitiveUpstreamErrorFixture(string $fixture): array
    {
        $unixPath = '/example/'.'not-a-real-server-path/example.env';
        $windowsPath = 'C:\\example\\'.'not-a-real-server-path\\Service.php';
        $fakeSecret = 'example-not-a-real-secret';

        return match ($fixture) {
            'environment' => [
                'Validation failed while reading '.$unixPath.': DB'.'_PASSWORD='.$fakeSecret,
                [$unixPath, 'DB'.'_PASSWORD', $fakeSecret],
            ],
            'sql' => [
                'SQL'.'STATE[HY000]: password='.$fakeSecret.' at '.$unixPath,
                ['SQL'.'STATE[HY000]', 'password='.$fakeSecret, $unixPath],
            ],
            'windows_path' => [
                'Unhandled exception at '.$windowsPath.':44; APP'.'_KEY='.$fakeSecret,
                [$windowsPath, 'APP'.'_KEY', $fakeSecret],
            ],
            'provider_secret' => [
                'AWS'.'_SECRET_ACCESS_KEY='.$fakeSecret.' at '.$unixPath,
                ['AWS'.'_SECRET_ACCESS_KEY', $fakeSecret, $unixPath],
            ],
            default => self::fail('Unknown sensitive upstream error fixture.'),
        };
    }

    /**
     * @return iterable<string, array{bool, class-string<AuthenticationException>}>
     */
    public static function oversizedErrorResponseCases(): iterable
    {
        yield 'declared size' => [true, AuthenticationException::class];
        yield 'measured stream size' => [false, AuthenticationException::class];
    }

    /**
     * @return iterable<string, array{int, string, string, class-string<ApiException>}>
     */
    public static function invalidErrorResponseCases(): iterable
    {
        yield '401 text body' => [
            401,
            'text/html',
            '<p>Bearer '.self::API_KEY.'</p>',
            AuthenticationException::class,
        ];
        yield '500 malformed JSON body' => [
            500,
            'application/json',
            '{"message":"Bearer '.self::API_KEY,
            ServerException::class,
        ];
    }

    /**
     * @return iterable<string, array{int, class-string<ApiException>, string, bool}>
     */
    public static function httpErrorCases(): iterable
    {
        yield '401' => [401, AuthenticationException::class, 'authentication_error', false];
        yield '403' => [403, AuthorizationException::class, 'authorization_error', false];
        yield '404' => [404, NotFoundException::class, 'not_found', false];
        yield '409' => [409, ApiException::class, 'api_error', false];
        yield '422' => [422, ValidationException::class, 'validation_error', false];
        yield '429' => [429, RateLimitException::class, 'rate_limit_error', true];
        yield '500' => [500, ServerException::class, 'server_error', true];
        yield '503' => [503, ServerException::class, 'server_error', true];
        yield '509' => [509, ServerException::class, 'server_error', true];
        yield '512' => [512, ServerException::class, 'server_error', true];
        yield '599' => [599, ServerException::class, 'server_error', true];
    }

    /**
     * @return iterable<string, array{Response}>
     */
    public static function invalidResponseCases(): iterable
    {
        yield 'missing content type' => [new Response(200, [], '{}')];
        yield 'non-json content type' => [new Response(200, ['Content-Type' => 'text/html'], '{}')];
        yield 'malformed json' => [new Response(200, ['Content-Type' => 'application/json'], '{bad')];
        yield 'json list' => [new Response(200, ['Content-Type' => 'application/json'], '[]')];
        yield 'json scalar' => [new Response(200, ['Content-Type' => 'application/json'], 'true')];
    }
}
