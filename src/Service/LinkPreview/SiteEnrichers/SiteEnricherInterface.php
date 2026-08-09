<?php

declare(strict_types=1);

namespace App\Service\LinkPreview\SiteEnrichers;

/**
 * Strategy interface for site-specific metadata enrichment.
 *
 * Examples: TradingView (extract ticker from ?symbol=OANDA:XAUUSD),
 * YouTube (extract videoId, channel), Spotify (extract track/album ID).
 *
 * Implementations are registered as services tagged with `app.link_preview.enricher`
 * (or injected manually) and iterated in LinkPreviewService::preview().
 */
interface SiteEnricherInterface
{
    /**
     * Does this enricher handle the given URL + host?
     */
    public function supports(string $url, string $host): bool;

    /**
     * Return enrichment data (e.g. {kind: 'tradingview', ticker: 'XAUUSD', ...}).
     * May also override title/description/image by returning those keys —
     * those overrides take precedence over MetadataExtractor results.
     *
     * @param array<string, mixed> $baseMetadata output of MetadataExtractor::extract()
     * @return array<string, mixed>
     */
    public function enrich(string $url, array $baseMetadata): array;
}