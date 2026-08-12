# Contributing

Thank you for improving the Allocora PHP client.

## Development setup

Requirements are PHP 8.2 or newer and Composer 2.

```bash
composer install
composer format
composer check
```

`composer check` runs strict Composer validation, PHPUnit, PHPStan, Pint's style check, and the locked dependency security audit.

## Contract changes

The package supports only the public operations in `resources/public-api-manifest.json`. Any API change must update the canonical Allocora application manifest and this package copy together, preserve exact manifest parity, update the relevant resource method and tests, and document the user-visible behavior in the README and changelog.

Keep the public surface closed: add an operation only when it is part of the canonical public manifest and has an explicit resource method, contract test, and user documentation.

## Pull requests

- Keep changes focused and backward-compatible where possible.
- Add tests for successful, failure, edge, transport, and credential-safety behavior.
- Do not add automatic retries for financial writes.
- Do not commit dependencies, lockfiles, caches, archives, credentials, local paths, customer data, or internal operational notes.
- Confirm `composer check` and Composer archive inspection pass before requesting review.
