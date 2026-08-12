<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Tests;

use Elvesora\Allocora\Client;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use LogicException;
use PHPUnit\Framework\TestCase as BaseTestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

abstract class TestCase extends BaseTestCase
{
    protected const API_KEY = 'test_allocora_api_key_not_a_real_credential';

    protected const BASE_URL = 'https://api.example.test/api/v1';

    protected const RESOURCE_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * @param  list<ResponseInterface|Throwable>  $responses
     * @param  list<array{request: RequestInterface, options: array<array-key, mixed>}>  $history
     *
     * @param-out list<array{request: RequestInterface, options: array<array-key, mixed>}> $history
     *
     * @param  array<string, string|list<string>>  $defaultHeaders
     */
    protected function client(
        array $responses,
        array &$history = [],
        string $apiKey = self::API_KEY,
        string $baseUrl = self::BASE_URL,
        float $timeout = 30.0,
        float $connectTimeout = 5.0,
        array $defaultHeaders = [],
    ): Client {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);

        /** @param array<string, mixed> $options */
        $recordRequest = static function (RequestInterface $request, array $options) use (&$history): void {
            $history[] = [
                'request' => $request,
                'options' => $options,
            ];
        };

        $recordingLayer = static function (callable $next) use ($recordRequest): callable {
            return static function (RequestInterface $request, array $options) use ($next, $recordRequest): PromiseInterface {
                $recordRequest($request, $options);
                $promise = $next($request, $options);

                if (! $promise instanceof PromiseInterface) {
                    throw new LogicException('The test HTTP handler did not return a promise.');
                }

                return $promise;
            };
        };

        $handler->push($recordingLayer);

        return new Client(
            apiKey: $apiKey,
            httpClient: new HttpClient(['handler' => $handler]),
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            defaultHeaders: $defaultHeaders,
        );
    }
}
