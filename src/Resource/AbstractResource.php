<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Resource;

use Elvesora\Allocora\Internal\Transport;
use Elvesora\Allocora\Response\ApiResponse;
use InvalidArgumentException;

abstract readonly class AbstractResource
{
    final public function __construct(#[\SensitiveParameter] protected Transport $transport) {}

    /**
     * @param  array<string, scalar|list<scalar|null>|null>  $query
     */
    final protected function get(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] array $query = [],
    ): ApiResponse {
        return $this->transport->request('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    final protected function post(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] array $payload,
    ): ApiResponse {
        return $this->transport->request('POST', $path, payload: $this->normalizeObjectPayload($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    final protected function put(
        #[\SensitiveParameter] string $path,
        #[\SensitiveParameter] array $payload,
    ): ApiResponse {
        return $this->transport->request('PUT', $path, payload: $this->normalizeObjectPayload($payload));
    }

    final protected function delete(#[\SensitiveParameter] string $path): ApiResponse
    {
        return $this->transport->request('DELETE', $path);
    }

    final protected function pathWithId(
        string $collectionPath,
        #[\SensitiveParameter] string $id,
        string $suffix = '',
    ): string {
        $id = trim($id);
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $id) !== 1) {
            throw new InvalidArgumentException('Allocora resource IDs must be canonical UUID strings.');
        }

        return $collectionPath.'/'.$id.$suffix;
    }

    /**
     * @param  array<array-key, mixed>  $records
     * @return array{records: list<array<string, mixed>>}
     */
    final protected function batchPayload(#[\SensitiveParameter] array $records, bool $requireId = false): array
    {
        if (! array_is_list($records) || count($records) < 1 || count($records) > 100) {
            throw new InvalidArgumentException('Batch operations require a list containing 1 to 100 records.');
        }

        $normalizedRecords = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                throw new InvalidArgumentException('Every batch record must be a JSON object.');
            }

            if ($record === []) {
                throw new InvalidArgumentException('Batch records cannot be empty.');
            }

            $normalizedRecord = $this->normalizeObjectPayload($record);

            if ($requireId) {
                $id = $normalizedRecord['id'] ?? null;
                if (! is_string($id)) {
                    throw new InvalidArgumentException('Every batch update or archive record must contain a UUID id.');
                }

                $this->pathWithId('/records', $id);
            }

            $normalizedRecords[] = $normalizedRecord;
        }

        return ['records' => $normalizedRecords];
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeObjectPayload(#[\SensitiveParameter] array $payload): array
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException('Single-record payloads must be associative arrays representing JSON objects.');
        }

        $normalized = [];
        foreach ($payload as $name => $value) {
            if (! is_string($name)) {
                throw new InvalidArgumentException('JSON object field names must be strings.');
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['resource' => static::class];
    }
}
