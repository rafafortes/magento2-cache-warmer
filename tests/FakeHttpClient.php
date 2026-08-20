<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Tests;

use Magento2CacheWarmer\Http\FetchResult;
use Magento2CacheWarmer\Http\HttpClientInterface;

final class FakeHttpClient implements HttpClientInterface
{
    /** @var array<string, FetchResult> */
    private array $responses;

    /** @var list<string> */
    public array $requests = [];

    /** @param array<string, FetchResult> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /** @param list<string> $urls @return array<string, FetchResult> */
    public function fetchMultiple(array $urls, ?callable $onComplete = null): array
    {
        array_push($this->requests, ...$urls);
        $result = [];
        foreach ($urls as $url) {
            if (isset($this->responses[$url])) {
                $result[$url] = $this->responses[$url];
                if ($onComplete !== null) {
                    $onComplete($result[$url]);
                }
            }
        }

        return $result;
    }
}
