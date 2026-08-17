<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class OpenApiDocsTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testOpenApiSpecExistsAndIsValid(): void
    {
        $this->client->request('GET', '/api/docs/openapi.json');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('openapi', $payload);
        $this->assertArrayHasKey('paths', $payload);
        $this->assertArrayHasKey('components', $payload);
        $this->assertSame('3.0.0', $payload['openapi']);
    }

    public function testOpenApiSpecDocumentsLoginEndpoint(): void
    {
        $this->client->request('GET', '/api/docs/openapi.json');

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('/api/auth/login', $payload['paths']);
        $this->assertSame('post', array_key_first($payload['paths']['/api/auth/login']));
    }

    public function testOpenApiSpecDefinesAuthSchemas(): void
    {
        $this->client->request('GET', '/api/docs/openapi.json');

        $payload = json_decode($this->client->getResponse()->getContent(), true);
        $schemas = $payload['components']['schemas'] ?? [];

        $this->assertArrayHasKey('LoginRequest', $schemas);
        $this->assertArrayHasKey('LoginResponse', $schemas);
        $this->assertArrayHasKey('User', $schemas);
        $this->assertArrayHasKey('ApiError', $schemas);
    }

    public function testSwaggerUiPageLoads(): void
    {
        $this->client->request('GET', '/api/docs');

        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertStringContainsString('swagger-ui', $this->client->getResponse()->getContent());
    }
}