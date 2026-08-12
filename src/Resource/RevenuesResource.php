<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Resource;

use Elvesora\Allocora\Response\ApiResponse;

final readonly class RevenuesResource extends AbstractResource
{
    private const PATH = '/revenues';

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
}
