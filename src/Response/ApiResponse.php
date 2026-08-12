<?php

declare(strict_types=1);

namespace Elvesora\Allocora\Response;

use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use SensitiveParameterValue;

/**
 * @phpstan-type ResponseHeaders array<string, list<string>>
 */
final readonly class ApiResponse implements JsonSerializable
{
    private int $status;

    private SensitiveParameterValue $body;

    private SensitiveParameterValue $headers;

    /**
     * @param  array<string, mixed>  $body
     * @param  ResponseHeaders  $headers
     */
    public function __construct(
        int $status,
        #[\SensitiveParameter]
        array $body,
        #[\SensitiveParameter]
        array $headers,
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('The HTTP status must be between 100 and 599.');
        }

        $this->status = $status;
        $this->body = new SensitiveParameterValue($body);
        $this->headers = new SensitiveParameterValue($headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->protectedBody();
    }

    /**
     * @return ResponseHeaders
     */
    public function headers(): array
    {
        return $this->protectedHeaders();
    }

    public function data(): mixed
    {
        return $this->protectedBody()['data'] ?? null;
    }

    public function meta(): mixed
    {
        return $this->protectedBody()['meta'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function header(string $name): array
    {
        foreach ($this->protectedHeaders() as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return $values;
            }
        }

        return [];
    }

    public function headerLine(string $name): string
    {
        return implode(', ', $this->header($name));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->protectedBody();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'status' => $this->status,
            'header_count' => count($this->protectedHeaders()),
            'has_data' => array_key_exists('data', $this->protectedBody()),
            'has_meta' => array_key_exists('meta', $this->protectedBody()),
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Allocora API responses cannot be serialized.');
    }

    /** @return array<string, mixed> */
    private function protectedBody(): array
    {
        $body = $this->body->getValue();

        if (! is_array($body)) {
            throw new LogicException('The Allocora response body holder is invalid.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /** @return ResponseHeaders */
    private function protectedHeaders(): array
    {
        $headers = $this->headers->getValue();

        if (! is_array($headers)) {
            throw new LogicException('The Allocora response header holder is invalid.');
        }

        /** @var ResponseHeaders $headers */
        return $headers;
    }
}
