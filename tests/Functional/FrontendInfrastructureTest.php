<?php

declare(strict_types=1);

namespace App\Tests\Functional;

class FrontendInfrastructureTest extends ApiTestCase
{
    public function testManifestJsonFileExistsAndIsValid(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $path = $projectRoot . '/public/manifest.json';
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $manifest = json_decode($content, true);
        $this->assertNotNull($manifest);
        $this->assertSame('T.N.S.V.T Sanctum', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);
        $this->assertNotEmpty($manifest['shortcuts']);
    }

    public function testServiceWorkerTemplateExists(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $path = $projectRoot . '/templates/sw.js.twig';
        $this->assertFileExists($path);

        $body = file_get_contents($path);
        $this->assertStringContainsString('addEventListener', $body);
        $this->assertStringContainsString('cache', $body);
        $this->assertStringContainsString('offline', $body);
        $this->assertStringContainsString('{{ cache_version }}', $body);
    }

    public function testServiceWorkerRouteServesJavascript(): void
    {
        $this->client->request('GET', '/sw.js');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/javascript', explode(';', $response->headers->get('Content-Type'))[0]);
        $this->assertStringContainsString('CACHE_VERSION', $response->getContent());
    }

    public function testOfflineFallbackPageRenders(): void
    {
        $this->client->request('GET', '/offline');

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Sin conexión', $response->getContent());
    }

    public function testShellTemplateIncludesPwaTags(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $shellPath = $projectRoot . '/templates/shell.html.twig';
        $content = file_get_contents($shellPath);

        $this->assertStringContainsString('manifest.json', $content, 'shell.html.twig must link to manifest.json');
        $this->assertStringContainsString('apple-mobile-web-app', $content, 'PWA meta tags required for iOS');
        $this->assertStringContainsString('data-controller="pwa"', $content, 'pwa_controller must be initialized on body');
    }
}