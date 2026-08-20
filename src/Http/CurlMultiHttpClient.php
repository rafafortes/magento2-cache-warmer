<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Http;

use Magento2CacheWarmer\Security\UrlGuard;
use Magento2CacheWarmer\Security\UrlSecurityException;

/** Concurrent fetcher built on ext-curl's curl_multi API. */
final class CurlMultiHttpClient implements HttpClientInterface
{
    /**
     * @param float $timeoutSeconds Per-request total timeout.
     * @param float $connectTimeoutSeconds Per-request connect timeout.
     * @param bool $tlsPeerVerification Whether to verify TLS peer certificates.
     */
    public function __construct(
        private UrlGuard $urlGuard,
        private float $timeoutSeconds = 10.0,
        private float $connectTimeoutSeconds = 5.0,
        private bool $tlsPeerVerification = true
    ) {
    }

    /** @param list<string> $urls */
    public function fetchMultiple(array $urls, ?callable $onComplete = null): array
    {
        $multiHandle = \curl_multi_init();
        /** @var array<string, \CurlHandle> */
        $handles = [];
        /** @var array<string, FetchResult> */
        $results = [];
        /** @var array<string, bool> */
        $redirectViolations = [];

        try {
            foreach ($urls as $url) {
                try {
                    $resolveEntries = $this->urlGuard->resolveEntries($url);
                } catch (UrlSecurityException) {
                    $result = FetchResult::create($url, ['httpCode' => 0, 'body' => null, 'elapsedMs' => 0.0]);
                    $results[$url] = $result;
                    if ($onComplete !== null) {
                        $onComplete($result);
                    }
                    continue;
                }

                $handle = \curl_init($url);
                if ($handle === false) {
                    $result = FetchResult::create($url, ['httpCode' => 0, 'body' => null, 'elapsedMs' => 0.0]);
                    $results[$url] = $result;
                    if ($onComplete !== null) {
                        $onComplete($result);
                    }
                    continue;
                }
                \curl_setopt($handle, \CURLOPT_RETURNTRANSFER, true);
                \curl_setopt($handle, \CURLOPT_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS);
                \curl_setopt($handle, \CURLOPT_REDIR_PROTOCOLS, \CURLPROTO_HTTP | \CURLPROTO_HTTPS);
                \curl_setopt($handle, \CURLOPT_SSL_VERIFYPEER, $this->tlsPeerVerification);
                \curl_setopt($handle, \CURLOPT_SSL_VERIFYHOST, $this->tlsPeerVerification ? 2 : 0);
                \curl_setopt($handle, \CURLOPT_FOLLOWLOCATION, true);
                \curl_setopt($handle, \CURLOPT_MAXREDIRS, 5);
                if ($resolveEntries !== []) {
                    \curl_setopt($handle, \CURLOPT_RESOLVE, $resolveEntries);
                }
                \curl_setopt($handle, \CURLOPT_HEADERFUNCTION, function ($redirectHandle, string $header) use ($url, &$redirectViolations): int {
                    if (stripos($header, 'Location:') === 0) {
                        $location = trim(substr($header, strlen('Location:')));
                        try {
                            $baseUrl = (string) \curl_getinfo($redirectHandle, \CURLINFO_EFFECTIVE_URL);
                            $resolved = $this->urlGuard->resolveLocation($baseUrl !== '' ? $baseUrl : $url, $location);
                            if ($resolved === null) {
                                throw new UrlSecurityException('Redirect location is invalid.');
                            }
                            $this->urlGuard->validate($resolved);
                        } catch (UrlSecurityException) {
                            $redirectViolations[$url] = true;
                            return 0;
                        }
                    }

                    return strlen($header);
                });
                \curl_setopt($handle, \CURLOPT_TIMEOUT_MS, max(1, (int) round($this->timeoutSeconds * 1000)));
                \curl_setopt($handle, \CURLOPT_CONNECTTIMEOUT_MS, max(1, (int) round($this->connectTimeoutSeconds * 1000)));
                \curl_multi_add_handle($multiHandle, $handle);
                $handles[$url] = $handle;
            }

            $active = 0;
            do {
                $status = \curl_multi_exec($multiHandle, $active);
                while (($info = \curl_multi_info_read($multiHandle)) !== false) {
                    $handle = $info['handle'];
                    foreach ($handles as $url => $candidate) {
                        if ($candidate !== $handle || isset($results[$url])) {
                            continue;
                        }
                        $result = $this->createResult($url, $handle, $redirectViolations[$url] ?? false);
                        $results[$url] = $result;
                        if ($onComplete !== null) {
                            $onComplete($result);
                        }
                        break;
                    }
                }
                if ($active > 0 && $status === \CURLM_OK) {
                    if (\curl_multi_select($multiHandle) === -1) {
                        usleep(1000);
                    }
                }
            } while ($active > 0 && $status === \CURLM_OK);

            foreach ($handles as $url => $handle) {
                if (!isset($results[$url])) {
                    $result = $this->createResult($url, $handle, $redirectViolations[$url] ?? false);
                    $results[$url] = $result;
                    if ($onComplete !== null) {
                        $onComplete($result);
                    }
                }
            }
        } finally {
            foreach ($handles as $handle) {
                \curl_multi_remove_handle($multiHandle, $handle);
                \curl_close($handle);
            }
            \curl_multi_close($multiHandle);
        }

        return $results;
    }

    private function createResult(string $url, \CurlHandle $handle, bool $redirectViolation): FetchResult
    {
        $rawBody = \curl_multi_getcontent($handle);
        $body = is_string($rawBody) ? $rawBody : null;
        $effectiveUrl = (string) \curl_getinfo($handle, \CURLINFO_EFFECTIVE_URL);
        if ($effectiveUrl !== '' && !$this->urlGuard->isAllowed($effectiveUrl)) {
            $redirectViolation = true;
        }
        $httpCode = $redirectViolation ? 0 : (int) \curl_getinfo($handle, \CURLINFO_HTTP_CODE);
        $elapsedMs = round((float) \curl_getinfo($handle, \CURLINFO_TOTAL_TIME) * 1000.0, 2);

        return FetchResult::create($url, [
            'httpCode' => $body === null ? 0 : $httpCode,
            'body' => $body,
            'elapsedMs' => $elapsedMs,
        ]);
    }
}
