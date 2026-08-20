<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Output;

/**
 * Output abstraction so the crawler never talks to STDOUT directly.
 */
interface OutputInterface
{
    /** @param string ...$lines */
    public function writeln(string ...$lines): void;

    /**
     * @param mixed $value
     */
    public function dump(mixed $value): void;
}
