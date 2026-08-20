<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Security;

interface HostResolverInterface
{
    /** @return list<string> Resolved IPv4 and IPv6 addresses. */
    public function resolve(string $host): array;
}
