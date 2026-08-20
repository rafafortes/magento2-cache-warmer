<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Crawl;

use Magento2CacheWarmer\Http\FetchResult;
use Magento2CacheWarmer\Http\HttpClientInterface;
use Magento2CacheWarmer\Output\OutputInterface;
use Magento2CacheWarmer\Queue\UrlQueue;
use Magento2CacheWarmer\Security\UrlGuard;
use Magento2CacheWarmer\Security\UrlSecurityException;
use Magento2CacheWarmer\Sitemap\XmlSitemapParser;

/** Fetches the URLs explicitly listed in a sitemap. */
final class Crawler
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private XmlSitemapParser $sitemapParser,
        private int $maxThreads,
        private UrlGuard $urlGuard,
        private ?OutputInterface $output = null
    ) {
        if ($maxThreads < 1) {
            throw new \InvalidArgumentException('The thread count must be at least 1.');
        }
    }

    public function crawl(string $sitemapUrl): CrawlResult
    {
        $queue = new UrlQueue();
        $skipped = [];
        $sitemap = $this->fetchOne($sitemapUrl, 'sitemap');

        if ($sitemap === null || !$sitemap->isSuccessful()) {
            $skipped[] = $sitemapUrl;
            return new CrawlResult([], $skipped);
        }

        try {
            $locations = $this->sitemapParser->parse((string) $sitemap->body);
            if ($this->sitemapParser->isIndex((string) $sitemap->body)) {
                $this->enqueueSitemapIndex($locations, $queue, $skipped);
            } else {
                foreach ($locations as $url) {
                    $queue->add($url);
                }
            }
        } catch (\RuntimeException) {
            $skipped[] = $sitemapUrl;
            return new CrawlResult([], $skipped);
        }

        $visited = [];
        while (!$queue->isEmpty()) {
            $batch = $queue->take($this->maxThreads);
            $allowedBatch = [];
            foreach ($batch as $url) {
                if ($this->isAllowed($url)) {
                    $allowedBatch[] = $url;
                } else {
                    $skipped[] = $url;
                    $this->writeBlockedHit($url);
                }
            }
            if ($allowedBatch === []) {
                continue;
            }

            $responses = $this->httpClient->fetchMultiple($allowedBatch, function (FetchResult $response): void {
                $this->writeHit($response);
            });

            foreach ($allowedBatch as $url) {
                $response = $responses[$url] ?? null;
                if ($response === null) {
                    $skipped[] = $url;
                    $this->writeMissingHit($url);
                    continue;
                }
                if ($response->isSuccessful()) {
                    $visited[] = $url;
                } else {
                    $skipped[] = $url;
                }
            }
        }

        return new CrawlResult($visited, $skipped);
    }

    /**
     * @param list<string> $locations
     * @param list<string> $skipped
     */
    private function enqueueSitemapIndex(array $locations, UrlQueue $queue, array &$skipped): void
    {
        $childQueue = new UrlQueue();
        foreach ($locations as $childUrl) {
            $childQueue->add($childUrl);
        }

        while (!$childQueue->isEmpty()) {
            $childUrl = $childQueue->take(1)[0];
            $child = $this->fetchOne($childUrl, 'sitemap');
            if ($child === null || !$child->isSuccessful()) {
                $skipped[] = $childUrl;
                continue;
            }

            try {
                foreach ($this->sitemapParser->parse((string) $child->body) as $url) {
                    $queue->add($url);
                }
            } catch (\RuntimeException) {
                $skipped[] = $childUrl;
            }
        }
    }

    private function fetchOne(string $url, string $kind): ?FetchResult
    {
        if (!$this->isAllowed($url)) {
            $this->writeBlockedHit($url, $kind);
            return null;
        }

        $responses = $this->httpClient->fetchMultiple([$url], function (FetchResult $response) use ($kind): void {
            $this->writeHit($response, $kind);
        });
        $response = $responses[$url] ?? null;
        if ($response === null) {
            $this->writeMissingHit($url, $kind);
        }

        return $response;
    }

    private function isAllowed(string $url): bool
    {
        try {
            $this->urlGuard->validate($url);
            return true;
        } catch (UrlSecurityException) {
            return false;
        }
    }

    private function writeHit(FetchResult $response, string $kind = 'page'): void
    {
        $status = $response->isSuccessful() ? 'OK' : 'FAIL';
        $this->output?->writeln(sprintf(
            '[%s] %s %s HTTP %d %.2f ms',
            $status,
            strtoupper($kind),
            $response->url,
            $response->httpCode,
            $response->elapsedMs
        ));
    }

    private function writeMissingHit(string $url, string $kind = 'page'): void
    {
        $this->output?->writeln(sprintf('[FAIL] %s %s HTTP 0', strtoupper($kind), $url));
    }

    private function writeBlockedHit(string $url, string $kind = 'page'): void
    {
        $this->output?->writeln(sprintf('[BLOCKED] %s %s', strtoupper($kind), $url));
    }
}
