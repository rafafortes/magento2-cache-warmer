<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Tests;

use Magento2CacheWarmer\UrlFetcherInterface;

final class FakeUrlFetcher implements UrlFetcherInterface
{
    public function getFetchedUrls(): array
    {
        return ['https://shop.test/page'];
    }

    public function getFailedUrls(): array
    {
        return [];
    }
}
