<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Renders the PWA offline fallback page when the user has no network.
 * Registered as a public route (no auth required) so service worker can
 * cache it during install and serve it on offline navigation.
 */
class OfflineController extends AbstractController
{
    #[Route('/offline', name: 'app_offline', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('offline/index.html.twig');
    }
}