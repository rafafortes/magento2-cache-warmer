<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Tests;

use Magento2CacheWarmer\Security\HostResolverInterface;

final class FakeHostResolver implements HostResolverInterface
{
    /** @param array<string, list<string>> $addresses */
    public function __construct(private array $addresses)
    {
    }

    /** @return list<string> */
    public function resolve(string $host): array
    {
        return $this->addresses[$host] ?? [];
    }
}
