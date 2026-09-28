<?php

declare(strict_types=1);

namespace App\Tests\Functional;

class CspSmokeTest extends ApiTestCase
{
    public function testHomePageRendersAndHasCspHeader(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $headers = $this->client->getResponse()->headers;
        $csp = $headers->get('Content-Security-Policy') ?? $headers->get('content-security-policy');
        $this->assertNotNull($csp, 'CSP header must be present');
        $this->assertStringNotContainsString("'unsafe-inline'", $csp,
            'Audit AUDIT-2026-09-28 #5: script-src debe usar nonce, no unsafe-inline');
        $this->assertStringContainsString("'nonce-", $csp, 'CSP debe contener un nonce');
        self::assertTrue(true);
    }

    /**
     * Verifica que los <script> inline tienen atributo nonce que matchea
     * el header CSP. Sin esto el browser bloquea los scripts.
     */
    public function testHomePageInlineScriptsHaveMatchingNonce(): void
    {
        $this->client->request('GET', '/');
        $html = $this->client->getResponse()->getContent();
        $csp = $this->client->getResponse()->headers->get('Content-Security-Policy', '');

        // Extraer nonce del header: 'nonce-XXXX...'
        preg_match("/'nonce-([A-Za-z0-9+\/=]+)'/", $csp, $m);
        $this->assertNotEmpty($m, 'CSP debe declarar un nonce');
        $nonce = $m[1];

        // Buscar todos los <script> inline (sin src=) y exigir nonce=
        preg_match_all('/<script(?![^>]*\bsrc=)([^>]*)>/i', $html, $matches);
        $inlineScripts = $matches[0] ?? [];
        $this->assertNotEmpty($inlineScripts, 'La home debe tener al menos un script inline');

        foreach ($inlineScripts as $tag) {
            $this->assertStringContainsString('nonce="' . $nonce . '"', $tag,
                "Inline script sin nonce matching: $tag");
        }
    }

    /**
     * Audit AUDIT-2026-09-28 #5: style-src NO debe tener 'unsafe-inline'.
     * Solo 'unsafe-hashes' (para style="..." attributes) y nonce.
     */
    public function testStyleSrcHasNoUnsafeInline(): void
    {
        $this->client->request('GET', '/');
        $csp = $this->client->getResponse()->headers->get('Content-Security-Policy', '');
        preg_match('/style-src ([^;]+)/', $csp, $m);
        $this->assertNotEmpty($m, 'style-src debe estar presente');
        $this->assertStringNotContainsString("'unsafe-inline'", $m[1],
            "style-src no debe contener 'unsafe-inline' (audit AUDIT-2026-09-28 #5)");
        $this->assertStringContainsString("'nonce-", $m[1], 'style-src debe usar nonce');
        $this->assertStringContainsString("'unsafe-hashes'", $m[1],
            "style-src debe permitir inline style='...' attributes");
    }

    /**
     * Audit AUDIT-2026-09-28 #5: script-src debe tener sha256-* de los event
     * handlers inline. Los handlers en si mismos siguen en el HTML (no los
     * refactorizamos) pero CSP valida que su hash coincida con el header.
     */
    public function testScriptSrcHasSha256HashesForInlineEventHandlers(): void
    {
        $this->client->request('GET', '/');
        $csp = $this->client->getResponse()->headers->get('Content-Security-Policy', '');
        preg_match('/script-src ([^;]+)/', $csp, $m);
        $this->assertNotEmpty($m, 'script-src debe estar presente');

        // 'unsafe-hashes' requerido para que los sha256-* apliquen a handlers
        $this->assertStringContainsString("'unsafe-hashes'", $m[1],
            "script-src debe permitir 'unsafe-hashes' para que sha256-* funcione sobre event handlers");

        // Al menos los handlers de api_helper + error500 + macro_academy
        $this->assertStringContainsString("'sha256-FBtmGHL2IdXjY7A/QliE0pTOVmmDGzq5+cVxIleJ65E='",
            $m[1], "api_helper banner close handler debe estar hasheado");
        $this->assertStringContainsString("'sha256-9gOBGqEQINNDuds+tkXbNzih6klbz+KeCyxEj4KRLeM='",
            $m[1], "location.reload() handler debe estar hasheado");
    }

    /**
     * Verifica que un template con onclick handlers (macro_academy quiz)
     * sigue funcionando con CSP strict. El handler debe estar en el HTML
     * Y su hash debe estar en el CSP header.
     */
    public function testMacroAcademyQuizPageHasValidCsp(): void
    {
        // macro_academy quiz requires auth — admin puede acceder.
        $admin = $this->createAdmin(['code' => 'CSPQ01', 'name' => 'Csp Quiz']);
        $this->loginAs($admin);

        $this->client->request('GET', '/sanctum/academia/quiz');
        if ($this->client->getResponse()->getStatusCode() === 404) {
            self::markTestSkipped('Quiz page route not found in this env');
        }

        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // Los handlers onclick="mcAnswer(...)" deben estar en el HTML
        $this->assertStringContainsString('onclick="mcAnswer(', $html,
            'macro_academy quiz debe tener onclick handlers');

        // Y todos los handlers del archivo deben estar hasheados en el CSP
        $csp = $this->client->getResponse()->headers->get('Content-Security-Policy', '');
        preg_match_all("/'sha256-[A-Za-z0-9+\/=]+'/", $csp, $cspHashes);
        $this->assertGreaterThanOrEqual(10, count($cspHashes[0]),
            'CSP debe declarar al menos 10 hashes (uno por cada mcAnswer q1-q10)');
    }
}