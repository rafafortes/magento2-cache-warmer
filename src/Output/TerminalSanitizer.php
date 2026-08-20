<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Output;

final class TerminalSanitizer
{
    public static function sanitize(string $value): string
    {
        $asciiSafe = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        if ($asciiSafe === null) {
            return '';
        }

        $sanitized = preg_replace('/\p{Cc}/u', '', $asciiSafe);
        return $sanitized === null ? '' : $sanitized;
    }
}
