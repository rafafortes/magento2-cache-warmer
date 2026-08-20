<?php

declare(strict_types=1);

namespace Magento2CacheWarmer\Security;

/** Enforces same-origin HTTP(S) access and blocks SSRF destinations. */
final class UrlGuard
{
    /** @var list<string> */
    private array $trustedPrivateHosts;

    /** @var array<string, list<string>> */
    private array $resolvedAddresses = [];

    private string $originHost;
    private int $originPort;
    private string $originScheme;

    /**
     * @param list<string> $trustedPrivateHosts Exact host names allowed to resolve to private IPs.
     */
    public function __construct(
        string $originUrl,
        array $trustedPrivateHosts = [],
        ?HostResolverInterface $resolver = null
    ) {
        $parts = $this->parseHttpUrl($originUrl);
        $this->originScheme = strtolower((string) $parts['scheme']);
        $this->originHost = $this->normalizeHost((string) $parts['host']);
        $this->originPort = $this->port($parts);
        $this->resolver = $resolver ?? new SystemHostResolver();
        $this->trustedPrivateHosts = $this->normalizeTrustedHosts($trustedPrivateHosts);
        $this->validate($originUrl);
    }

    private HostResolverInterface $resolver;

    /** @return list<string> Allowed resolved IP addresses for cURL pinning. */
    public function validate(string $url): array
    {
        $parts = $this->parseHttpUrl($url);
        $host = $this->normalizeHost((string) $parts['host']);
        $port = $this->port($parts);
        if ((string) $parts['scheme'] !== $this->originScheme || $host !== $this->originHost || $port !== $this->originPort) {
            throw new UrlSecurityException('URL scheme, host or port is outside the sitemap origin.');
        }

        $key = $host . ':' . $port;
        if (!isset($this->resolvedAddresses[$key])) {
            $addresses = $this->resolver->resolve($host);
            $this->assertAddresses($host, $addresses);
            $this->resolvedAddresses[$key] = $addresses;
        }

        return $this->resolvedAddresses[$key];
    }

    public function isAllowed(string $url): bool
    {
        try {
            $this->validate($url);
            return true;
        } catch (UrlSecurityException) {
            return false;
        }
    }

    /** @return list<string> CURLOPT_RESOLVE entries. */
    public function resolveEntries(string $url): array
    {
        $parts = $this->parseHttpUrl($url);
        $host = $this->normalizeHost((string) $parts['host']);
        $port = $this->port($parts);
        $entries = [];
        foreach ($this->validate($url) as $address) {
            $entries[] = sprintf('%s:%d:%s', $host, $port, $address);
        }

        return $entries;
    }

    public function resolveLocation(string $baseUrl, string $location): ?string
    {
        $location = trim($location);
        if ($location === '') {
            return null;
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null || str_starts_with($location, '//')) {
            if (str_starts_with($location, '//')) {
                $scheme = (string) parse_url($baseUrl, PHP_URL_SCHEME);
                return $scheme . ':' . $location;
            }
            return $location;
        }

        $base = $this->parseHttpUrl($baseUrl);
        $scheme = (string) $base['scheme'];
        $host = (string) $base['host'];
        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $path = (string) ($base['path'] ?? '/');
        if (str_starts_with($location, '?')) {
            return $scheme . '://' . $host . $port . $path . $location;
        }
        if (str_starts_with($location, '/')) {
            $resolvedPath = $location;
        } else {
            $directory = str_ends_with($path, '/') ? $path : rtrim(dirname($path), '/') . '/';
            $resolvedPath = $directory . $location;
        }

        return $scheme . '://' . $host . $port . '/' . ltrim($resolvedPath, '/');
    }

    /** @return array{scheme: string, host: string, port?: int, path?: string, user?: string, pass?: string} */
    private function parseHttpUrl(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new UrlSecurityException('URL could not be parsed.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new UrlSecurityException('Only HTTP and HTTPS URLs are allowed.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UrlSecurityException('URLs with credentials are not allowed.');
        }
        $parts['scheme'] = $scheme;
        $parts['host'] = $host;

        return $parts;
    }

    /** @param array{scheme: string, port?: int} $parts */
    private function port(array $parts): int
    {
        return isset($parts['port']) ? (int) $parts['port'] : ($parts['scheme'] === 'https' ? 443 : 80);
    }

    private function normalizeHost(string $host): string
    {
        return strtolower(rtrim($host, '.'));
    }

    /**
     * @param list<string> $hosts
     * @return list<string>
     */
    private function normalizeTrustedHosts(array $hosts): array
    {
        $normalized = [];
        foreach ($hosts as $host) {
            if (trim($host) === '') {
                throw new UrlSecurityException('Trusted private hosts must be non-empty strings.');
            }
            $candidate = $this->normalizeHost(trim($host));
            if (strpbrk($candidate, '/?#@ ') !== false || str_contains($candidate, ':')) {
                throw new UrlSecurityException('Trusted private hosts must contain host names only.');
            }
            $normalized[] = $candidate;
        }

        return array_values(array_unique($normalized));
    }

    /** @param list<string> $addresses */
    private function assertAddresses(string $host, array $addresses): void
    {
        if ($addresses === []) {
            throw new UrlSecurityException('The URL host did not resolve to an IP address.');
        }
        if (in_array($host, $this->trustedPrivateHosts, true)) {
            return;
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new UrlSecurityException('The URL resolves to a private or reserved IP address.');
            }
        }
    }
}
