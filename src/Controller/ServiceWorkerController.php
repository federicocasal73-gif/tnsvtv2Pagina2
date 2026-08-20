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
 */
class ServiceWorkerController extends AbstractController
{
    #[Route('/sw.js', name: 'service_worker', methods: ['GET'])]
    public function index(): Response
    {
        $appVersion = (string) getenv('APP_VERSION');
        if ($appVersion === '') {
            $appVersion = '2.0.0';
        }

        $content = $this->renderView('sw.js.twig', [
            'cache_version' => $appVersion,
        ]);

        return new Response($content, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
        ]);
    }
}