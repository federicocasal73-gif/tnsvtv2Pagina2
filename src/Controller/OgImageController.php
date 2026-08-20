<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Returns dynamic OG images as SVG (with proper content-type).
 * Browsers and social platforms (Facebook, Twitter, Discord) accept SVG for og:image.
 *
 * GET /og/image?title=...&subtitle=...&variant=default|gold|violet|trade
 */
class OgImageController extends AbstractController
{
    #[Route('/og/image', name: 'og_image', methods: ['GET'])]
    public function image(Request $request): Response
    {
        $title = $this->safe($request->query->get('title', 'T.N.S.V.T'), 80);
        $subtitle = $this->safe($request->query->get('subtitle', 'Reino del Cristo Íntegro'), 80);
        $variant = $request->query->get('variant', 'default');
        if (!in_array($variant, ['default', 'gold', 'violet', 'trade'], true)) {
            $variant = 'default';
        }

        $bg = match ($variant) {
            'gold' => '#1a1300',
            'violet' => '#1a0d2e',
            'trade' => '#0d1a0d',
            default => '#161121',
        };
        $accent = match ($variant) {
            'gold' => '#f2ca50',
            'violet' => '#8a3cff',
            'trade' => '#4ade80',
            default => '#f2ca50',
        };
        $accentDark = match ($variant) {
            'gold' => '#e9c349',
            'violet' => '#7c3aed',
            'trade' => '#22c55e',
            default => '#e9c349',
        };

        $svg = $this->render('og_image.svg.twig', [
            'title' => $title,
            'subtitle' => $subtitle,
            'bg' => $bg,
            'accent' => $accent,
            'accent_dark' => $accentDark,
        ])->getContent();

        return new Response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function safe(string $value, int $max): string
    {
        $value = trim(strip_tags($value));
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max - 1) . '…';
        }
        return htmlspecialchars($value, ENT_XML1, 'UTF-8');
    }
}
