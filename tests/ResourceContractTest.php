<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Tests;

use Elvesora\Allocora\Client;
use Elvesora\Allocora\Response\ApiResponse;
use GuzzleHttp\Psr7\Response;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ResourceContractTest extends TestCase
{
    /**
     * @param  array<string, mixed>|null  $expectedPayload
     *
     * @throws JsonException
     */
    #[DataProvider('operationCases')]
    public function test_every_public_operation_maps_to_its_exact_api_route(
        string $routeName,
        string $permission,
        string $resource,
        string $operation,
        string $method,
        string $path,
        ?array $expectedPayload,
    ): void {
        $history = [];
        $client = $this->client([
            new Response(200, [
                'Content-Type' => 'application/json; charset=UTF-8',
                'X-Request-Id' => 'request-123',
            ], json_encode([
                'data' => ['route' => $routeName],
                'meta' => ['permission' => $permission],
            ], JSON_THROW_ON_ERROR)),
        ], $history);

        $response = $this->invokeOperation($client, $resource, $operation);

        self::assertSame(200, $response->status());
        self::assertSame(['route' => $routeName], $response->data());
        self::assertSame(['permission' => $permission], $response->meta());
        self::assertSame('request-123', $response->headerLine('x-request-id'));
        self::assertCount(1, $history, 'The client must not retry API operations automatically.');

        $entry = $history[0];
        $request = $entry['request'];
        self::assertSame($method, $request->getMethod());
        self::assertSame(self::BASE_URL.$path, (string) $request->getUri()->withQuery(''));
        self::assertSame('Bearer '.self::API_KEY, $request->getHeaderLine('Authorization'));
        self::assertSame('application/json', $request->getHeaderLine('Accept'));
        self::assertSame('elvesora-allocora-php/0.1.0', $request->getHeaderLine('User-Agent'));
        self::assertIsFloat($entry['options']['timeout']);
        self::assertGreaterThan(0.0, $entry['options']['timeout']);
        self::assertLessThanOrEqual(30.0, $entry['options']['timeout']);
        self::assertSame(5.0, $entry['options']['connect_timeout']);
        self::assertTrue($entry['options']['verify']);
        self::assertFalse($entry['options']['allow_redirects']);
        self::assertFalse($entry['options']['http_errors']);
        self::assertTrue($entry['options']['stream']);

        if ($operation === 'list') {
            self::assertSame('page=2&per_page=25', $request->getUri()->getQuery());
        } else {
            self::assertSame('', $request->getUri()->getQuery());
        }

        if ($expectedPayload === null) {
            self::assertSame('', (string) $request->getBody());
            self::assertSame('', $request->getHeaderLine('Content-Type'));
        } else {
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
            self::assertSame(
                $expectedPayload,
                json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR),
            );
        }
    }

    public function test_response_exposes_an_immutable_generic_view(): void
    {
        $history = [];
        $client = $this->client([
            new Response(201, [
                'Content-Type' => 'application/json',
                'X-RateLimit-Remaining' => ['999'],
            ], '{"data":{"id":"one"},"meta":{"created":true}}'),
        ], $history);

        $response = $client->payees()->create(['name' => 'Ada']);

        self::assertInstanceOf(ApiResponse::class, $response);
        self::assertSame(201, $response->status());
        self::assertSame(['id' => 'one'], $response->data());
        self::assertSame(['created' => true], $response->meta());
        self::assertSame(['999'], $response->header('x-ratelimit-remaining'));
        self::assertSame($response->body(), $response->jsonSerialize());
        self::assertArrayNotHasKey('body', $response->__debugInfo());
    }

    /**
     * @throws JsonException
     */
    public function test_rule_reads_return_retail_price_and_legacy_allocation_keys_unchanged(): void
    {
        $allocations = [
            ['payee_id' => '22222222-2222-4222-8222-222222222222', 'percentage' => 10, 'royalty_basis' => 'rrp', 'rrp_source' => 'product_history'],
            ['payee_id' => '33333333-3333-4333-8333-333333333333', 'percentage' => '5.50', 'royalty_basis' => 'rrp', 'rrp_source' => 'file'],
            ['payee_id' => '44444444-4444-4444-8444-444444444444', 'percentage' => 5],
            ['payee_id' => '55555555-5555-4555-8555-555555555555', 'percentage' => 7, 'royalty_basis' => 'future_basis'],
        ];
        $rule = ['id' => self::RESOURCE_ID, 'name' => 'Example retail royalty', 'allocation_type' => 'flat', 'allocations' => $allocations];
        $history = [];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => $rule], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(['data' => [$rule]], JSON_THROW_ON_ERROR)),
        ], $history);

        // Identical arrays: keys, absent legacy keys, unknown values and
        // number/string percentages are all passed through unchanged.
        self::assertSame($rule, $client->rules()->show(self::RESOURCE_ID)->data());
        self::assertSame([$rule], $client->rules()->versions(self::RESOURCE_ID)->data());
        self::assertCount(2, $history);
    }

    /**
     * @throws JsonException
     */
    public function test_rule_name_and_status_changes_send_only_the_changed_fields(): void
    {
        $history = [];
        $client = $this->client([
            new Response(200, ['Content-Type' => 'application/json'], '{"data":{"id":"'.self::RESOURCE_ID.'"}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":[],"meta":{"processed":1,"succeeded":1,"failed":0,"limit":100}}'),
            new Response(200, ['Content-Type' => 'application/json'], '{"data":{"id":"'.self::RESOURCE_ID.'"}}'),
        ], $history);

        $client->rules()->update(self::RESOURCE_ID, ['name' => 'Retail royalty 2027']);
        $client->rules()->batchUpdate([['id' => self::RESOURCE_ID, 'status' => 'inactive']]);
        $client->rules()->toggleStatus(self::RESOURCE_ID);

        self::assertSame(['name' => 'Retail royalty 2027'], json_decode((string) $history[0]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(['records' => [['id' => self::RESOURCE_ID, 'status' => 'inactive']]], json_decode((string) $history[1]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR));
        self::assertSame('', (string) $history[2]['request']->getBody());
        foreach ($history as $entry) {
            self::assertStringNotContainsString('royalty_basis', (string) $entry['request']->getBody());
            self::assertStringNotContainsString('allocations', (string) $entry['request']->getBody());
        }
    }

    /**
     * @return iterable<string, array{string, string, string, string, string, string, array<string, mixed>|null}>
     */
    public static function operationCases(): iterable
    {
        $payload = ['external_id' => 'customer-1'];
        $records = ['records' => [$payload]];
        $updatedRecords = ['records' => [['id' => self::RESOURCE_ID, 'name' => 'Updated']]];
        $archivedRecords = ['records' => [['id' => self::RESOURCE_ID]]];

        yield 'payees.list' => ['api.v1.payees.index', 'read:payees', 'payees', 'list', 'GET', '/payees', null];
        yield 'payees.show' => ['api.v1.payees.show', 'read:payees', 'payees', 'show', 'GET', '/payees/'.self::RESOURCE_ID, null];
        yield 'payees.batchCreate' => ['api.v1.payees.batch-store', 'write:payees', 'payees', 'batchCreate', 'POST', '/payees/batch', $records];
        yield 'payees.batchUpsert' => ['api.v1.payees.batch-upsert', 'write:payees', 'payees', 'batchUpsert', 'POST', '/payees/batch/upsert', $records];
        yield 'payees.create' => ['api.v1.payees.store', 'write:payees', 'payees', 'create', 'POST', '/payees', $payload];
        yield 'payees.upsert' => ['api.v1.payees.upsert', 'write:payees', 'payees', 'upsert', 'POST', '/payees/upsert', $payload];
        yield 'payees.batchUpdate' => ['api.v1.payees.batch-update', 'write:payees', 'payees', 'batchUpdate', 'PUT', '/payees/batch', $updatedRecords];
        yield 'payees.update' => ['api.v1.payees.update', 'write:payees', 'payees', 'update', 'PUT', '/payees/'.self::RESOURCE_ID, $payload];

        yield 'products.list' => ['api.v1.products.index', 'read:products', 'products', 'list', 'GET', '/products', null];
        yield 'products.show' => ['api.v1.products.show', 'read:products', 'products', 'show', 'GET', '/products/'.self::RESOURCE_ID, null];
        yield 'products.batchCreate' => ['api.v1.products.batch-store', 'write:products', 'products', 'batchCreate', 'POST', '/products/batch', $records];
        yield 'products.batchUpsert' => ['api.v1.products.batch-upsert', 'write:products', 'products', 'batchUpsert', 'POST', '/products/batch/upsert', $records];
        yield 'products.create' => ['api.v1.products.store', 'write:products', 'products', 'create', 'POST', '/products', $payload];
        yield 'products.upsert' => ['api.v1.products.upsert', 'write:products', 'products', 'upsert', 'POST', '/products/upsert', $payload];
        yield 'products.batchUpdate' => ['api.v1.products.batch-update', 'write:products', 'products', 'batchUpdate', 'PUT', '/products/batch', $updatedRecords];
        yield 'products.update' => ['api.v1.products.update', 'write:products', 'products', 'update', 'PUT', '/products/'.self::RESOURCE_ID, $payload];
        yield 'products.batchArchive' => ['api.v1.products.batch-destroy', 'write:products', 'products', 'batchArchive', 'DELETE', '/products/batch', $archivedRecords];
        yield 'products.archive' => ['api.v1.products.destroy', 'write:products', 'products', 'archive', 'DELETE', '/products/'.self::RESOURCE_ID, null];

        yield 'revenues.list' => ['api.v1.revenues.index', 'read:revenues', 'revenues', 'list', 'GET', '/revenues', null];
        yield 'revenues.show' => ['api.v1.revenues.show', 'read:revenues', 'revenues', 'show', 'GET', '/revenues/'.self::RESOURCE_ID, null];
        yield 'revenues.batchCreate' => ['api.v1.revenues.batch-store', 'write:revenues', 'revenues', 'batchCreate', 'POST', '/revenues/batch', $records];
        yield 'revenues.create' => ['api.v1.revenues.store', 'write:revenues', 'revenues', 'create', 'POST', '/revenues', $payload];

        yield 'rules.list' => ['api.v1.rules.index', 'read:rules', 'rules', 'list', 'GET', '/rules', null];
        yield 'rules.show' => ['api.v1.rules.show', 'read:rules', 'rules', 'show', 'GET', '/rules/'.self::RESOURCE_ID, null];
        yield 'rules.batchCreate' => ['api.v1.rules.batch-store', 'write:rules', 'rules', 'batchCreate', 'POST', '/rules/batch', $records];
        yield 'rules.batchUpsert' => ['api.v1.rules.batch-upsert', 'write:rules', 'rules', 'batchUpsert', 'POST', '/rules/batch/upsert', $records];
        yield 'rules.create' => ['api.v1.rules.store', 'write:rules', 'rules', 'create', 'POST', '/rules', $payload];
        yield 'rules.upsert' => ['api.v1.rules.upsert', 'write:rules', 'rules', 'upsert', 'POST', '/rules/upsert', $payload];
        yield 'rules.batchUpdate' => ['api.v1.rules.batch-update', 'write:rules', 'rules', 'batchUpdate', 'PUT', '/rules/batch', $updatedRecords];
        yield 'rules.update' => ['api.v1.rules.update', 'write:rules', 'rules', 'update', 'PUT', '/rules/'.self::RESOURCE_ID, $payload];
        yield 'rules.toggleStatus' => ['api.v1.rules.toggle-status', 'write:rules', 'rules', 'toggleStatus', 'POST', '/rules/'.self::RESOURCE_ID.'/toggle-status', null];
        yield 'rules.versions' => ['api.v1.rules.versions', 'read:rules', 'rules', 'versions', 'GET', '/rules/'.self::RESOURCE_ID.'/versions', null];
    }

    private function invokeOperation(Client $client, string $resource, string $operation): ApiResponse
    {
        $payload = ['external_id' => 'customer-1'];

        return match ($resource.'.'.$operation) {
            'payees.list' => $client->payees()->list(['page' => 2, 'per_page' => 25]),
            'payees.show' => $client->payees()->show(self::RESOURCE_ID),
            'payees.create' => $client->payees()->create($payload),
            'payees.batchCreate' => $client->payees()->batchCreate([$payload]),
            'payees.upsert' => $client->payees()->upsert($payload),
            'payees.batchUpsert' => $client->payees()->batchUpsert([$payload]),
            'payees.update' => $client->payees()->update(self::RESOURCE_ID, $payload),
            'payees.batchUpdate' => $client->payees()->batchUpdate([['id' => self::RESOURCE_ID, 'name' => 'Updated']]),
            'products.list' => $client->products()->list(['page' => 2, 'per_page' => 25]),
            'products.show' => $client->products()->show(self::RESOURCE_ID),
            'products.create' => $client->products()->create($payload),
            'products.batchCreate' => $client->products()->batchCreate([$payload]),
            'products.upsert' => $client->products()->upsert($payload),
            'products.batchUpsert' => $client->products()->batchUpsert([$payload]),
            'products.update' => $client->products()->update(self::RESOURCE_ID, $payload),
            'products.batchUpdate' => $client->products()->batchUpdate([['id' => self::RESOURCE_ID, 'name' => 'Updated']]),
            'products.archive' => $client->products()->archive(self::RESOURCE_ID),
            'products.batchArchive' => $client->products()->batchArchive([['id' => self::RESOURCE_ID]]),
            'revenues.list' => $client->revenues()->list(['page' => 2, 'per_page' => 25]),
            'revenues.show' => $client->revenues()->show(self::RESOURCE_ID),
            'revenues.create' => $client->revenues()->create($payload),
            'revenues.batchCreate' => $client->revenues()->batchCreate([$payload]),
            'rules.list' => $client->rules()->list(['page' => 2, 'per_page' => 25]),
            'rules.show' => $client->rules()->show(self::RESOURCE_ID),
            'rules.create' => $client->rules()->create($payload),
            'rules.batchCreate' => $client->rules()->batchCreate([$payload]),
            'rules.upsert' => $client->rules()->upsert($payload),
            'rules.batchUpsert' => $client->rules()->batchUpsert([$payload]),
            'rules.update' => $client->rules()->update(self::RESOURCE_ID, $payload),
            'rules.batchUpdate' => $client->rules()->batchUpdate([['id' => self::RESOURCE_ID, 'name' => 'Updated']]),
            'rules.toggleStatus' => $client->rules()->toggleStatus(self::RESOURCE_ID),
            'rules.versions' => $client->rules()->versions(self::RESOURCE_ID),
            default => throw new LogicException('Unexpected operation fixture.'),
        };
    }
}
