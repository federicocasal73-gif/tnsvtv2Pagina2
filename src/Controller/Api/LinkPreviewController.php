<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Service\LinkPreview\LinkPreviewService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/link-preview')]
final class LinkPreviewController
{
    public function __construct(
        private readonly LinkPreviewService $linkPreviewService,
    ) {
    }

    #[Route('', name: 'api_link_preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $url = $data['url'] ?? '';
        $force = (bool) ($data['force'] ?? false);

        if ($url === '') {
            return new JsonResponse(['success' => false, 'error' => 'url_required'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $preview = $this->linkPreviewService->preview($url, $force);
            return new JsonResponse(['success' => true, 'preview' => $preview->toArray()]);
        } catch (\App\Service\LinkPreview\InvalidUrlException $e) {
            return new JsonResponse(['success' => false, 'error' => 'invalid_url', 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        } catch (\App\Service\LinkPreview\SsrfException $e) {
            return new JsonResponse(['success' => false, 'error' => 'blocked_url', 'message' => $e->getMessage()], Response::HTTP_FORBIDDEN);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'error' => 'server_error', 'message' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
