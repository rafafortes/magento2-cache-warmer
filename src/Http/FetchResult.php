<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Http;

/**
 * One completed HTTP response, keyed by the requested URL.
 */
final class FetchResult
{
    private function __construct(
        public string $url,
        public int $httpCode,
        public ?string $body,
        public float $elapsedMs
    ) {
    }

    /**
     * @param array{httpCode: int|null, body: string|null, elapsedMs: float} $info
     */
    public static function create(string $url, array $info): self
    {
        return new self($url, $info['httpCode'], $info['body'], $info['elapsedMs']);
    }

    public function isSuccessful(): bool
    {
        return $this->httpCode >= 200 && $this->httpCode < 300 && $this->body !== null;
    }
}
