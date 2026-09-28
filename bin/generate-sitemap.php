<?php

declare(strict_types=1);

/**
 * Generates public/sitemap.xml from a curated list of PUBLIC routes
 * (auth-required pages are excluded — search engines shouldn't index them).
 *
 * Run on every deploy (CI or local) before the app boots, or on demand
 * after adding new public pages.
 *
 * Usage: php bin/generate-sitemap.php [BASE_URL]
 *        php bin/generate-sitemap.php https://tnsvt.com
 */

require __DIR__ . '/../vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv(__DIR__ . '/../.env');

$baseUrl = $argv[1] ?? ($_SERVER['APP_SERVER_URL'] ?? 'https://tnsvt.com');
$baseUrl = rtrim($baseUrl, '/');

// Curated public paths (no auth, no sanctum). Add new public pages here.
$publicPaths = [
    '/',
    '/login',
    '/home',
    '/offline',
    '/macro',
    '/macro/academy',
    '/oracle',
    '/frequencies',
    '/og/image',
];

$urls = array_unique(array_merge($publicPaths, [$baseUrl . '/sitemap.xml']));

$xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;
foreach ($urls as $url) {
    $loc = str_starts_with($url, 'http') ? $url : $baseUrl . $url;
    $xml .= "  <url><loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc><changefreq>weekly</changefreq></url>" . PHP_EOL;
}
$xml .= '</urlset>' . PHP_EOL;

$dir = __DIR__ . '/../public';
file_put_contents("$dir/sitemap.xml", $xml);

echo "Sitemap written to public/sitemap.xml (" . count($urls) . " URLs)" . PHP_EOL;