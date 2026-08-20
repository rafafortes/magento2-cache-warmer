<?php

declare(strict_types=1);

namespace Magento2CacheWarmer;

use Magento2CacheWarmer\Crawl\CrawlResult;
use Magento2CacheWarmer\Crawl\Crawler;
use Magento2CacheWarmer\Http\CurlMultiHttpClient;
use Magento2CacheWarmer\Http\HttpClientInterface;
use Magento2CacheWarmer\Output\OutputInterface;
use Magento2CacheWarmer\Security\HostResolverInterface;
use Magento2CacheWarmer\Security\UrlGuard;
use Magento2CacheWarmer\Sitemap\XmlSitemapParser;

/** Public facade for warming the pages listed in one sitemap. */
final class UrlFetcher implements UrlFetcherInterface
{
    private ?CrawlResult $result = null;

    /**
     * @param list<string> $trustedPrivateHosts Exact hosts allowed to resolve to private IPs.
     */
    public function __construct(
        string $sitemapUrl,
        int $maxThreads = 1,
        ?HttpClientInterface $httpClient = null,
        ?OutputInterface $output = null,
        array $trustedPrivateHosts = [],
        ?HostResolverInterface $resolver = null
    ) {
        if (filter_var($sitemapUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('The sitemap URL must be a valid URL.');
        }
        if ($maxThreads < 1) {
            throw new \InvalidArgumentException('The thread count must be at least 1.');
        }
        $this->sitemapUrl = $sitemapUrl;
        $this->maxThreads = $maxThreads;
        $this->urlGuard = new UrlGuard($sitemapUrl, $trustedPrivateHosts, $resolver);
        $this->httpClient = $httpClient ?? new CurlMultiHttpClient(urlGuard: $this->urlGuard);
        $this->output = $output;
    }

    private string $sitemapUrl;
    private int $maxThreads;
    private HttpClientInterface $httpClient;
    private UrlGuard $urlGuard;
    private ?OutputInterface $output;

    /** @return list<string> URLs fetched successfully from the sitemap. */
    public function getFetchedUrls(): array
    {
        $this->run();
        return $this->result->visited;
    }

    /** @return list<string> URLs that could not be fetched or parsed. */
    public function getFailedUrls(): array
    {
        $this->run();
        return $this->result->skipped;
    }

    private function run(): void
    {
        if ($this->result !== null) {
            return;
        }
        $this->result = (new Crawler(
            $this->httpClient,
            new XmlSitemapParser(),
            $this->maxThreads,
            $this->urlGuard,
            $this->output
        ))->crawl($this->sitemapUrl);
    }
}
