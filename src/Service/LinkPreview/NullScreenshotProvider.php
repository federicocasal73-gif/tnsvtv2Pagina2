<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

/**
 * No-op screenshot provider used as the default. The system relies on OpenGraph,
 * Twitter Cards, HTML metadata + favicon to render preview cards.
 *
 * Future: implement ScreenshotProviderInterface with Playwright / Puppeteer /
 * Screenshot API and register it in services.yaml to enable server-side capture.
 */
final class NullScreenshotProvider implements ScreenshotProviderInterface
{
    public function capture(string $url): ?string
    {
        return null;
    }

    public function isEnabled(): bool
    {
        return false;
    }
}