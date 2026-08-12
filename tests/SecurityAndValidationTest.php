<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Tests;

use Elvesora\Allocora\Client;
use Elvesora\Allocora\Exception\InvalidResponseException;
use Elvesora\Allocora\Exception\TransportException;
use Elvesora\Allocora\Internal\Transport;
use Elvesora\Allocora\Resource\AbstractResource;
use Elvesora\Allocora\Resource\PayeesResource;
use Elvesora\Allocora\Resource\ProductsResource;
use Elvesora\Allocora\Resource\RevenuesResource;
use Elvesora\Allocora\Resource\RulesResource;
use Elvesora\Allocora\Response\ApiResponse;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

final class SecurityAndValidationTest extends TestCase
{
    #[DataProvider('invalidApiKeys')]
    public function test_api_key_validation_rejects_unsafe_header_values(string $apiKey): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client($apiKey);
    }

    #[DataProvider('invalidBaseUrls')]
    public function test_base_url_is_restricted_to_the_public_https_v1_boundary(string $baseUrl): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Client(self::API_KEY, baseUrl: $baseUrl);
    }

    public function test_timeouts_are_positive_bounded_and_ordered(): void
    {
        foreach ([
            static fn () => new Client(self::API_KEY, timeout: 0),
            static fn () => new Client(self::API_KEY, timeout: 301),
            static fn () => new Client(self::API_KEY, connectTimeout: 0),
            static fn () => new Client(self::API_KEY, timeout: 4, connectTimeout: 5),
        ] as $operation) {
            try {
                $operation();
                self::fail('Expected invalid timeouts to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_custom_headers_are_validated_and_cannot_override_security_headers(): void
    {
        $invalidHeaders = [
            ['Authorization' => 'Bearer attacker'],
            ['Host' => 'attacker.example'],
            ['Keep-Alive' => 'timeout=5'],
            ['Proxy-Authenticate' => 'Basic'],
            ['Proxy-Connection' => 'keep-alive'],
            ['TE' => 'trailers'],
            ['Trailer' => 'X-Checksum'],
            ['Upgrade' => 'websocket'],
            ["Bad\r\nHeader" => 'value'],
            ['X-Test' => "value\r\ninjected: true"],
            ['X-Test' => []],
            ['X-Test' => [123]],
        ];

        foreach ($invalidHeaders as $headers) {
            try {
                new Client(self::API_KEY, defaultHeaders: $headers);
                self::fail('Expected unsafe custom headers to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_valid_custom_headers_are_sent_without_weakening_defaults(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
        ], $history, defaultHeaders: ['X-Request-Id' => 'request-123']);

        $client->payees()->list();

        self::assertSame('request-123', $history[0]['request']->getHeaderLine('X-Request-Id'));
        self::assertSame('Bearer '.self::API_KEY, $history[0]['request']->getHeaderLine('Authorization'));
        self::assertTrue($history[0]['options']['verify']);
        self::assertFalse($history[0]['options']['allow_redirects']);
    }

    public function test_ids_payloads_batches_queries_and_sizes_fail_before_network_io(): void
    {
        $history = [];
        $client = $this->client([], $history);
        $createPayee = new ReflectionMethod($client->payees(), 'create');

        $operations = [
            static fn () => $client->payees()->show('../forbidden'),
            static fn () => $client->products()->archive('not-a-uuid'),
            static fn () => $createPayee->invoke($client->payees(), [['not' => 'an object']]),
            static fn () => $client->payees()->batchCreate([]),
            static fn () => $client->payees()->batchCreate([[]]),
            static fn () => $client->payees()->batchCreate(array_fill(0, 101, ['name' => 'One'])),
            static fn () => $client->payees()->batchUpdate([['name' => 'Missing id']]),
            static fn () => $client->products()->batchArchive([['id' => '../invalid']]),
            static fn () => $client->payees()->list(['bad name' => 'value']),
            static fn () => $client->payees()->list(['search' => str_repeat('q', Transport::MAX_QUERY_BYTES + 1)]),
            static fn () => $client->payees()->create(['notes' => str_repeat('x', Transport::MAX_REQUEST_BYTES + 1)]),
        ];

        foreach ($operations as $operation) {
            try {
                $operation();
                self::fail('Expected unsafe request input to fail.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertCount(0, $history);
    }

    public function test_credentials_are_sensitive_non_debuggable_and_non_serializable(): void
    {
        $privateBaseUrl = 'https://private-tenant.example.test/api/v1';
        $privateHeaderValue = 'private-correlation-value';
        $client = new Client(
            self::API_KEY,
            baseUrl: $privateBaseUrl,
            defaultHeaders: ['X-Correlation-Context' => $privateHeaderValue],
        );
        $debug = print_r($client, true);

        self::assertStringNotContainsString(self::API_KEY, $debug);
        self::assertStringNotContainsString($privateBaseUrl, $debug);
        self::assertStringNotContainsString($privateHeaderValue, $debug);
        self::assertStringNotContainsString('apiKey', $debug);
        $publicMethods = get_class_methods($client);
        sort($publicMethods);

        self::assertSame(
            ['__construct', '__debugInfo', '__serialize', 'baseUrl', 'connectTimeout', 'payees', 'products', 'revenues', 'rules', 'timeout'],
            $publicMethods,
        );

        $constructor = new ReflectionMethod(Client::class, '__construct');
        $apiKeyParameter = $constructor->getParameters()[0];
        self::assertNotEmpty($apiKeyParameter->getAttributes(\SensitiveParameter::class));

        $transportProperty = new ReflectionProperty(Client::class, 'transport');
        $transport = $transportProperty->getValue($client);
        self::assertInstanceOf(Transport::class, $transport);

        $credentialBearingObjects = [
            $client,
            $client->payees(),
            $client->products(),
            $client->revenues(),
            $client->rules(),
            $transport,
        ];

        foreach ($credentialBearingObjects as $object) {
            $export = var_export($object, true);
            self::assertStringNotContainsString(self::API_KEY, $export);
            self::assertStringNotContainsString($privateBaseUrl, $export);
            self::assertStringNotContainsString($privateHeaderValue, $export);

            try {
                serialize($object);
                self::fail('Expected credential-bearing SDK objects to reject serialization.');
            } catch (LogicException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame($privateBaseUrl, $client->baseUrl());
    }

    public function test_sensitive_parameters_cover_every_secret_and_customer_data_frame(): void
    {
        $classes = [
            Client::class,
            Transport::class,
            ApiResponse::class,
            AbstractResource::class,
            PayeesResource::class,
            ProductsResource::class,
            RevenuesResource::class,
            RulesResource::class,
        ];
        $sensitiveNames = [
            'apiKey',
            'baseUrl',
            'body',
            'clock',
            'defaultHeaders',
            'headers',
            'httpClient',
            'id',
            'path',
            'payload',
            'query',
            'records',
            'response',
            'transport',
        ];

        foreach ($classes as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class) {
                    continue;
                }

                foreach ($method->getParameters() as $parameter) {
                    $type = $parameter->getType();
                    $isHttpClient = $type instanceof ReflectionNamedType
                        && is_a($type->getName(), ClientInterface::class, true);

                    if (! in_array($parameter->getName(), $sensitiveNames, true) && ! $isHttpClient) {
                        continue;
                    }

                    self::assertNotEmpty(
                        $parameter->getAttributes(\SensitiveParameter::class),
                        sprintf('%s::%s($%s) must redact sensitive trace arguments.', $class, $method->getName(), $parameter->getName()),
                    );
                }
            }
        }
    }

    public function test_api_response_requires_deliberate_access_for_body_and_header_values(): void
    {
        $bodySecret = 'customer-financial-record';
        $headerSecret = 'private-upstream-header';
        $response = new ApiResponse(
            200,
            ['data' => ['secret' => $bodySecret], 'meta' => ['page' => 1]],
            ['X-Private-Context' => [$headerSecret]],
        );

        foreach ([print_r($response, true), var_export($response, true)] as $implicitOutput) {
            self::assertStringNotContainsString($bodySecret, $implicitOutput);
            self::assertStringNotContainsString($headerSecret, $implicitOutput);
        }

        try {
            serialize($response);
            self::fail('Expected API responses to reject native serialization.');
        } catch (LogicException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(['data' => ['secret' => $bodySecret], 'meta' => ['page' => 1]], $response->body());
        self::assertSame(['secret' => $bodySecret], $response->data());
        self::assertSame(['page' => 1], $response->meta());
        self::assertSame([$headerSecret], $response->header('x-private-context'));
        self::assertSame($headerSecret, $response->headerLine('X-Private-Context'));
        self::assertSame($response->body(), $response->jsonSerialize());
    }

    /** @param array<string, string|list<string>> $headers */
    #[DataProvider('oversizedResponseHeaderCases')]
    public function test_success_response_header_retention_is_bounded(array $headers): void
    {
        $responseHeaders = $headers;
        $responseHeaders['Content-Type'] = 'application/json';

        $client = $this->client([
            new Response(200, $responseHeaders, '{}'),
        ]);

        $this->expectException(InvalidResponseException::class);
        $client->payees()->list();
    }

    public function test_total_deadline_is_checked_before_dispatch(): void
    {
        $clockRead = 0;
        $clock = static function () use (&$clockRead): float {
            return $clockRead++ === 0 ? 0.0 : 2.0;
        };
        $httpClient = $this->createMock(ClientInterface::class);
        $httpClient->expects(self::never())->method('request');
        $transport = $this->transportWithClock($httpClient, $clock);

        $this->expectException(TransportException::class);
        $transport->request('GET', '/payees');
    }

    public function test_total_deadline_is_checked_by_the_header_callback(): void
    {
        $state = (object) ['now' => 0.0];
        $response = new Response(200, ['Content-Type' => 'application/json'], '{}');
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            static function (string $method, string $uri, array $options) use ($state, $response): ResponseInterface {
                $state->now = 2.0;
                self::assertIsCallable($options['on_headers'] ?? null);
                $options['on_headers']($response);

                return $response;
            },
        );
        $transport = $this->transportWithClock($httpClient, static fn (): float => $state->now);

        $this->expectException(TransportException::class);
        $transport->request('GET', '/payees');
    }

    public function test_total_deadline_is_checked_by_the_progress_callback(): void
    {
        $state = (object) ['now' => 0.0];
        $response = new Response(200, ['Content-Type' => 'application/json'], '{}');
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('request')->willReturnCallback(
            static function (string $method, string $uri, array $options) use ($state, $response): ResponseInterface {
                $state->now = 2.0;
                self::assertIsCallable($options['progress'] ?? null);
                $options['progress'](0, 0, 0, 0);

                return $response;
            },
        );
        $transport = $this->transportWithClock($httpClient, static fn (): float => $state->now);

        $this->expectException(TransportException::class);
        $transport->request('GET', '/payees');
    }

    public function test_total_deadline_is_checked_before_a_stream_read(): void
    {
        $state = (object) ['now' => 0.0];
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(true);
        $stream->method('rewind')->willReturnCallback(static function () use ($state): void {
            $state->now = 2.0;
        });
        $stream->expects(self::never())->method('read');
        $response = new Response(200, ['Content-Type' => 'application/json'], $stream);
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $transport = $this->transportWithClock($httpClient, static fn (): float => $state->now);

        $this->expectException(TransportException::class);
        $transport->request('GET', '/payees');
    }

    public function test_total_deadline_is_checked_after_each_stream_read(): void
    {
        $state = (object) ['now' => 0.0];
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('getSize')->willReturn(null);
        $stream->method('isSeekable')->willReturn(false);
        $stream->method('eof')->willReturn(false);
        $stream->expects(self::once())->method('read')->willReturnCallback(
            static function (int $length) use ($state): string {
                $state->now = 2.0;

                return '{}';
            },
        );
        $response = new Response(200, ['Content-Type' => 'application/json'], $stream);
        $httpClient = $this->createStub(ClientInterface::class);
        $httpClient->method('request')->willReturn($response);
        $transport = $this->transportWithClock($httpClient, static fn (): float => $state->now);

        $this->expectException(TransportException::class);
        $transport->request('GET', '/payees');
    }

    public function test_client_defaults_match_the_release_contract(): void
    {
        $client = new Client(self::API_KEY);

        self::assertSame('https://www.allocora.com/api/v1', $client->baseUrl());
        self::assertSame(30.0, $client->timeout());
        self::assertSame(5.0, $client->connectTimeout());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidApiKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => ['1234567'];
        yield 'space' => ['invalid key'];
        yield 'newline' => ["invalid\nkey"];
        yield 'non-ascii' => ['clé-secrète'];
        yield 'oversized' => [str_repeat('a', 4097)];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidBaseUrls(): iterable
    {
        yield 'http' => ['http://api.example.test/api/v1'];
        yield 'wrong path' => ['https://api.example.test/api'];
        yield 'missing API path' => ['https://api.example.test'];
        yield 'trailing slash' => ['https://api.example.test/api/v1/'];
        yield 'credentials' => ['https://user:password@api.example.test/api/v1'];
        yield 'query' => ['https://api.example.test/api/v1?tenant=one'];
        yield 'fragment' => ['https://api.example.test/api/v1#fragment'];
        yield 'surrounding whitespace' => [' https://api.example.test/api/v1'];
    }

    /** @return iterable<string, array{array<string, string|list<string>>}> */
    public static function oversizedResponseHeaderCases(): iterable
    {
        $tooManyHeaders = [];
        for ($index = 0; $index <= Transport::MAX_RESPONSE_HEADER_COUNT; $index++) {
            $tooManyHeaders['X-Bounded-'.$index] = 'value';
        }

        yield 'header count' => [$tooManyHeaders];
        yield 'header name' => [[str_repeat('X', Transport::MAX_RESPONSE_HEADER_NAME_BYTES + 1) => 'value']];
        yield 'header value' => [['X-Bounded' => str_repeat('v', Transport::MAX_RESPONSE_HEADER_VALUE_BYTES + 1)]];
        yield 'value count' => [[
            'X-Bounded' => array_fill(0, Transport::MAX_RESPONSE_HEADER_VALUE_COUNT + 1, 'value'),
        ]];
        yield 'aggregate bytes' => [[
            'X-Bounded-1' => str_repeat('a', 8_000),
            'X-Bounded-2' => str_repeat('b', 8_000),
            'X-Bounded-3' => str_repeat('c', 8_000),
            'X-Bounded-4' => str_repeat('d', 8_000),
            'X-Bounded-5' => str_repeat('e', 1_000),
        ]];
    }

    private function transportWithClock(ClientInterface $httpClient, \Closure $clock): Transport
    {
        return new Transport(
            apiKey: self::API_KEY,
            httpClient: $httpClient,
            baseUrl: self::BASE_URL,
            timeout: 1.0,
            connectTimeout: 0.5,
            clock: $clock,
        );
    }
}
