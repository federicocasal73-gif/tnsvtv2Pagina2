<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the static public/sitemap.xml.
 *
 * Regenerate the file via `php bin/generate-sitemap.php` (run on deploy
 * after route changes). If the file is missing, return 404 so search
 * crawlers don't index a stale list.
 */
class SitemapController extends AbstractController
{
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'])]
    public function __invoke(): Response
    {
        $path = $this->getParameter('kernel.project_dir') . '/public/sitemap.xml';
        if (!is_file($path)) {
            throw $this->createNotFoundException('sitemap.xml not generated yet');
        }

        $response = new Response((string) file_get_contents($path));
        $response->headers->set('Content-Type', 'application/xml; charset=utf-8');
        $response->setPublic();
        $response->setMaxAge(3600);

        return $response;
    }
}