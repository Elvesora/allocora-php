<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Tests;

use JsonException;

final class ContractManifestTest extends TestCase
{
    /** @throws JsonException */
    public function test_manifest_is_the_exact_32_operation_public_contract(): void
    {
        $manifestPath = dirname(__DIR__).'/resources/public-api-manifest.json';
        $contents = file_get_contents($manifestPath);
        self::assertIsString($contents);

        $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);
        self::assertSame(1, $manifest['schema_version']);
        self::assertSame('v1', $manifest['api_version']);
        self::assertSame('/api/v1', $manifest['base_path']);
        self::assertSame('bearer', $manifest['authentication']);
        self::assertSame(['max_attempts' => 1000, 'window_seconds' => 60], $manifest['rate_limit']);

        $operations = $manifest['operations'];
        self::assertIsArray($operations);
        self::assertCount(32, $operations);

        /** @var array<string, array{string, string, string}> $manifestOperations */
        $manifestOperations = [];
        foreach ($operations as $operation) {
            self::assertIsArray($operation);
            self::assertSame(['name', 'method', 'path', 'permission'], array_keys($operation));
            $name = $operation['name'] ?? null;
            $method = $operation['method'] ?? null;
            $path = $operation['path'] ?? null;
            $permission = $operation['permission'] ?? null;

            self::assertIsString($name);
            self::assertIsString($method);
            self::assertIsString($path);
            self::assertIsString($permission);
            self::assertStringStartsWith('api.v1.', $name);
            self::assertStringStartsWith('/api/v1/', $path);
            self::assertContains($method, ['GET', 'POST', 'PUT', 'DELETE']);
            self::assertMatchesRegularExpression('/^(read|write):(payees|products|revenues|rules)$/', $permission);

            $manifestOperations[$name] = [$method, $path, $permission];
        }

        self::assertCount(32, $manifestOperations, 'Manifest operation names must be unique.');

        /** @var array<string, array{string, string, string}> $sdkOperations */
        $sdkOperations = [];
        foreach (ResourceContractTest::operationCases() as $case) {
            [$name, $permission, , , $method, $path] = $case;
            $manifestPath = str_replace(self::RESOURCE_ID, '{id}', '/api/v1'.$path);
            $sdkOperations[$name] = [$method, $manifestPath, $permission];
        }

        self::assertSame($manifestOperations, $sdkOperations);
    }
}
