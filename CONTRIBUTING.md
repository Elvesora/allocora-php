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

## Releases

The signed-tag policy applies to every release after `v0.1.0`. The immutable, attested `v0.1.0` release remains unchanged because its tag cannot be replaced.

Stable release tags must use format `vX.Y.Z`, be annotated, and be signed by a signing key registered with GitHub for a verified tagger identity. From a clean, reviewed `main` commit, create the tag locally and push its signed object only to the candidate namespace:

```bash
git tag -s vX.Y.Z -m "vX.Y.Z"
git tag -v vX.Y.Z
git push origin refs/tags/vX.Y.Z:refs/tags/release-candidates/vX.Y.Z
gh workflow run promote-release-tag.yml --ref main -f version=X.Y.Z
```

Do not push a public `vX.Y.Z` tag directly. Active repository rules allow only the dedicated release-promoter GitHub App to create public release tags and prevent every actor from updating or deleting them. The protected promotion environment holds the job for explicit approval and supplies the App credential. After approval, the workflow runs from the default branch, verifies the signed candidate against its exact commit, dispatches and waits for the complete `tests` workflow, and creates the public tag only after every gate passes.

Do not create or publish the GitHub release, or declare the Composer version supported, until `promote release tag / signed-tag` succeeds. Attach the audited distribution archive to a draft release before publication. Published release tags must never be moved, replaced, or deleted.
