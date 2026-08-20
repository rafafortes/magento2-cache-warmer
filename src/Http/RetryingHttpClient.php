<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Http;

/** Retries transient HTTP and transport failures without duplicating output. */
final class RetryingHttpClient implements HttpClientInterface
{
    private const DEFAULT_RETRY_DELAY_MS = 250;

    public function __construct(
        private HttpClientInterface $client,
        private int $maxRetries,
        private int $retryDelayMs = self::DEFAULT_RETRY_DELAY_MS
    ) {
        if ($maxRetries < 0) {
            throw new \InvalidArgumentException('The retry count must not be negative.');
        }
        if ($retryDelayMs < 0) {
            throw new \InvalidArgumentException('The retry delay must not be negative.');
        }
    }

    /** @param list<string> $urls */
    public function fetchMultiple(array $urls, ?callable $onComplete = null): array
    {
        $pending = $urls;
        /** @var array<string, FetchResult> $results */
        $results = [];
        /** @var array<string, float> $elapsedByUrl */
        $elapsedByUrl = [];
        $attempt = 0;

        while ($pending !== []) {
            $responses = $this->client->fetchMultiple($pending);
            $next = [];
            foreach ($pending as $url) {
                $response = $responses[$url] ?? null;
                if ($response === null) {
                    if ($attempt < $this->maxRetries) {
                        $next[] = $url;
                    }
                    continue;
                }

                $elapsedByUrl[$url] = ($elapsedByUrl[$url] ?? 0.0) + $response->elapsedMs;
                $response = $this->withElapsed($response, $elapsedByUrl[$url]);
                if ($this->isRetryable($response) && $attempt < $this->maxRetries) {
                    $next[] = $url;
                    continue;
                }

                $results[$url] = $response;
                if ($onComplete !== null) {
                    $onComplete($response);
                }
            }

            if ($next === []) {
                break;
            }
            $this->waitBeforeRetry($attempt);
            $pending = $next;
            $attempt++;
        }

        return $results;
    }

    private function isRetryable(FetchResult $response): bool
    {
        return $response->httpCode === 0
            || $response->httpCode === 408
            || $response->httpCode === 425
            || $response->httpCode === 429
            || ($response->httpCode >= 500 && $response->httpCode <= 504);
    }

    private function waitBeforeRetry(int $attempt): void
    {
        if ($this->retryDelayMs === 0) {
            return;
        }

        $multiplier = 2 ** min($attempt, 5);
        usleep($this->retryDelayMs * $multiplier * 1000);
    }

    private function withElapsed(FetchResult $response, float $elapsedMs): FetchResult
    {
        return FetchResult::create($response->url, [
            'httpCode' => $response->httpCode,
            'body' => $response->body,
            'elapsedMs' => round($elapsedMs, 2),
        ]);
    }
}
