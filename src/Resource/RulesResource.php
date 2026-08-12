<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Resource;

use Elvesora\Allocora\Response\ApiResponse;

final readonly class RulesResource extends AbstractResource
{
    private const PATH = '/rules';

    /** @param array<string, scalar|list<scalar|null>|null> $query */
    public function list(#[\SensitiveParameter] array $query = []): ApiResponse
    {
        return $this->get(self::PATH, $query);
    }

    public function show(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->get($this->pathWithId(self::PATH, $id));
    }

    /** @param array<string, mixed> $payload */
    public function create(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->post(self::PATH, $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchCreate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->post(self::PATH.'/batch', $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function upsert(#[\SensitiveParameter] array $payload): ApiResponse
    {
        return $this->post(self::PATH.'/upsert', $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpsert(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->post(self::PATH.'/batch/upsert', $this->batchPayload($records));
    }

    /** @param array<string, mixed> $payload */
    public function update(
        #[\SensitiveParameter] string $id,
        #[\SensitiveParameter] array $payload,
    ): ApiResponse {
        return $this->put($this->pathWithId(self::PATH, $id), $payload);
    }

    /** @param list<array<string, mixed>> $records */
    public function batchUpdate(#[\SensitiveParameter] array $records): ApiResponse
    {
        return $this->put(self::PATH.'/batch', $this->batchPayload($records, requireId: true));
    }

    public function toggleStatus(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->transport->request('POST', $this->pathWithId(self::PATH, $id, '/toggle-status'));
    }

    public function versions(#[\SensitiveParameter] string $id): ApiResponse
    {
        return $this->get($this->pathWithId(self::PATH, $id, '/versions'));
    }
}
