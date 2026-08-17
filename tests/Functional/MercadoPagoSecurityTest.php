<?php

declare(strict_types=1);

namespace App\Tests\Functional;

class MercadoPagoSecurityTest extends ApiTestCase
{
    public function testWebhookRejectsGetMethod(): void
    {
        $this->client->request(
            'GET',
            '/api/mercadopago/webhook?topic=payment&id=12345',
        );

        $response = $this->client->getResponse();
        $this->assertNotSame(200, $response->getStatusCode(),
            'GET to webhook must be rejected (only POST allowed to prevent cache poisoning)');
    }

    public function testWebhookRejectsPostWithoutSignature(): void
    {
        $this->client->request(
            'POST',
            '/api/mercadopago/webhook',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'action' => 'payment.created',
                'data' => ['id' => '12345'],
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(),
            'Webhook without X-Signature must return 401, not 200');

        $body = json_decode($response->getContent(), true);
        $this->assertSame('invalid_signature', $body['error'] ?? null);
    }

    public function testWebhookRejectsPostWithInvalidSignature(): void
    {
        $this->client->request(
            'POST',
            '/api/mercadopago/webhook',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => 'ts=1234567890,v1=invalid_fake_signature',
            ],
            json_encode([
                'action' => 'payment.created',
                'data' => ['id' => '12345'],
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(401, $response->getStatusCode(),
            'Webhook with invalid signature must return 401');
    }

    public function testWebhookReturns404ForUnknownRouteToPreventInfoLeak(): void
    {
        $this->client->request('GET', '/api/wallet/me');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode(),
            '/api/wallet/me must return 404 (route removed)');
    }

    public function testWebhookValidSignatureAllowsProcessing(): void
    {
        $paymentId = '12345';
        $ts = (string) time();
        $template = "id:$paymentId;created-at:$ts;";
        $expectedHash = hash_hmac('sha256', $template, 'test_webhook_secret_for_signing_verification');

        $this->client->request(
            'POST',
            '/api/mercadopago/webhook',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_SIGNATURE' => "ts=$ts,v1=$expectedHash",
            ],
            json_encode([
                'action' => 'payment.created',
                'data' => ['id' => $paymentId],
            ])
        );

        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode(),
            'Valid signature must return 200. Body: ' . $response->getContent());
    }
}