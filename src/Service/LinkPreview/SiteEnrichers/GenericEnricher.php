<?php

declare(strict_types=1);

namespace App\Service\LinkPreview\SiteEnrichers;

/**
 * Stub no-op enricher used as a default fallback. Real enrichers (Tradingview,
 * YouTube, Spotify, etc.) will be added in Session 2 with full logic.
 *
 * Returning supports()=false means this enricher never applies.
 */
final class GenericEnricher implements SiteEnricherInterface
{
    public function supports(string $url, string $host): bool
    {
        return false;
    }

    public function enrich(string $url, array $baseMetadata): array
    {
        return ['kind' => 'generic'];
    }
}