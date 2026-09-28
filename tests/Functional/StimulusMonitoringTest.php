<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Verifica que el dashboard de monitoring (/sanctum/monitoring) carga
 * correctamente con CSP estricto (nonce en scripts, Stimulus se hidrata).
 */
class StimulusMonitoringTest extends ApiTestCase
{
    public function testMonitoringPageHasStimulusController(): void
    {
        // El endpoint requiere ROLE_ADMIN. Creamos uno.
        $admin = $this->createAdmin(['code' => 'STIM01', 'name' => 'Stim Admin']);
        $this->loginAs($admin);

        $this->client->request('GET', '/sanctum/monitoring');
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // 1. data-controller="monitoring" presente (Stimulus se va a conectar)
        $this->assertStringContainsString('data-controller="monitoring"', $html,
            'El template debe declarar data-controller="monitoring" para que Stimulus la conecte');

        // 2. CSP nonce presente
        $csp = $this->client->getResponse()->headers->get('Content-Security-Policy', '');
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m);
        $this->assertNotEmpty($m, 'CSP debe tener nonce');
        $nonce = $m[1];

        // 3. importmap script tiene nonce matching
        preg_match_all('/<script type="importmap"([^>]*)>/', $html, $importmaps);
        $this->assertNotEmpty($importmaps[0], 'Debe haber <script type="importmap">');
        foreach ($importmaps[0] as $tag) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag,
                "importmap sin nonce matching: $tag");
        }

        // 4. module scripts tienen nonce (entrypoints de Stimulus)
        preg_match_all('/<script[^>]*type="module"[^>]*>/', $html, $modules);
        $this->assertNotEmpty($modules[0], 'Debe haber <script type="module"> (entrypoints Stimulus)');
        foreach ($modules[0] as $tag) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag,
                "module script sin nonce: $tag");
        }
    }
}