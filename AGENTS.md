# Repository Guidelines

## Project Overview

This repository is a PHP 8.0+ CLI package for warming Magento 2/Varnish caches.
It reads a sitemap (or sitemap index), fetches only the listed pages with
concurrent cURL requests, and reports every request as it completes.

Breaking changes to the former `UrlFetcher` script/API are acceptable, but the
essential crawler behavior must remain available through the current facade and
CLI.

## Project Structure

- `bin/magento2-cache-warmer` — executable CLI entry point; Composer exports it as a
  package binary.
- `src/UrlFetcher.php` — public facade implementing `UrlFetcherInterface` and
  composing the runtime dependencies. Importing it does not start a crawl.
- `src/UrlFetcherInterface.php` — fetcher seam used by the CLI and tests.
- `src/Cli/` — `Magento2CacheWarmerCommand`, the side-effecting command adapter.
- `src/Crawl/` — sitemap orchestration and immutable `CrawlResult` data.
- `src/Http/` — `HttpClientInterface`, cURL multi transport, and fetch results.
  TLS peer verification is enabled by default; redirects are validated by the
  URL guard and millisecond timeouts are configured by the transport.
- `src/Security/` — URL origin/SSRF validation, injectable host resolution, and
  explicit trusted-private-host handling.
- `src/Output/` — `OutputInterface`, `ConsoleOutput`, and terminal sanitization;
  capture mode must not write to the terminal.
- `src/Queue/` — deduplicating FIFO URL queue.
- `src/Sitemap/` — namespace-safe XML parsing, sitemap indexes, and parse errors.
- There are no seed URL or blacklist files: the sitemap is the sole URL source.
- `tests/` — PHPUnit unit tests, fakes, and local `file://` fixtures. Tests must
  not make live network requests.
- `phpstan.neon`, `phpcs.xml`, `phpmd.xml`, `phpunit.xml` — versioned quality
  tool configuration.

## Installation and Commands

```bash
composer install
composer dump-autoload
```

Run the CLI against a controlled host only:

```bash
composer require rafafortes/magento2-cache-warmer
vendor/bin/magento2-cache-warmer https://shop.test/sitemap.xml 5
```

The arguments are `<sitemapUrl>` and an optional positive concurrency count.
Every sitemap/page request is printed by default; there is no `--debug` option.
Set `CACHE_WARMER_TRUSTED_PRIVATE_HOSTS` to a comma-separated exact host list
only when a local private origin is deliberately trusted.

Run the test suite and complete quality gate with bounded commands:

```bash
timeout 180 composer test
timeout 300 composer quality
```

`composer quality` runs PHPStan over `src`, `bin`, and `tests`; PHPCS over
`src`, `tests`, and `bin`; PHPMD over `src`; `composer audit`; and PHPUnit with
text/Clover coverage. PHPStan, PHPCS, PHPMD, the dependency audit, and PHPUnit
must all pass. A PHP installation
without Xdebug or PCOV can run the tests but cannot collect coverage; install a
coverage driver and use:

```bash
XDEBUG_MODE=coverage composer run test-coverage
```

For a syntax-only check:

```bash
find src bin tests -name '*.php' -print0 | xargs -0 -n1 php -l
```

Use local fakes or `file://` fixtures for tests and smoke checks whenever
possible. A real HTTP crawl can generate load on every discovered internal URL.

## Coding Conventions

- Keep `declare(strict_types=1);` and PHP 8.0-compatible syntax.
- Use four-space indentation, typed properties, explicit return types, PascalCase
  classes, and camelCase methods/variables.
- Keep responsibilities in focused components and depend on interfaces at
  composition boundaries.
- Preserve sitemap parsing, sitemap-index support, deduplication, concurrency,
  validated redirects, TLS verification, SSRF protections, terminal
  sanitization, and per-request output. Do not add HTML link crawling or
  seed/blacklist configuration without an explicit request.
- Keep library imports side-effect free. Terminal writes belong in the CLI or
  output adapter, not in domain/application components.
- Match the configured PHPCS ruleset. There is no separate formatter.

## Testing Requirements

Add or update PHPUnit tests in `tests/` for every behavior change. Tests should
cover successful and failed sitemap/page requests, malformed input, deduplication,
sitemap namespaces and indexes, dangerous schemes, credentials, external/private
hosts, redirects, explicit trusted-private hosts, terminal control characters,
CLI argument validation, and dependency injection. Tests must not use live
network requests or live DNS. Do not use staging URLs or production credentials
in tests.

Before submitting a change, run at least:

```bash
composer validate --no-check-publish
timeout 180 composer quality
git diff --check
```

When reporting coverage, include the actual PHPUnit coverage summary and state
whether Xdebug/PCOV was available.

## Documentation and Git Discipline

Update `README.md` when public CLI/API behavior, architecture, installation, or
quality commands change. Do not add CI workflows unless explicitly requested;
there is no CI requirement in the current package scope.

Use focused imperative commit subjects following the repository convention, for
example `Fix - Close audit findings` or `Chore - Update documentation`. Do not
change Git identity, create branches, force-push, or commit credentials,
private URLs, caches, `vendor/`, `build/`, PHPUnit caches, or task/runtime
artifacts. Review `git diff` and `git status` before committing.

## Completion and Release Workflow

Every task that changes the package must finish with this sequence:

1. Run `composer validate --no-check-publish`, `timeout 300 composer quality`,
   and `git diff --check`. Do not release with any failing check. A missing
   Xdebug/PCOV driver is acceptable only when PHPUnit itself passes; report the
   missing coverage driver explicitly.
2. Review the complete diff and `git status`; confirm that only intended files
   are included and that no credentials, private URLs, generated artifacts, or
   task journals are staged.
3. Select the next SemVer version from the existing tags. Use a major version
   for breaking public API/CLI changes, minor for backwards-compatible
   features, and patch for fixes. Follow the repository's existing tag naming
   convention (currently bare `X.Y.Z`, without a `v` prefix) and ensure the
   tag does not already exist.
4. Commit the reviewed changes on the current branch with a focused imperative
   subject. Do not amend unrelated commits or create a release branch.
5. Push the current branch to its configured remote with `git push`.
6. Create an annotated tag named `X.Y.Z` on the pushed commit and publish it
   with `git push <remote> X.Y.Z`. The pushed tag is the Composer package
   release.
7. Create the hosted GitHub release associated with that exact tag, using the
   repository's authenticated GitHub CLI:
   `gh release create X.Y.Z --verify-tag --title "Release X.Y.Z" --generate-notes`.
   Do not create a release for a different tag or silently skip this step.
8. Verify the branch and tag with `git ls-remote` and the hosted release with
   `gh release view X.Y.Z`; then report the released version, commit, tag,
   release URL, quality-gate result, and any unavailable coverage driver.

Never commit, push, tag, or publish if the quality gate fails or the diff has
not been reviewed. Never force-push or delete/replace an existing release tag.
