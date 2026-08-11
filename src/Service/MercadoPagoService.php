<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class MercadoPagoService
{
    private ?string $accessToken;
    private string $apiUrl;

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
    ) {
        $this->accessToken = $this->getParam('mp.access_token', 'MP_ACCESS_TOKEN', '');
        $this->apiUrl = $this->getParam('mp.api_url', 'MP_API_URL', 'https://api.mercadopago.com');
    }

    public function isConfigured(): bool
    {
        return $this->accessToken !== null && $this->accessToken !== '';
    }

    /**
     * Crea una preferencia de pago en MP Checkout Pro.
     * @param string $externalRef ID unico para identificar el pago (ej: user_code + timestamp)
     * @param float $amountARS Monto en ARS
     * @param string $title Titulo del producto
     * @param array $backUrls URLs de retorno
     * @return array{id:string,init_point:string}|null
     */
    public function createPreference(
        string $externalRef,
        float $amountARS,
        string $title,
        array $backUrls = [],
    ): ?array {
        if (!$this->isConfigured()) {
            $this->logger->warning('[MP] Not configured, skipping createPreference');
            return null;
        }

        $body = json_encode([
            'external_reference' => $externalRef,
            'notification_url' => $this->getNotificationUrl(),
            'auto_return' => 'approved',
            'back_urls' => [
                'success' => $backUrls['success'] ?? $this->getParam('mp.success_url', 'MP_SUCCESS_URL', ''),
                'failure' => $backUrls['failure'] ?? $this->getParam('mp.failure_url', 'MP_FAILURE_URL', ''),
                'pending' => $backUrls['pending'] ?? $this->getParam('mp.pending_url', 'MP_PENDING_URL', ''),
            ],
            'items' => [
                [
                    'id' => $externalRef,
                    'title' => $title,
                    'currency_id' => 'ARS',
                    'quantity' => 1,
                    'unit_price' => $amountARS,
                ],
            ],
        ]);

        try {
            $resp = $this->callAPI('POST', '/checkout/preferences', $body);
            if ($resp && isset($resp['id'], $resp['init_point'])) {
                return [
                    'id' => $resp['id'],
                    'init_point' => $resp['init_point'],
                ];
            }
            $this->logger->error('[MP] createPreference response missing id/init_point', ['resp' => $resp]);
        } catch (\Throwable $e) {
            $this->logger->error('[MP] createPreference error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Obtiene info de un pago por ID de MP.
     */
    public function getPayment(string $paymentId): ?array
    {
        if (!$this->isConfigured()) return null;
        try {
            return $this->callAPI('GET', "/v1/payments/{$paymentId}");
        } catch (\Throwable $e) {
            $this->logger->error("[MP] getPayment($paymentId) error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca un pago por external_reference.
     */
    public function searchByExternalRef(string $externalRef): ?array
    {
        if (!$this->isConfigured()) return null;
        try {
            return $this->callAPI('GET', '/v1/payments/search', null, [
                'external_reference' => $externalRef,
                'sort' => 'date_created',
                'criteria' => 'desc',
                'limit' => 1,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error("[MP] searchByExternalRef($externalRef) error: " . $e->getMessage());
            return null;
        }
    }

    private function callAPI(string $method, string $path, ?string $body = null, array $query = []): ?array
    {
        $url = $this->apiUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [
                    'Authorization: Bearer ' . $this->accessToken,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $body,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            $this->logger->error("[MP] HTTP call failed: $method $path");
            return null;
        }

        $decoded = json_decode($result, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->error("[MP] JSON decode error: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    private function getNotificationUrl(): string
    {
        $serverUrl = $this->getParam('app.server_url', 'APP_SERVER_URL', 'http://192.168.1.2:8000');
        return rtrim($serverUrl, '/') . '/api/mercadopago/webhook';
    }

    private function getParam(string $paramName, string $envName, string $default): string
    {
        if ($this->params->has($paramName)) {
            return $this->params->get($paramName);
        }
        return $_ENV[$envName] ?? $default;
    }
}
