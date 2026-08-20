<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Queue;

/** FIFO queue that prevents duplicate pending URLs. */
final class UrlQueue
{
    /** @var list<string> */
    private array $items = [];

    /** @var array<string, true> */
    private array $known = [];

    public function add(string $url): bool
    {
        if ($url === '' || isset($this->known[$url])) {
            return false;
        }
        $this->known[$url] = true;
        $this->items[] = $url;

        return true;
    }

    /** @return list<string> */
    public function take(int $limit): array
    {
        if ($limit < 1 || $this->items === []) {
            return [];
        }

        return array_splice($this->items, 0, $limit);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
