<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Security;

/** Resolves hosts without silently accepting an unresolved destination. */
final class SystemHostResolver implements HostResolverInterface
{
    /** @return list<string> */
    public function resolve(string $host): array
    {
        $addresses = [];
        $ipv4 = gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = $ipv4;
        }

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $recordHost = strtolower(rtrim((string) ($record['host'] ?? ''), '.'));
                if ($recordHost !== strtolower(rtrim($host, '.'))) {
                    continue;
                }
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address)) {
                    $addresses[] = $address;
                }
            }
        }

        return array_values(array_unique($addresses));
    }
}
