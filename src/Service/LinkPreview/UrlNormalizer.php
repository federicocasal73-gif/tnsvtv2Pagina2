<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

/**
 * SSRF-safe URL normalizer.
 *
 * - Validates scheme (http/https only).
 * - Blocks localhost, metadata endpoints, and known-bad hosts.
 * - Resolves DNS once and rejects private/loopback/link-local IPs.
 * - Re-checks DNS resolution to mitigate DNS rebinding.
 * - Normalizes URLs for stable cache hashing (lowercase host, strip tracking params, etc.).
 */
final class UrlNormalizer
{
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'fbclid', 'gclid', 'msclkid', 'mc_eid', 'mc_cid', 'yclid',
        '_ga', '_gl', 'ref', 'ref_src', 'ref_url',
    ];

    private const BLOCKED_HOSTS = [
        'localhost', 'ip6-localhost', 'ip6-loopback',
        'metadata.google.internal', 'metadata.goog',
    ];

    private const PRIVATE_CIDRS_V4 = [
        ['10.0.0.0', '255.0.0.0'],
        ['172.16.0.0', '255.240.0.0'],
        ['192.168.0.0', '255.255.0.0'],
        ['127.0.0.0', '255.0.0.0'],
        ['169.254.0.0', '255.255.0.0'],
        ['0.0.0.0', '255.0.0.0'],
        ['100.64.0.0', '255.192.0.0'],
        ['224.0.0.0', '240.0.0.0'],
    ];

    /**
     * Validate URL is safe to fetch AND return the normalized form.
     * @throws InvalidUrlException
     * @throws SsrfException
     */
    public function assertSafe(string $url): string
    {
        $normalized = $this->normalize($url);

        $parts = parse_url($normalized);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new InvalidUrlException('URL malformada');
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidUrlException(sprintf('scheme "%s" no permitido (solo http/https)', $scheme));
        }

        $host = strtolower($parts['host']);
        if ($host === '') {
            throw new InvalidUrlException('host vacío');
        }

        if ($this->isBlockedHost($host)) {
            throw new SsrfException(sprintf('host "%s" bloqueado por seguridad', $host));
        }

        // Resolve DNS once. If host is already an IP literal, skip resolution.
        $ips = $this->isIpLiteral($host) ? [$host] : $this->resolveAll($host);
        foreach ($ips as $ip) {
            if ($this->isPrivateIp($ip)) {
                throw new SsrfException(sprintf('IP privada detectada: %s', $ip));
            }
        }

        // DNS rebinding mitigation: re-resolve and compare.
        $ips2 = $this->isIpLiteral($host) ? [$host] : $this->resolveAll($host);
        if ($ips !== $ips2) {
            throw new SsrfException('DNS rebinding detectado');
        }

        return $normalized;
    }

    /**
     * Normalize a URL for stable hashing + cache lookups.
     * - Lowercase host.
     * - Strip default ports.
     * - Strip tracking params.
     * - Strip fragment.
     * - Strip trailing slash on path (unless root).
     */
    public function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;
        $userInfo = '';
        if (isset($parts['user'])) {
            $userInfo = $parts['user'];
            if (isset($parts['pass'])) {
                $userInfo .= ':' . $parts['pass'];
            }
            $userInfo .= '@';
        }

        // Strip default ports
        if ($port !== null) {
            if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
                $port = null;
            }
        }

        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
            $params = array_filter($params, static fn($key) => !in_array(strtolower($key), self::TRACKING_PARAMS, true), ARRAY_FILTER_USE_KEY);
            if ($params !== []) {
                ksort($params);
                $query = '?' . http_build_query($params);
            }
        }

        $portStr = $port !== null ? ':' . $port : '';

        return sprintf('%s://%s%s%s%s', $scheme, $userInfo, $host, $portStr, $path) . $query;
    }

    public function extractDomain(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return '';
        }
        $host = strtolower($parts['host']);
        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    public function isPrivateIp(string $ip): bool
    {
        // IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $lower = strtolower($ip);
            if ($lower === '::1' || $lower === '::') {
                return true;
            }
            if (str_starts_with($lower, 'fc') || str_starts_with($lower, 'fd')) {
                return true; // fc00::/7 unique local
            }
            if (str_starts_with($lower, 'fe8') || str_starts_with($lower, 'fe9') || str_starts_with($lower, 'fea') || str_starts_with($lower, 'feb')) {
                return true; // fe80::/10 link-local
            }
            // IPv4-mapped IPv6 (::ffff:127.0.0.1)
            if (preg_match('/^::ffff:([0-9.]+)$/i', $lower, $m)) {
                return $this->isPrivateIp($m[1]);
            }
            return false;
        }

        // IPv4
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($ip);
        if ($long === false) {
            return false;
        }
        foreach (self::PRIVATE_CIDRS_V4 as [$net, $mask]) {
            $netLong = ip2long($net);
            if ($netLong === false) {
                continue;
            }
            $maskLong = ip2long($mask);
            if ((($long & $maskLong) ^ ($netLong & $maskLong)) === 0) {
                return true;
            }
        }
        return false;
    }

    private function isBlockedHost(string $host): bool
    {
        $host = rtrim($host, '.');
        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return true;
        }
        if (str_ends_with($host, '.local') || str_ends_with($host, '.internal') || str_ends_with($host, '.localhost')) {
            return true;
        }
        return false;
    }

    private function isIpLiteral(string $host): bool
    {
        return (bool) filter_var($host, FILTER_VALIDATE_IP);
    }

    /**
     * @return string[] list of resolved IPs (IPv4 + IPv6 if available)
     */
    private function resolveAll(string $host): array
    {
        $ips = [];
        // A records
        $a = @gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }
        // AAAA records
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        // Fallback: gethostbyname (only IPv4)
        if ($ips === []) {
            $resolved = @gethostbyname($host);
            if ($resolved !== $host && filter_var($resolved, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $ips[] = $resolved;
            }
        }
        return array_values(array_unique($ips));
    }
}