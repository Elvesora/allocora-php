# Changelog

All notable changes to this package are documented here.

## [Unreleased]

### Documentation

- Rule reads return retail-price (RRP) allocation keys `royalty_basis` and `rrp_source` unchanged; README explains reading them, name/status-only updates, and the `422` refusal (no retry) for writes that send these keys or replace the allocations of a retail-price rule.

### Tests

- Contract tests for unchanged pass-through of RRP and legacy rule reads, name/status-only request payloads, and a single non-retried `422` refusal.

## [0.1.0] - 2026-08-12

### Added

- Framework-independent client for the 32 public Allocora API v1 operations.
- Explicit payee, product, revenue, and rule resource clients.
- Immutable generic API responses with status, body, headers, data, and meta access.
- Typed HTTP, validation, rate-limit, server, transport, and invalid-response exceptions with fixed messages and metadata.
- Fail-closed error handling that never retains or exposes untrusted upstream error bodies, validation details, server paths, environment values, or provider error codes.
- Safe HTTPS boundary validation, explicit timeouts, TLS verification, disabled redirects, bounded request/response sizes, and no automatic retries.
- Monotonic end-to-end deadline enforcement across request dispatch, response-header callbacks, transfer progress, and streamed body reads.
- Protected storage and sensitive-parameter coverage for credentials, private endpoints, custom headers, injected HTTP clients, resource IDs, queries, payloads, batches, and response data.
- Export-safe, debug-safe, non-serializable API responses that preserve deliberate accessor and JSON behavior.
- Bounded successful response-header retention and rejection of unsafe or oversized header sets.
- Bounded `Retry-After` parsing that discards oversized or malformed selected error metadata.
- Complete `500` through `599` `ServerException` mapping and rejection of standard hop-by-hop custom headers.
- Canonical public API contract manifest and parity-focused test suite.
- PHP 8.2 through 8.5 CI matrix, static analysis, formatting, security audit, dependency updates, and archive verification.
