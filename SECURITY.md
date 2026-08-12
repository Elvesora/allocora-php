# Security Policy

## Supported versions

Security updates are provided for the latest `0.1.x` release until a newer supported release line is announced.

## Reporting a vulnerability

Do not open a public issue for a suspected vulnerability. Use GitHub's private vulnerability reporting for the `Elvesora/allocora-php` repository. Include the affected version, impact, reproduction steps, and any proposed remediation.

Do not include real Allocora API keys, customer data, financial records, or other secrets in reports. Use clearly fictional values and revoke any credential that may have been exposed.

We will acknowledge a complete report, investigate it, and coordinate remediation and disclosure through the private report. Public disclosure should wait until a fix is available.

## Client security assumptions

The SDK verifies TLS, rejects redirects, enforces a monotonic total request deadline, bounds request bodies, query strings, response bodies, and retained response headers, discards untrusted upstream error bodies, uses fixed exception messages and metadata, and does not retry automatically. Credentials, private endpoints, custom-header values, request data, and response data are protected from implicit stack-trace/debug/export disclosure inside SDK objects; native serialization of clients, transports, and responses is rejected.

Deliberate accessors and JSON serialization still return real response data. Consumers must protect API keys and customer data in their own configuration, logs, queues, caches, telemetry, backups, and deployment systems.
