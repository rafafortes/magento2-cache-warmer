<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Http;

/** Concurrent HTTP client abstraction. */
interface HttpClientInterface
{
    /**
     * Fetches every URL concurrently and returns one result per URL.
     *
     * @param list<string> $urls
     * @param (callable(FetchResult): void)|null $onComplete
     *
     * @return array<string, FetchResult> Keyed by the requested URL.
     */
    public function fetchMultiple(array $urls, ?callable $onComplete = null): array;
}
