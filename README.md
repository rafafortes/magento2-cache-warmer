# Magento 2 Cache Warmer

Small PHP CLI utility that requests every page listed in a sitemap. It is
intended to warm Magento/Varnish caches from inside the PHP container.

## Requirements

- PHP 8.0+
- Composer
- `ext-curl` and `ext-simplexml`

## Installation in a project

Install the package from the Magento project root:

```bash
composer require rafafortes/magento2-cache-warmer
```

The executable is installed by Composer at `vendor/bin/magento2-cache-warmer`.

## Usage

```bash
vendor/bin/magento2-cache-warmer --sitemap=<url> [--threads=<count>] [--retries=<count>]
```

Example inside the PHP container:

```bash
cd /var/www/html
vendor/bin/magento2-cache-warmer \
  --sitemap=https://shop.test/feeds/sitemap.xml \
  --threads=5 \
  --retries=3
```

Options:

- `--sitemap=<url>` — required URL of the sitemap reachable from the PHP
  container. The sitemap can be a regular sitemap or a sitemap index.
- `--threads=<count>` — optional positive integer; number of page requests made
  in parallel. The default is `1`.
- `--retries=<count>` — optional non-negative integer; number of retries after
  the initial attempt for transport failures and transient HTTP statuses
  (`408`, `425`, `429`, and `500`–`504`). The default is `0`. Retry attempts use
  a short exponential backoff. `--retry=<count>` is accepted as an alias.

The previous positional form, `<sitemapUrl> [threads]`, remains accepted for
compatibility, but named options are recommended.

For every sitemap and page request, the command prints the URL, HTTP status,
result (`OK` or `FAIL`) and elapsed time after the final attempt. Blocked URLs
are reported as `BLOCKED`. It prints a final count when the run finishes.

Security rules are fail-closed: sitemap URLs must use the same HTTP(S) scheme,
host and port as the sitemap, resolve to public addresses, and redirects are
validated before they are followed. URLs with credentials or dangerous schemes
are rejected. For a deliberately trusted local Docker host, set its exact host
name explicitly:

```bash
CACHE_WARMER_TRUSTED_PRIVATE_HOSTS=shop.test \
  vendor/bin/magento2-cache-warmer \
  --sitemap=https://shop.test/feeds/sitemap.xml --threads=5 --retries=3
```

The current version deliberately does **not** accept a base URL, follow links
found in HTML, load seed URLs, or apply a blacklist. Only URLs explicitly
listed in the supplied sitemap are requested. There is no `--debug` option.

## Library usage

```php
use Magento2CacheWarmer\UrlFetcher;

$warmer = new UrlFetcher(
    'https://example.com/sitemap.xml',
    maxThreads: 5,
    maxRetries: 3
);
$successfulUrls = $warmer->getFetchedUrls();
$failedUrls = $warmer->getFailedUrls();
```

## Architecture

- `UrlFetcher` is the public facade and accepts the sitemap URL, concurrency,
  and retry settings.
- `Crawler` loads the sitemap, expands sitemap indexes and fetches only their
  listed URLs.
- `CurlMultiHttpClient` performs concurrent requests with TLS verification,
  redirects and bounded connect/total timeouts.
- `RetryingHttpClient` retries transient failures without printing duplicate
  progress lines.
- `OutputInterface` and `ConsoleOutput` provide per-request progress output.
- `HttpClientInterface` allows deterministic test doubles.

## Tests and quality gate

Run the tests:

```bash
composer test
```

Run all configured checks:

```bash
composer quality
```

The quality command runs PHPStan, PHPCS, PHPMD, `composer audit` and PHPUnit.
Tests use local fakes and do not make live network requests.
