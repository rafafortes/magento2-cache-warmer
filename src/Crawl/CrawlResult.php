<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Crawl;

/** Immutable result of one sitemap warming run. */
final class CrawlResult
{
    /**
     * @param list<string> $visited
     * @param list<string> $skipped
     */
    public function __construct(
        public array $visited,
        public array $skipped
    ) {
    }
}
