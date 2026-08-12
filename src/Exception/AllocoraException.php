<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Exception;

use RuntimeException;

class AllocoraException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly ?int $statusCode = null,
        private readonly string $errorType = 'allocora_error',
        private readonly bool $retryable = false,
        private readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message);
    }

    public function statusCode(): ?int
    {
        return $this->statusCode;
    }

    public function errorType(): string
    {
        return $this->errorType;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Raw error response bodies are never retained or exposed.
     *
     * @return array{}
     */
    public function responseBody(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'message' => $this->getMessage(),
            'status_code' => $this->statusCode,
            'error_type' => $this->errorType,
            'retryable' => $this->retryable,
            'retry_after' => $this->retryAfter,
        ];
    }
}
