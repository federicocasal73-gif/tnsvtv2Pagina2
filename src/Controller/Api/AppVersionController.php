<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/app')]
class AppVersionController extends AbstractController
{
    #[Route('/version', name: 'api_app_version', methods: ['GET'])]
    public function version(): JsonResponse
    {
        return new JsonResponse([
            'appId' => 'com.tnsvt.app',
            'name' => 'T.N.S.V.T',
            'version' => $_ENV['APP_VERSION'] ?? '1.3.0',
            'versionCode' => (int) ($_ENV['APP_VERSION_CODE'] ?? 4),
            'downloadUrl' => $_ENV['APP_DOWNLOAD_URL'] ?? '',
            'releaseNotes' => $_ENV['APP_RELEASE_NOTES'] ?? '',
            'updateRequired' => ($_ENV['APP_UPDATE_REQUIRED'] ?? 'false') === 'true',
            'publishedAt' => date('c'),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
