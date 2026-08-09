<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Favicon resolver: tries /favicon.ico + Google s2 API as universal fallback.
 *
 * Returns the URL to use (local cache path or remote URL). Does NOT download
 * the image — that's done lazily by the browser via <img>.
 */
final class FaviconService
{
    private const GOOGLE_S2_BASE = 'https://www.google.com/s2/favicons';
    private const DEFAULT_FAVICON = '/uploads/link-previews/favicons/default-link.svg';

    public function __construct(
        private readonly string $uploadDir,
        private readonly ?HttpClientInterface $http = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0775, true);
        }
    }

    /**
     * Returns the Google s2 favicon URL for the given domain (always works without auth).
     */
    public function googleS2Url(string $domain, int $size = 64): string
    {
        $domain = trim(strtolower($domain));
        if ($domain === '' || !preg_match('/^[a-z0-9.\-:]+$/', $domain)) {
            return '';
        }
        return sprintf('%s?domain=%s&sz=%d', self::GOOGLE_S2_BASE, urlencode($domain), $size);
    }

    /**
     * Returns a local fallback SVG when nothing else is available.
     */
    public function defaultLocalIcon(): string
    {
        return self::DEFAULT_FAVICON;
    }

    /**
     * Extract the favicon URL declared in the HTML <link rel="icon"> (if any).
     * Returns null if no link tag is found.
     */
    public function extractFromHtml(string $html, string $baseUrl): ?string
    {
        if (trim($html) === '') {
            return null;
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);
        foreach ($xpath->query('//link[@rel="icon" or @rel="shortcut icon" or @rel="apple-touch-icon" or @rel="apple-touch-icon-precomposed"]') as $link) {
            /** @var \DOMElement $link */
            $href = $link->getAttribute('href');
            if ($href === '') {
                continue;
            }
            return $this->resolveUrl($href, $baseUrl);
        }

        return null;
    }

    /**
     * Build the final favicon URL for a LinkPreview:
     *   1. Site-specific local SVG (TradingView, YouTube, etc.) if matched.
     *   2. HTML-declared favicon if found.
     *   3. Google s2 fallback.
     */
    public function resolveForPreview(?string $htmlIcon, string $domain): string
    {
        $local = $this->localLogoForDomain($domain);
        if ($local !== null) {
            return $local;
        }
        if ($htmlIcon !== null && $htmlIcon !== '') {
            return $htmlIcon;
        }
        $google = $this->googleS2Url($domain);
        return $google !== '' ? $google : $this->defaultLocalIcon();
    }

    private function localLogoForDomain(string $domain): ?string
    {
        $host = strtolower($domain);
        $map = [
            'tradingview.com' => '/uploads/link-previews/favicons/tradingview-logo.svg',
            'youtube.com' => '/uploads/link-previews/favicons/youtube-logo.svg',
            'youtu.be' => '/uploads/link-previews/favicons/youtube-logo.svg',
            'github.com' => '/uploads/link-previews/favicons/github-logo.svg',
            'spotify.com' => '/uploads/link-previews/favicons/spotify-logo.svg',
            'instagram.com' => '/uploads/link-previews/favicons/instagram-logo.svg',
        ];
        foreach ($map as $key => $path) {
            if ($host === $key || str_ends_with($host, '.' . $key)) {
                return $path;
            }
        }
        return null;
    }

    private function resolveUrl(string $url, string $base): string
    {
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }
        $baseParts = parse_url($base);
        if ($baseParts === false) {
            return $url;
        }
        $origin = ($baseParts['scheme'] ?? 'https') . '://' . ($baseParts['host'] ?? '');
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $basePath = $baseParts['path'] ?? '/';
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);
        return $origin . $basePath . $url;
    }
}