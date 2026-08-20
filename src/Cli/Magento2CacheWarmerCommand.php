<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Cli;

use Magento2CacheWarmer\Output\ConsoleOutput;
use Magento2CacheWarmer\Output\OutputInterface;
use Magento2CacheWarmer\Output\TerminalSanitizer;
use Magento2CacheWarmer\UrlFetcher;
use Magento2CacheWarmer\UrlFetcherInterface;

/** Thin command adapter; importing the library never executes a crawl. */
final class Magento2CacheWarmerCommand
{
    /** @param list<string> $arguments */
    public static function run(
        array $arguments,
        ?UrlFetcherInterface $fetcher = null,
        ?OutputInterface $output = null
    ): int {
        if (count($arguments) < 1 || count($arguments) > 2) {
            fwrite(STDERR, "Usage: magento2-cache-warmer <sitemapUrl> [threads]\n");
            return 1;
        }

        $sitemapUrl = (string) $arguments[0];
        $threads = 1;
        if (isset($arguments[1])) {
            if (!ctype_digit($arguments[1]) || (int) $arguments[1] < 1) {
                fwrite(STDERR, "The thread count must be a positive integer.\n");
                return 1;
            }
            $threads = (int) $arguments[1];
        }

        try {
            $output ??= new ConsoleOutput();
            $trustedPrivateHosts = self::trustedPrivateHosts();
            $fetcher ??= new UrlFetcher(
                $sitemapUrl,
                $threads,
                null,
                $output,
                $trustedPrivateHosts
            );
            $started = microtime(true);
            $urls = $fetcher->getFetchedUrls();
            $skipped = $fetcher->getFailedUrls();
            $elapsed = microtime(true) - $started;
            $output->writeln(sprintf(
                'Finished: %d successful, %d failed, %dm %.2fs',
                count($urls),
                count($skipped),
                (int) floor($elapsed / 60),
                fmod($elapsed, 60.0)
            ));

            return 0;
        } catch (\Throwable $exception) {
            fwrite(STDERR, TerminalSanitizer::sanitize('Error: ' . $exception->getMessage()) . "\n");
            return 1;
        }
    }

    /** @return list<string> */
    private static function trustedPrivateHosts(): array
    {
        $value = getenv('CACHE_WARMER_TRUSTED_PRIVATE_HOSTS');
        if ($value === false || trim($value) === '') {
            return [];
        }

        $hosts = array_map('trim', explode(',', $value));
        if (in_array('', $hosts, true)) {
            throw new \InvalidArgumentException(
                'CACHE_WARMER_TRUSTED_PRIVATE_HOSTS must contain comma-separated host names.'
            );
        }

        return $hosts;
    }
}
