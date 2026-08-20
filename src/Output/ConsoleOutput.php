<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Output;

/**
 * Writes to STDOUT, mirroring the original script output.
 */
final class ConsoleOutput implements OutputInterface
{
    private bool $capture;

    /**
     * @var list<string>
     */
    private array $captured = [];

    public function __construct(bool $capture = false)
    {
        $this->capture = $capture;
    }

    public function writeln(string ...$lines): void
    {
        foreach ($lines as $line) {
            $line = TerminalSanitizer::sanitize($line);
            if ($this->capture) {
                $this->captured[] = $line;
            } else {
                echo $line, "\n";
            }
        }
    }

    public function dump(mixed $value): void
    {
        ob_start();
        print_r($value);
        $rendered = TerminalSanitizer::sanitize((string) ob_get_clean());
        if ($this->capture) {
            $this->captured[] = $rendered;
        } else {
            echo $rendered;
        }
    }

    /**
     * @return list<string>
     */
    public function getCaptured(): array
    {
        return $this->captured;
    }
}
