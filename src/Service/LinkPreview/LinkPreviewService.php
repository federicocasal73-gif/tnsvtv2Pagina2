<?php

declare(strict_types=1);

namespace App\Service\LinkPreview;

use App\Entity\LinkPreview;
use App\Repository\LinkPreviewRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Orquestador de Universal Link Preview.
 *
 * Flujo:
 *  1. Validar URL (SSRF guard via UrlNormalizer).
 *  2. Buscar en cache (link_previews table) por url_hash. Si fresh → return.
 *  3. Fetch HTML via HttpClient (timeout, max-size).
 *  4. Extraer metadata (OG → Twitter → HTML → JSON-LD) via MetadataExtractor.
 *  5. Aplicar SiteEnricherInterface chain (TradingView, YouTube, etc.).
 *  6. Resolver favicon (FaviconService).
 *  7. Persistir en DB con TTL configurable (LINK_PREVIEW_CACHE_TTL).
 *  8. Retornar LinkPreview entity.
 *
 * Si force=true, ignora cache y re-fetchea siempre.
 */
class LinkPreviewService
{
    private readonly int $cacheTtl;
    private readonly int $maxDownloadBytes;
    private readonly float $httpTimeout;

    /** @var iterable<\App\Service\LinkPreview\SiteEnrichers\SiteEnricherInterface> */
    private readonly iterable $enrichers;

    public function __construct(
        private readonly UrlNormalizer $normalizer,
        private readonly MetadataExtractor $extractor,
        private readonly FaviconService $faviconService,
        private readonly ScreenshotProviderInterface $screenshotProvider,
        private readonly LinkPreviewRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        int $cacheTtl = 86400,
        int $maxDownloadBytes = 2_097_152,
        float $httpTimeout = 5.0,
        ?iterable $enrichers = null,
    ) {
        $this->cacheTtl = max(60, $cacheTtl);
        $this->maxDownloadBytes = max(1024, $maxDownloadBytes);
        $this->httpTimeout = max(1.0, $httpTimeout);
        $this->enrichers = $enrichers ?? [];
    }

    /**
     * Get a preview for the URL. Returns the persisted LinkPreview entity.
     * @throws InvalidUrlException|SsrfException
     */
    public function preview(string $url, bool $force = false): LinkPreview
    {
        $normalized = $this->normalizer->assertSafe($url);
        $hash = $this->hash($normalized);

        if (!$force) {
            $cached = $this->repository->findFreshByHash($hash);
            if ($cached !== null) {
                return $cached;
            }
        }

        $existing = $this->repository->findByHash($hash);
        return $this->fetchAndPersist($normalized, $hash, $existing);
    }

    private function fetchAndPersist(string $normalized, string $hash, ?LinkPreview $preview): LinkPreview
    {
        $html = '';
        $mime = null;
        $fetchError = null;

        try {
            $response = $this->http->request('GET', $normalized, [
                'timeout' => $this->httpTimeout,
                'max_duration' => $this->httpTimeout,
                'headers' => [
                    'User-Agent' => 'TNSVT-LinkPreview/1.0 (+https://tnsvt.com)',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
                'max_redirects' => 5,
            ]);
            $status = $response->getStatusCode();
            if ($status < 200 || $status >= 400) {
                throw new \RuntimeException('HTTP ' . $status);
            }
            // Enforce max size by streaming chunks.
            $size = 0;
            $chunks = [];
            foreach ($this->http->stream($response) as $chunk) {
                $size += strlen($chunk->getContent());
                if ($size > $this->maxDownloadBytes) {
                    throw new \RuntimeException('Tamaño excedido (' . $this->maxDownloadBytes . ' bytes)');
                }
                $chunks[] = $chunk->getContent();
            }
            $html = implode('', $chunks);
            $mime = $response->getHeaders()['content-type'][0] ?? null;
        } catch (\Throwable $e) {
            $fetchError = $e->getMessage();
            $this->logger->info('[LinkPreview] fetch failed', ['url' => $normalized, 'error' => $fetchError]);
        }

        if ($preview === null) {
            $preview = new LinkPreview();
            $preview->setUrlHash($hash);
            $this->em->persist($preview);
        }

        $preview->setUrl($normalized);
        $preview->setDomain($this->normalizer->extractDomain($normalized));
        $preview->setLastUpdate(new \DateTimeImmutable());
        $preview->setExpiresAt(new \DateTimeImmutable('+' . $this->cacheTtl . ' seconds'));
        $preview->setMime($mime);

        if ($fetchError !== null) {
            $preview->setError(mb_substr($fetchError, 0, 250));
            // Still try to fill what we can (favicon at minimum)
            $preview->setFaviconExternal($this->faviconService->googleS2Url($preview->getDomain()));
            $preview->setFaviconLocal($this->faviconService->resolveForPreview(null, $preview->getDomain()));
            $this->em->flush();
            return $preview;
        }

        $metadata = $this->extractor->extract($html, $normalized);
        $baseImage = $metadata['image'];
        $baseDescription = $metadata['description'];
        $baseTitle = $metadata['title'];
        $baseSiteName = $metadata['site_name'];
        $baseType = $metadata['type'];

        // Apply enrichers (they can override title/description/image based on URL pattern).
        $enriched = [
            'kind' => 'generic',
        ];
        foreach ($this->enrichers as $enricher) {
            if ($enricher->supports($normalized, $preview->getDomain() ?? '')) {
                $overrides = $enricher->enrich($normalized, $metadata);
                $enriched = array_merge($enriched, $overrides);
                if (isset($overrides['title'])) {
                    $baseTitle = $overrides['title'];
                }
                if (isset($overrides['description'])) {
                    $baseDescription = $overrides['description'];
                }
                if (isset($overrides['site_name'])) {
                    $baseSiteName = $overrides['site_name'];
                }
                if (isset($overrides['image'])) {
                    $baseImage = $overrides['image'];
                }
                if (isset($overrides['type'])) {
                    $baseType = $overrides['type'];
                }
                break; // First match wins
            }
        }

        // Fallback: try screenshot provider if no image AND provider enabled.
        if (empty($baseImage) && $this->screenshotProvider->isEnabled()) {
            $baseImage = $this->screenshotProvider->capture($normalized);
        }

        $preview->setTitle($baseTitle);
        $preview->setDescription($baseDescription);
        $preview->setSiteName($baseSiteName);
        $preview->setType($baseType);

        if ($baseImage !== null && $baseImage !== '') {
            $preview->setImageExternal($baseImage);
        }

        $preview->setFaviconExternal(
            $metadata['favicon_hint'] ?? $this->faviconService->googleS2Url($preview->getDomain())
        );
        $preview->setFaviconLocal(
            $this->faviconService->resolveForPreview($metadata['favicon_hint'] ?? null, $preview->getDomain())
        );

        $preview->setEnriched($enriched);
        $preview->setRawMetadata($metadata['raw'] ?? null);
        $preview->setError(null);

        $this->em->flush();

        return $preview;
    }

    private function hash(string $url): string
    {
        return hash('sha256', $url);
    }
}