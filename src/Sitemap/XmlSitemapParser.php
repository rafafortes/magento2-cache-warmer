<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Sitemap;

/**
 * Extracts <loc> entries from a sitemap.xml document.
 */
final class XmlSitemapParser
{
    public function isIndex(string $xml): bool
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = simplexml_load_string($xml);
            return $doc !== false && ($doc->xpath('//*[local-name() = "sitemap"]') ?: []) !== [];
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /**
     * @return list<string>
     */
    public function parse(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            \libxml_clear_errors();
            $doc = \simplexml_load_string($xml);
            if ($doc === false) {
                $error = \libxml_get_last_error();
                $message = $error !== false ? $error->message : 'unknown parse error';
                throw new SitemapParseException(sprintf('Sitemap XML could not be parsed: %s', trim($message)));
            }

            $urls = [];
            $locations = $this->isIndex($xml)
                ? ($doc->xpath('/*[local-name() = "sitemapindex"]/*[local-name() = "sitemap"]/*[local-name() = "loc"]') ?: [])
                : ($doc->xpath('/*[local-name() = "urlset"]/*[local-name() = "url"]/*[local-name() = "loc"]') ?: []);
            foreach ($locations as $location) {
                $loc = trim((string) $location);
                if ($loc !== '') {
                    $urls[] = $loc;
                }
            }

            return $urls;
        } finally {
            \libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
