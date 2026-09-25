<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the Service Worker script.
 *
 * CACHE_VERSION is derived from APP_VERSION so every production deployment
 * automatically busts all client-side caches (no manual version bump needed).
 *
 * The old static /public/sw.js was replaced by this route because Apache
 * serves physical files before the front controller, which would shadow the
 * dynamic version. Keep the file OUT of public/.
 *
 * PRECACHE_URLS are read at runtime from public/assets/manifest.json so the
 * SW caches the actual content-hashed filenames (otherwise the cache.addAll()
 * fails silently with 404 and the SW ships with an empty static cache).
 */
class ServiceWorkerController extends AbstractController
{
    #[Route('/sw.js', name: 'service_worker', methods: ['GET'])]
    public function index(): Response
    {
        // Read APP_VERSION via Symfony's Dotenv-loaded super-globals.
        // getenv() returns false here because the LiteSpeed front-end does
        // not export env vars to PHP-FPM. $_ENV / $_SERVER are populated
        // by Dotenv::bootEnv() at app boot.
        $appVersion = (string) ($_ENV['APP_VERSION'] ?? $_SERVER['APP_VERSION'] ?? getenv('APP_VERSION') ?: '2.0.0');

        $content = $this->renderView('sw.js.twig', [
            'cache_version' => $appVersion,
            'precache_urls' => $this->loadPrecacheUrls(),
        ]);

        return new Response($content, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Reads the asset-mapper manifest and returns the URLs of all static
     * assets that should be precached (CSS, JS controllers, third-party libs,
     * JS modules). Falls back to an empty array if the manifest is missing
     * (fresh checkout without `asset-map:compile`), in which case the SW
     * still works as network-first with runtime caching.
     *
     * @return string[]
     */
    private function loadPrecacheUrls(): array
    {
        $manifestPath = $this->getParameter('kernel.project_dir') . '/public/assets/manifest.json';
        if (!is_file($manifestPath)) {
            return [];
        }

        try {
            $raw = file_get_contents($manifestPath);
            if ($raw === false) {
                return [];
            }
            $manifest = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($manifest)) {
            return [];
        }

        $urls = ['/manifest.json', '/favicon.svg', '/favicon.ico'];
        foreach ($manifest as $logical => $hashed) {
            if (!is_string($logical) || !is_string($hashed)) {
                continue;
            }
            // Only cache static, deterministic assets. Skip controllers
            // (loaded on-demand by Stimulus) and entrypoints (depend on
            // importmap which the browser fetches separately).
            if (str_starts_with($logical, 'styles/')
                || str_starts_with($logical, 'js/modules/')
                || str_starts_with($logical, 'third_party/')) {
                $urls[] = $hashed;
            }
        }

        return array_values(array_unique($urls));
    }
}