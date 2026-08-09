<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

/**
 * Strategy interface for fallback screenshot generation when OpenGraph / Twitter
 * Cards / HTML do not provide an image.
 *
 * The default implementation is NullScreenshotProvider (no-op) so the system
 * works without external dependencies. A future PlaywrightScreenshotProvider can
 * be implemented and registered in services.yaml without touching LinkPreviewService.
 */
interface ScreenshotProviderInterface
{
    /**
     * Capture a screenshot of the given URL and return the public URL where
     * the image is served. Return null if capture is unavailable or failed.
     */
    public function capture(string $url): ?string;

    /**
     * Whether this provider is enabled (e.g. Playwright installed).
     */
    public function isEnabled(): bool;
}