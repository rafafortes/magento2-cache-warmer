<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Tests;

use Magento2CacheWarmer\Cli\Magento2CacheWarmerCommand;
use Magento2CacheWarmer\Crawl\Crawler;
use Magento2CacheWarmer\Http\CurlMultiHttpClient;
use Magento2CacheWarmer\Http\FetchResult;
use Magento2CacheWarmer\Output\ConsoleOutput;
use Magento2CacheWarmer\Output\TerminalSanitizer;
use Magento2CacheWarmer\Security\UrlGuard;
use Magento2CacheWarmer\Security\UrlSecurityException;
use Magento2CacheWarmer\Sitemap\SitemapParseException;
use Magento2CacheWarmer\Sitemap\XmlSitemapParser;
use Magento2CacheWarmer\UrlFetcher;
use PHPUnit\Framework\TestCase;

final class UnitTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryPaths) as $path) {
            if (is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                rmdir($path);
            }
        }
        $this->temporaryPaths = [];
        parent::tearDown();
    }

    public function testSitemapParserSupportsNamespacesAndRejectsInvalidXml(): void
    {
        $parser = new XmlSitemapParser();
        self::assertSame(
            ['https://shop.test/page'],
            $parser->parse('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://shop.test/page</loc></url></urlset>')
        );
        self::assertSame(
            ['https://shop.test/page'],
            $parser->parse('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1"><url><loc>https://shop.test/page</loc><image:image><image:loc>https://shop.test/image.jpg</image:loc></image:image></url></urlset>')
        );
        self::assertTrue($parser->isIndex('<sitemapindex><sitemap><loc>https://shop.test/child.xml</loc></sitemap></sitemapindex>'));
        self::assertFalse($parser->isIndex('<urlset><url><loc>https://shop.test/page</loc></url></urlset>'));
        $this->expectException(SitemapParseException::class);
        $parser->parse('<urlset>');
    }

    public function testCrawlerFetchesOnlySitemapUrlsAndReportsEachHit(): void
    {
        $sitemap = 'https://shop.test/sitemap.xml';
        $listed = 'https://shop.test/listed';
        $failed = 'https://shop.test/failed';
        $client = new FakeHttpClient([
            $sitemap => FetchResult::create($sitemap, ['httpCode' => 200, 'body' => '<urlset><url><loc>' . $listed . '</loc></url><url><loc>' . $failed . '</loc></url></urlset>', 'elapsedMs' => 1.0]),
            $listed => FetchResult::create($listed, ['httpCode' => 200, 'body' => '<a href="/not-listed">not listed</a>', 'elapsedMs' => 2.0]),
            $failed => FetchResult::create($failed, ['httpCode' => 503, 'body' => 'unavailable', 'elapsedMs' => 3.0]),
        ]);
        $output = new ConsoleOutput(true);
        $guard = new UrlGuard($sitemap, ['shop.test'], new FakeHostResolver(['shop.test' => ['172.20.0.4']]));
        $result = (new Crawler($client, new XmlSitemapParser(), 2, $guard, $output))->crawl($sitemap);

        self::assertSame([$sitemap, $listed, $failed], $client->requests);
        self::assertSame([$listed], $result->visited);
        self::assertSame([$failed], $result->skipped);
        self::assertSame([
            '[OK] SITEMAP https://shop.test/sitemap.xml HTTP 200 1.00 ms',
            '[OK] PAGE https://shop.test/listed HTTP 200 2.00 ms',
            '[FAIL] PAGE https://shop.test/failed HTTP 503 3.00 ms',
        ], $output->getCaptured());
    }

    public function testCrawlerHandlesSitemapIndexesAndMissingResponses(): void
    {
        $index = 'https://shop.test/index.xml';
        $child = 'https://shop.test/child.xml';
        $page = 'https://shop.test/page';
        $missing = 'https://shop.test/missing';
        $client = new FakeHttpClient([
            $index => FetchResult::create($index, ['httpCode' => 200, 'body' => '<sitemapindex><sitemap><loc>' . $child . '</loc></sitemap><sitemap><loc>' . $child . '</loc></sitemap></sitemapindex>', 'elapsedMs' => 1.0]),
            $child => FetchResult::create($child, ['httpCode' => 200, 'body' => '<urlset><url><loc>' . $page . '</loc></url><url><loc>' . $missing . '</loc></url></urlset>', 'elapsedMs' => 1.0]),
            $page => FetchResult::create($page, ['httpCode' => 200, 'body' => 'page', 'elapsedMs' => 1.0]),
        ]);
        $output = new ConsoleOutput(true);
        $guard = new UrlGuard($index, ['shop.test'], new FakeHostResolver(['shop.test' => ['172.20.0.4']]));
        $result = (new Crawler($client, new XmlSitemapParser(), 1, $guard, $output))->crawl($index);

        self::assertSame([$page], $result->visited);
        self::assertSame([$missing], $result->skipped);
        self::assertSame([$index, $child, $page, $missing], $client->requests);
        self::assertContains('[FAIL] PAGE ' . $missing . ' HTTP 0', $output->getCaptured());
    }

    public function testFacadeValidatesSitemapAndThreadCount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlFetcher('not-a-url');
    }

    public function testFacadeCachesResultAndRejectsInvalidThreads(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UrlFetcher('https://shop.test/sitemap.xml', 0);
    }

    public function testFacadeRunsSitemapAndCliAcceptsOneOrTwoArguments(): void
    {
        $sitemap = 'https://shop.test/sitemap.xml';
        $client = new FakeHttpClient([
            $sitemap => FetchResult::create($sitemap, ['httpCode' => 200, 'body' => '<urlset/>', 'elapsedMs' => 0.1]),
        ]);
        $output = new ConsoleOutput(true);
        $fetcher = new UrlFetcher(
            $sitemap,
            2,
            $client,
            $output,
            ['shop.test'],
            new FakeHostResolver(['shop.test' => ['172.20.0.4']])
        );
        self::assertSame([], $fetcher->getFetchedUrls());
        self::assertSame([], $fetcher->getFailedUrls());

        self::assertSame(0, Magento2CacheWarmerCommand::run([$sitemap, '2'], $fetcher, $output));
        self::assertSame(1, Magento2CacheWarmerCommand::run([]));
        self::assertSame(1, Magento2CacheWarmerCommand::run([$sitemap, '0']));
        self::assertSame(1, Magento2CacheWarmerCommand::run(['https://shop.test', $sitemap]));
        self::assertSame(1, Magento2CacheWarmerCommand::run([$sitemap, '--debug']));
        $captured = $output->getCaptured();
        self::assertStringContainsString('Finished:', (string) end($captured));
    }

    public function testUrlGuardBlocksDangerousAndExternalDestinations(): void
    {
        $guard = new UrlGuard(
            'https://shop.test/sitemap.xml',
            [],
            new FakeHostResolver([
                'shop.test' => ['93.184.216.34'],
                'internal.test' => ['10.0.0.4'],
            ])
        );

        self::assertTrue($guard->isAllowed('https://shop.test/page'));
        self::assertFalse($guard->isAllowed('http://shop.test/page'));
        self::assertFalse($guard->isAllowed('https://other.test/page'));
        self::assertFalse($guard->isAllowed('file:///etc/passwd'));
        self::assertFalse($guard->isAllowed('https://user:pass@shop.test/page'));
        self::assertFalse($guard->isAllowed('https://internal.test/page'));
        self::assertSame(
            'https://shop.test/next',
            $guard->resolveLocation('https://shop.test/page', '/next')
        );
        $redirect = $guard->resolveLocation('https://shop.test/page', 'https://other.test/redirect');
        self::assertIsString($redirect);
        self::assertFalse($guard->isAllowed($redirect));
    }

    public function testUrlGuardAllowsOnlyExplicitTrustedPrivateOrigin(): void
    {
        $resolver = new FakeHostResolver(['shop.test' => ['172.20.0.4']]);
        $thrown = false;
        try {
            new UrlGuard('https://shop.test/sitemap.xml', [], $resolver);
        } catch (UrlSecurityException) {
            $thrown = true;
        }
        self::assertTrue($thrown);

        $trusted = new UrlGuard('https://shop.test/sitemap.xml', ['shop.test'], $resolver);
        self::assertTrue($trusted->isAllowed('https://shop.test/page'));
    }

    public function testCrawlerDoesNotSendBlockedSitemapUrlsToHttpClient(): void
    {
        $sitemap = 'https://shop.test/sitemap.xml';
        $safe = 'https://shop.test/safe';
        $malicious = 'https://other.test/secret';
        $client = new FakeHttpClient([
            $sitemap => FetchResult::create($sitemap, ['httpCode' => 200, 'body' => '<urlset><url><loc>' . $safe . '</loc></url><url><loc>' . $malicious . '</loc></url></urlset>', 'elapsedMs' => 1.0]),
            $safe => FetchResult::create($safe, ['httpCode' => 200, 'body' => 'safe', 'elapsedMs' => 1.0]),
        ]);
        $output = new ConsoleOutput(true);
        $guard = new UrlGuard($sitemap, ['shop.test'], new FakeHostResolver(['shop.test' => ['172.20.0.4']]));
        $result = (new Crawler($client, new XmlSitemapParser(), 2, $guard, $output))->crawl($sitemap);

        self::assertSame([$sitemap, $safe], $client->requests);
        self::assertSame([$safe], $result->visited);
        self::assertSame([$malicious], $result->skipped);
        self::assertContains('[BLOCKED] PAGE ' . $malicious, $output->getCaptured());
    }

    public function testTerminalOutputRemovesControlCharacters(): void
    {
        self::assertSame('url[31mnext', TerminalSanitizer::sanitize("url\x1b[31mnext\n"));
        self::assertSame('ação', TerminalSanitizer::sanitize("a\u{0080}ção"));
        $output = new ConsoleOutput(true);
        $output->writeln("safe\r\n\x1b[2J");
        self::assertSame(['safe[2J'], $output->getCaptured());
    }

    public function testCliRejectsMalformedTrustedPrivateHostConfig(): void
    {
        putenv('CACHE_WARMER_TRUSTED_PRIVATE_HOSTS=shop.test,,other.test');
        try {
            self::assertSame(1, Magento2CacheWarmerCommand::run(['https://shop.test/sitemap.xml']));
        } finally {
            putenv('CACHE_WARMER_TRUSTED_PRIVATE_HOSTS');
        }
    }

    public function testFetchResultAcceptsAnySuccessfulHttpStatus(): void
    {
        self::assertTrue(FetchResult::create('u', ['httpCode' => 204, 'body' => '', 'elapsedMs' => 0.0])->isSuccessful());
        self::assertTrue(FetchResult::create('u', ['httpCode' => 200, 'body' => '', 'elapsedMs' => 0.0])->isSuccessful());
        self::assertFalse(FetchResult::create('u', ['httpCode' => 500, 'body' => 'x', 'elapsedMs' => 0.0])->isSuccessful());
    }

    public function testCurlClientHandlesMalformedUrlWithoutAborting(): void
    {
        $guard = new UrlGuard(
            'https://shop.test/sitemap.xml',
            [],
            new FakeHostResolver(['shop.test' => ['93.184.216.34']])
        );
        $result = (new CurlMultiHttpClient($guard))->fetchMultiple(['not a url']);
        self::assertSame(0, $result['not a url']->httpCode);
    }

    public function testCurlClientRejectsNonHttpSchemes(): void
    {
        $guard = new UrlGuard(
            'https://shop.test/sitemap.xml',
            [],
            new FakeHostResolver(['shop.test' => ['93.184.216.34']])
        );
        $result = (new CurlMultiHttpClient($guard))->fetchMultiple(['file:///etc/passwd']);
        self::assertSame(0, $result['file:///etc/passwd']->httpCode);
        self::assertNull($result['file:///etc/passwd']->body);
    }
}
