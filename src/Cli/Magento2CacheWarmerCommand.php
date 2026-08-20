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
        try {
            $options = self::parseArguments($arguments);
        } catch (\InvalidArgumentException $exception) {
            fwrite(STDERR, TerminalSanitizer::sanitize($exception->getMessage()) . "\n");
            fwrite(STDERR, self::usage());
            return 1;
        }

        if ($options === null) {
            fwrite(STDOUT, self::usage());
            return 0;
        }

        try {
            $output ??= new ConsoleOutput();
            $trustedPrivateHosts = self::trustedPrivateHosts();
            $fetcher ??= new UrlFetcher(
                $options['sitemap'],
                $options['threads'],
                null,
                $output,
                $trustedPrivateHosts,
                null,
                $options['retries']
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

    /**
     * @param list<string> $arguments
     * @return array{sitemap: string, threads: int, retries: int}|null
     */
    private static function parseArguments(array $arguments): ?array
    {
        if (in_array('--help', $arguments, true)) {
            return null;
        }
        if ($arguments === []) {
            throw new \InvalidArgumentException('A sitemap URL is required.');
        }

        $hasOptions = str_starts_with($arguments[0], '--');
        if (!$hasOptions) {
            return self::parseLegacyArguments($arguments);
        }

        $values = [];
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new \InvalidArgumentException('Options must use the --parameter=value format.');
            }
            [$name, $value] = explode('=', $argument, 2);
            if ($value === '') {
                throw new \InvalidArgumentException($name . ' must not be empty.');
            }
            $canonicalName = $name === '--retry' ? '--retries' : $name;
            if (!in_array($canonicalName, ['--sitemap', '--threads', '--retries'], true)) {
                throw new \InvalidArgumentException('Unknown option: ' . $name);
            }
            if (isset($values[$canonicalName])) {
                throw new \InvalidArgumentException('Option repeated: ' . $name);
            }
            $values[$canonicalName] = $value;
        }

        if (!isset($values['--sitemap'])) {
            throw new \InvalidArgumentException('The --sitemap option is required.');
        }

        return [
            'sitemap' => $values['--sitemap'],
            'threads' => self::positiveInteger($values['--threads'] ?? '1', 'thread count'),
            'retries' => self::nonNegativeInteger($values['--retries'] ?? '0', 'retry count'),
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array{sitemap: string, threads: int, retries: int}
     */
    private static function parseLegacyArguments(array $arguments): array
    {
        if (count($arguments) < 1 || count($arguments) > 2) {
            throw new \InvalidArgumentException('Expected a sitemap URL and optional thread count.');
        }

        return [
            'sitemap' => $arguments[0],
            'threads' => self::positiveInteger($arguments[1] ?? '1', 'thread count'),
            'retries' => 0,
        ];
    }

    private static function positiveInteger(string $value, string $name): int
    {
        if (!ctype_digit($value) || (int) $value < 1) {
            throw new \InvalidArgumentException('The ' . $name . ' must be a positive integer.');
        }

        return (int) $value;
    }

    private static function nonNegativeInteger(string $value, string $name): int
    {
        if (!ctype_digit($value)) {
            throw new \InvalidArgumentException('The ' . $name . ' must be a non-negative integer.');
        }

        return (int) $value;
    }

    private static function usage(): string
    {
        return "Usage: magento2-cache-warmer --sitemap=<url> [--threads=<positive-int>] [--retries=<non-negative-int>]\n"
            . "       magento2-cache-warmer <sitemapUrl> [threads]  (legacy format)\n";
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
