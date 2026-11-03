# Allocora PHP

`elvesora/allocora-php` is the framework-independent PHP client for Allocora's public v1 API. It provides explicit resource methods for payees, products, immutable revenue records, and allocation rules without requiring a framework integration.

## Requirements

- PHP 8.2, 8.3, 8.4, or 8.5
- JSON and Mbstring PHP extensions
- Guzzle 7.9 or later in the 7.x series
- An Allocora API key with the scopes needed by your operations

## Installation

```bash
composer require elvesora/allocora-php:^0.1
```

Composer resolves stable releases through Packagist. Pin the compatible `^0.1` series as shown above and review the changelog before upgrading.

## Quick start

Store the key outside source control, for example as `ALLOCORA_API_KEY`, and pass it directly to the client:

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Elvesora\Allocora\Client;

$apiKey = getenv('ALLOCORA_API_KEY');

if (!is_string($apiKey) || $apiKey === '') {
    throw new RuntimeException('ALLOCORA_API_KEY is required.');
}

$allocora = new Client($apiKey);

$response = $allocora->payees()->list();

foreach ((array) $response->data() as $payee) {
    echo $payee['id'].PHP_EOL;
}
```

The default base URL is `https://www.allocora.com/api/v1`. A custom URL must use HTTPS, end in `/api/v1`, contain no credentials/query/fragment, and have no trailing slash:

```php
$allocora = new Client(
    apiKey: $apiKey,
    baseUrl: 'https://staging.example.com/api/v1',
);
```

## Authentication and scopes

Every request uses `Authorization: Bearer <key>`. Create keys in Allocora with only the scopes your integration needs:

| Resource | Read scope | Write scope |
| --- | --- | --- |
| Payees | `read:payees` | `write:payees` |
| Products | `read:products` | `write:products` |
| Revenues | `read:revenues` | `write:revenues` |
| Rules | `read:rules` | `write:rules` |

Missing or invalid keys produce `AuthenticationException` (401). Insufficient scope produces `AuthorizationException` (403).

## Resource methods

All methods return `Elvesora\Allocora\Response\ApiResponse`. The [public API reference](https://www.allocora.com/docs/api-endpoints) documents the complete request and response shapes for every resource.

### Payees

```php
$allocora->payees()->list();
$allocora->payees()->show($payeeId);
$allocora->payees()->create($payee);
$allocora->payees()->batchCreate($payees);
$allocora->payees()->upsert($payeeWithExternalId);
$allocora->payees()->batchUpsert($payeesWithExternalIds);
$allocora->payees()->update($payeeId, $changes);
$allocora->payees()->batchUpdate($recordsWithIds);
```

### Products

Products support the payee method set plus soft archival. Archiving changes product status; it does not hard-delete financial history.

```php
$allocora->products()->list();
$allocora->products()->show($productId);
$allocora->products()->create($product);
$allocora->products()->batchCreate($products);
$allocora->products()->upsert($productWithExternalId);
$allocora->products()->batchUpsert($productsWithExternalIds);
$allocora->products()->update($productId, $changes);
$allocora->products()->batchUpdate($recordsWithIds);
$allocora->products()->archive($productId);
$allocora->products()->batchArchive($recordsWithIds);
```

### Revenue records

Revenue records are append-only financial facts. The SDK intentionally has no revenue update or delete methods. Correct an error by recording the appropriate refund or adjustment through Allocora's documented revenue workflow.

```php
$response = $allocora->revenues()->create([
    'revenue_source_id' => $revenueSourceId,
    'external_id' => 'invoice-1042',
    'occurred_at' => '2026-08-11T10:30:00Z',
    'amount' => '150.000',
    'currency' => 'USD',
    'type' => 'sale',
]);

$allocora->revenues()->batchCreate($revenues);
$allocora->revenues()->show($revenueId);
```

Revenue listing is paginated and filterable. Query values are encoded by Guzzle:

```php
$page = $allocora->revenues()->list([
    'page' => 2,
    'per_page' => 50,
    'currency' => 'USD',
    'date_from' => '2026-08-01',
    'date_to' => '2026-08-31',
]);

$records = $page->data();
$pagination = $page->meta();
```

### Rules

Rule updates and status changes create new immutable rule versions.

```php
$allocora->rules()->list();
$allocora->rules()->show($ruleId);
$allocora->rules()->create($rule);
$allocora->rules()->batchCreate($rules);
$allocora->rules()->upsert($ruleWithExternalId);
$allocora->rules()->batchUpsert($rulesWithExternalIds);
$allocora->rules()->update($ruleId, $changes);
$allocora->rules()->batchUpdate($recordsWithIds);
$allocora->rules()->toggleStatus($ruleId);
$allocora->rules()->versions($ruleId);
```

#### Retail-price (RRP) allocations

A flat allocation can calculate its royalty on the retail price instead of actual receipts. Rule reads (`show`, `list`, `versions`) return the stored allocation keys unchanged:

```php
$rule = $allocora->rules()->show($ruleId)->data();

foreach ($rule['allocations'] as $allocation) {
    $basis = $allocation['royalty_basis'] ?? 'receipts'; // a missing key means actual receipts
    $source = $allocation['rrp_source'] ?? null;          // 'file' or 'product_history' when $basis is 'rrp'
    $rate = $allocation['percentage'];                    // for 'rrp': percent of units × retail price, not of receipts
}
```

Treat any other `royalty_basis` value as unknown rather than as receipts. Retail-price allocations are created and changed in the Allocora workspace rule editor. Through the API you can rename a rule, change its status, or toggle it; send only the fields you change:

```php
$allocora->rules()->update($ruleId, ['name' => 'Retail royalty 2027']);
$allocora->rules()->toggleStatus($ruleId);
```

The API returns `422` (`ValidationException`) and creates no rule version when a write sends `royalty_basis` or `rrp_source` in an allocation, or sends `allocations` or `allocation_type` for a rule that uses the retail-price basis — including the allocations you just read. Do not retry such a request; change the allocation in the workspace instead.

## Batch operations and partial failures

Batch methods accept a list of 1 to 100 non-empty JSON objects and send the API's required `{ "records": [...] }` envelope. Update and archive records must contain an `id` UUID.

Allocora processes each record independently. A successful HTTP response can therefore contain both successes and failures:

```php
$response = $allocora->payees()->batchCreate($records);

foreach ((array) $response->data() as $result) {
    if ($result['success'] === false) {
        // Handle this record using index, status, error, and optional errors.
    }
}

$summary = $response->meta(); // processed, succeeded, failed, limit
```

Do not treat HTTP 200 alone as proof that every batch record succeeded.

## Responses

`ApiResponse` is immutable and exposes the decoded JSON object and response metadata:

```php
$response->status();             // HTTP status
$response->body();               // complete decoded JSON object
$response->headers();            // array<string, list<string>>
$response->header('X-Request-Id');
$response->headerLine('Retry-After');
$response->data();               // body['data'] or null
$response->meta();               // body['meta'] or null
```

Successful responses must be JSON objects with a JSON content type. Malformed JSON, JSON lists/scalars, unreadable streams, bodies above 10 MiB, and unsafe or oversized response-header sets throw `InvalidResponseException`. Retained response headers are limited to 100 names, 200 values, 128 bytes per name, 8 KiB per value, and 32 KiB in aggregate.

Response bodies and header values are omitted from `print_r()` and `var_export()` output, and native `serialize()` is rejected. Access through the methods above or `json_encode()` is deliberate and returns the real response data, so do not send that output to logs or telemetry unless it is appropriate for the destination. Non-success response bodies are treated as untrusted and discarded after bounded parsing; the HTTP status remains the authoritative exception classification.

## Errors and retry policy

```php
use Elvesora\Allocora\Exception\AuthenticationException;
use Elvesora\Allocora\Exception\ApiException;
use Elvesora\Allocora\Exception\AuthorizationException;
use Elvesora\Allocora\Exception\NotFoundException;
use Elvesora\Allocora\Exception\RateLimitException;
use Elvesora\Allocora\Exception\ServerException;
use Elvesora\Allocora\Exception\TransportException;
use Elvesora\Allocora\Exception\ValidationException;

try {
    $response = $allocora->products()->show($productId);
} catch (ValidationException $exception) {
    $status = $exception->statusCode();
} catch (RateLimitException $exception) {
    $waitSeconds = $exception->retryAfter();
} catch (TransportException|ServerException $exception) {
    $retryable = $exception->retryable();
} catch (ApiException $exception) {
    $status = $exception->statusCode();
    $retryable = $exception->retryable();
}
```

API exceptions expose only SDK-defined metadata through `statusCode()`, `errorType()`, `retryable()`, and `retryAfter()`. Raw upstream error bodies, validation messages, error codes, traces, and server details are never retained. Consequently, `responseBody()` and `ValidationException::errors()` return empty arrays; validate submitted data locally and use the fixed exception type and status to choose the user-facing recovery path.

Constructor and request-input validation throws PHP's `InvalidArgumentException` for invalid configuration, UUIDs, queries, payloads, or batch records. These failures occur before a request is sent and are not retryable.

`retryAfter()` accepts only bounded numeric seconds or a bounded HTTP date and caps the result at seven days. Oversized or malformed retry metadata is discarded and returns `null`.

Typed status mapping:

| Failure | Exception | Retryable metadata |
| --- | --- | --- |
| 401 | `AuthenticationException` | No |
| 403 | `AuthorizationException` | No |
| 404 | `NotFoundException` | No |
| 422 | `ValidationException` | No |
| 429 | `RateLimitException` | Yes; inspect `retryAfter()` |
| 5xx | `ServerException` | Yes |
| Other non-2xx | `ApiException` | 408 only |
| Network/transport | `TransportException` | Yes |
| Invalid response | `InvalidResponseException` | Depends on status |

The SDK **never retries automatically**. Writes can represent financial facts and are not assumed idempotent. Before retrying any create, upsert, update, batch, archive, or rule-status request, reconcile the result with Allocora and use stable external identifiers where the API supports them. Respect `retryAfter()` for 429 responses.

## Transport and secret safety

- Total request timeout: 30 seconds, enforced with a monotonic deadline across dispatch, response headers, transfer progress, and streamed body reads.
- Connection timeout: 5 seconds.
- TLS certificate verification is enabled.
- Redirects and Guzzle HTTP exceptions are disabled so credentials are not forwarded and status mapping stays deterministic.
- Request bodies are limited to 2 MiB; query strings to 8 KiB; decoded response bodies to 10 MiB; retained response headers are bounded as described above.
- API keys, private base URLs, custom headers, injected HTTP clients, resource identifiers, queries, payloads, and batch records use protected storage or PHP's `SensitiveParameter` attribute where they cross SDK call frames.
- Client, transport, and response serialization is blocked. Debug/export output omits credentials, private endpoints, custom-header values, and response data.
- Transport and HTTP exceptions use fixed messages and discard untrusted upstream error bodies instead of attempting field-name redaction.

You remain responsible for keeping the key out of source control, logs, traces, analytics, job payloads, caches, browser output, and user-visible error messages. Rotate a key immediately if it is disclosed.

Optional custom headers are accepted in the constructor. Security-controlled and hop-by-hop headers cannot be overridden, including `Authorization`, `Host`, `Cookie`, `Content-Type`, `User-Agent`, `Connection`, `Keep-Alive`, `Proxy-Authenticate`, `Proxy-Authorization`, `Proxy-Connection`, `TE`, `Trailer`, `Transfer-Encoding`, and `Upgrade`.

## Supported API boundary

This package exposes exactly the 32 bearer-authenticated operations recorded in `resources/public-api-manifest.json`. The manifest and the four resource classes are the complete public API surface; the client has no generic request escape hatch.

## Development

```bash
composer install
composer format
composer check
composer archive --format=zip
```

See [CONTRIBUTING.md](CONTRIBUTING.md), [SECURITY.md](SECURITY.md), and [CHANGELOG.md](CHANGELOG.md).

## License

MIT. See [LICENSE](LICENSE).
