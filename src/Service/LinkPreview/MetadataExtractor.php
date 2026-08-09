<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

/**
 * Extracts OpenGraph / Twitter Cards / HTML / JSON-LD metadata from raw HTML.
 *
 * Priority cascade:
 *   1. OpenGraph (og:title, og:description, og:image, og:site_name, og:type, og:url)
 *   2. Twitter Cards (twitter:title, twitter:description, twitter:image, twitter:site)
 *   3. HTML (<title>, <meta name="description">)
 *   4. JSON-LD (schema.org) — supplements, does NOT override.
 */
final class MetadataExtractor
{
    /**
     * @return array{
     *   title: ?string,
     *   description: ?string,
     *   image: ?string,
     *   image_original: ?string,
     *   site_name: ?string,
     *   type: ?string,
     *   url: ?string,
     *   favicon_hint: ?string,
     *   jsonld_image: ?string,
     *   jsonld_type: ?string,
     *   source_priority: ?string,
     *   raw: array<string, mixed>
     * }
     */
    public function extract(string $html, string $baseUrl): array
    {
        $result = [
            'title' => null,
            'description' => null,
            'image' => null,
            'image_original' => null,
            'site_name' => null,
            'type' => null,
            'url' => null,
            'favicon_hint' => null,
            'jsonld_image' => null,
            'jsonld_type' => null,
            'source_priority' => null,
            'raw' => [],
        ];

        if (trim($html) === '') {
            return $result;
        }

        $maxHtmlSize = 1_000_000;
        if (strlen($html) > $maxHtmlSize) {
            $html = substr($html, 0, $maxHtmlSize);
        }

        $prev = libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $xpath = new \DOMXPath($dom);

        // === Priority 1: OpenGraph ===
        $ogTitle = $this->metaContent($xpath, 'property', 'og:title');
        $ogDesc = $this->metaContent($xpath, 'property', 'og:description');
        $ogImage = $this->metaContent($xpath, 'property', 'og:image');
        $ogSite = $this->metaContent($xpath, 'property', 'og:site_name');
        $ogType = $this->metaContent($xpath, 'property', 'og:type');
        $ogUrl = $this->metaContent($xpath, 'property', 'og:url');

        $result['raw']['og'] = array_filter([
            'title' => $ogTitle,
            'description' => $ogDesc,
            'image' => $ogImage,
            'site_name' => $ogSite,
            'type' => $ogType,
            'url' => $ogUrl,
        ]);

        // === Priority 2: Twitter Cards ===
        $twTitle = $this->metaContent($xpath, 'name', 'twitter:title');
        $twDesc = $this->metaContent($xpath, 'name', 'twitter:description');
        $twImage = $this->metaContent($xpath, 'name', 'twitter:image');
        $twSite = $this->metaContent($xpath, 'name', 'twitter:site');

        $result['raw']['twitter'] = array_filter([
            'title' => $twTitle,
            'description' => $twDesc,
            'image' => $twImage,
            'site' => $twSite,
        ]);

        // === Priority 3: HTML <title> + <meta name="description"> ===
        $titleNode = $xpath->query('//title')->item(0);
        $htmlTitle = $titleNode instanceof \DOMNode ? trim($titleNode->textContent) : null;
        $htmlDesc = $this->metaContent($xpath, 'name', 'description');

        // === Priority 4: JSON-LD (schema.org) ===
        $jsonldImage = null;
        $jsonldType = null;
        foreach ($xpath->query('//script[@type="application/ld+json"]') as $node) {
            $json = trim((string) $node->textContent);
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            // schema.org puede ser un objeto o un @graph con varios.
            $items = isset($decoded['@graph']) && is_array($decoded['@graph']) ? $decoded['@graph'] : [$decoded];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                if ($jsonldType === null && isset($item['@type'])) {
                    $jsonldType = (string) $item['@type'];
                }
                if ($jsonldImage === null) {
                    $img = $item['image'] ?? null;
                    if (is_string($img)) {
                        $jsonldImage = $img;
                    } elseif (is_array($img) && isset($img['url'])) {
                        $jsonldImage = (string) $img['url'];
                    }
                }
            }
            if ($jsonldImage !== null && $jsonldType !== null) {
                break;
            }
        }

        // === Favicon hint ===
        foreach ($xpath->query('//link[@rel="icon" or @rel="shortcut icon" or @rel="apple-touch-icon"]') as $link) {
            /** @var \DOMElement $link */
            $href = $link->getAttribute('href');
            if ($href !== '') {
                $result['favicon_hint'] = $this->resolveUrl($href, $baseUrl);
                break;
            }
        }

        // === Resolve cascade (priority order) ===
        if ($ogTitle || $ogDesc || $ogImage) {
            $result['title'] = $this->cleanText($ogTitle);
            $result['description'] = $this->cleanText($ogDesc);
            $result['image_original'] = $ogImage;
            $result['image'] = $ogImage ? $this->resolveUrl($ogImage, $baseUrl) : null;
            $result['site_name'] = $this->cleanText($ogSite);
            $result['type'] = $this->cleanText($ogType);
            $result['url'] = $ogUrl ? $this->resolveUrl($ogUrl, $baseUrl) : $baseUrl;
            $result['source_priority'] = 'opengraph';
        } elseif ($twTitle || $twDesc || $twImage) {
            $result['title'] = $this->cleanText($twTitle);
            $result['description'] = $this->cleanText($twDesc);
            $result['image_original'] = $twImage;
            $result['image'] = $twImage ? $this->resolveUrl($twImage, $baseUrl) : null;
            $result['site_name'] = $this->cleanText($twSite);
            $result['type'] = null;
            $result['url'] = $baseUrl;
            $result['source_priority'] = 'twitter_cards';
        } elseif ($htmlTitle || $htmlDesc) {
            $result['title'] = $this->cleanText($htmlTitle);
            $result['description'] = $this->cleanText($htmlDesc);
            $result['image'] = null;
            $result['site_name'] = null;
            $result['type'] = null;
            $result['url'] = $baseUrl;
            $result['source_priority'] = 'html';
        } else {
            $result['url'] = $baseUrl;
        }

        $result['jsonld_image'] = $jsonldImage ? $this->resolveUrl($jsonldImage, $baseUrl) : null;
        $result['jsonld_type'] = $jsonldType;

        return $result;
    }

    private function metaContent(\DOMXPath $xpath, string $attr, string $value): ?string
    {
        // Try both orders: @property/@content and @name/@content, with namespace support.
        $q = sprintf('//meta[@%s="%s"]', $attr, $value);
        $node = $xpath->query($q)->item(0);
        if (!$node instanceof \DOMElement) {
            // OpenGraph often uses <meta property="og:..."> but some sites use <meta name="og:...">.
            $altAttr = $attr === 'property' ? 'name' : 'property';
            $q2 = sprintf('//meta[@%s="%s"]', $altAttr, $value);
            $node = $xpath->query($q2)->item(0);
        }
        if (!$node instanceof \DOMElement) {
            return null;
        }
        $content = $node->getAttribute('content');
        return $content !== '' ? $content : null;
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

    private function cleanText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim($text);
        // Collapse whitespace.
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        // Trim if too long (cap at 500 for title, 1000 for description handled by caller).
        if (mb_strlen($text) > 500) {
            $text = mb_substr($text, 0, 500);
        }
        return $text !== '' ? $text : null;
    }
}