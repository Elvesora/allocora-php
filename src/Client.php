<?php

declare(strict_types=1);

namespace Elvesora\Allocora;

use Elvesora\Allocora\Internal\Transport;
use Elvesora\Allocora\Resource\PayeesResource;
use Elvesora\Allocora\Resource\ProductsResource;
use Elvesora\Allocora\Resource\RevenuesResource;
use Elvesora\Allocora\Resource\RulesResource;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\ClientInterface;
use LogicException;

final readonly class Client
{
    public const DEFAULT_BASE_URL = 'https://www.allocora.com/api/v1';

    public const DEFAULT_TIMEOUT = 30.0;

    public const DEFAULT_CONNECT_TIMEOUT = 5.0;

    private Transport $transport;

    private PayeesResource $payees;

    private ProductsResource $products;

    private RevenuesResource $revenues;

    private RulesResource $rules;

    /**
     * @param  array<array-key, mixed>  $defaultHeaders
     */
    public function __construct(
        #[\SensitiveParameter] string $apiKey,
        #[\SensitiveParameter]
        ?ClientInterface $httpClient = null,
        #[\SensitiveParameter]
        string $baseUrl = self::DEFAULT_BASE_URL,
        float $timeout = self::DEFAULT_TIMEOUT,
        float $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        #[\SensitiveParameter]
        array $defaultHeaders = [],
    ) {
        $this->transport = new Transport(
            apiKey: $apiKey,
            httpClient: $httpClient ?? new HttpClient,
            baseUrl: $baseUrl,
            timeout: $timeout,
            connectTimeout: $connectTimeout,
            defaultHeaders: $defaultHeaders,
        );

        $this->payees = new PayeesResource($this->transport);
        $this->products = new ProductsResource($this->transport);
        $this->revenues = new RevenuesResource($this->transport);
        $this->rules = new RulesResource($this->transport);
    }

    public function payees(): PayeesResource
    {
        return $this->payees;
    }

    public function products(): ProductsResource
    {
        return $this->products;
    }

    public function revenues(): RevenuesResource
    {
        return $this->revenues;
    }

    public function rules(): RulesResource
    {
        return $this->rules;
    }

    public function baseUrl(): string
    {
        return $this->transport->baseUrl();
    }

    public function timeout(): float
    {
        return $this->transport->timeout();
    }

    public function connectTimeout(): float
    {
        return $this->transport->connectTimeout();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'base_url' => '[REDACTED]',
            'timeout' => $this->timeout(),
            'connect_timeout' => $this->connectTimeout(),
            'resources' => ['payees', 'products', 'revenues', 'rules'],
        ];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new LogicException('Allocora clients cannot be serialized.');
    }
}
