<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\Auth\JwtService;

class SecurityHeadersTest extends ApiTestCase
{
    public function testXContentTypeOptionsNosniffHeaderIsPresent(): void
    {
        $this->client->request('GET', '/api/auth/check');

        $response = $this->client->getResponse();
        $this->assertSame(
            'nosniff',
            $response->headers->get('X-Content-Type-Options'),
            'X-Content-Type-Options: nosniff must be set (MIME sniffing protection)'
        );
    }

    public function testXFrameOptionsHeaderIsPresent(): void
    {
        $this->client->request('GET', '/api/auth/check');

        $response = $this->client->getResponse();
        $this->assertSame(
            'DENY',
            $response->headers->get('X-Frame-Options'),
            'X-Frame-Options: DENY must be set (clickjacking protection)'
        );
    }

    public function testReferrerPolicyHeaderIsPresent(): void
    {
        $this->client->request('GET', '/api/auth/check');

        $response = $this->client->getResponse();
        $this->assertSame(
            'strict-origin-when-cross-origin',
            $response->headers->get('Referrer-Policy'),
            'Referrer-Policy must be strict-origin-when-cross-origin'
        );
    }

    public function testContentSecurityPolicyHeaderIsPresentOnHtml(): void
    {
        $this->client->request('GET', '/');

        $response = $this->client->getResponse();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'CSP header must be present on HTML responses');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp,
            "CSP must include frame-ancestors 'none' (clickjacking defense in depth)");
    }

    public function testPermissionsPolicyHeaderIsPresent(): void
    {
        $this->client->request('GET', '/');

        $response = $this->client->getResponse();
        $permissions = $response->headers->get('Permissions-Policy');
        $this->assertNotNull($permissions, 'Permissions-Policy header must be present');
        $this->assertStringContainsString('camera=()', $permissions, 'camera must be disabled');
        $this->assertStringContainsString('microphone=()', $permissions, 'microphone must be disabled');
        $this->assertStringContainsString('geolocation=()', $permissions, 'geolocation must be disabled');
    }

    public function testHeadersArePresentOnAuthenticatedJsonApi(): void
    {
        $user = $this->createUser(['code' => 'SECUSER']);
        $token = static::getContainer()->get(JwtService::class)->createToken($user);

        $this->client->request(
            'GET',
            '/api/auth/check',
            [],
            [],
            ['HTTP_AUTHORIZATION' => 'Bearer ' . $token],
        );

        $response = $this->client->getResponse();
        $this->assertNotNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNotNull($response->headers->get('X-Frame-Options'));
        $this->assertNotNull($response->headers->get('Referrer-Policy'));
    }

    public function testSecurityHeadersConfigBundleIsRegistered(): void
    {
        $bundles = static::getContainer()->getParameter('kernel.bundles');
        $this->assertArrayHasKey('NelmioSecurityBundle', $bundles,
            'NelmioSecurityBundle must be registered in config/bundles.php');
    }
}