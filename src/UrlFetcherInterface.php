<?php

declare(strict_types=1);

namespace Magento2CacheWarmer;

interface UrlFetcherInterface
{
    /** @return list<string> URLs fetched successfully from the sitemap. */
    public function getFetchedUrls(): array;

    /** @return list<string> URLs that could not be fetched or parsed. */
    public function getFailedUrls(): array;
}
