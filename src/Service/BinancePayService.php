<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class BinancePayService
{
    private ?string $apiKey;
    private ?string $secretKey;
    private string $apiUrl;

    public function __construct(
        private ParameterBagInterface $params,
        private LoggerInterface $logger,
    ) {
        $this->apiKey = $this->getParam('binance_pay.api_key', 'BINANCE_PAY_API_KEY', '');
        $this->secretKey = $this->getParam('binance_pay.secret_key', 'BINANCE_PAY_SECRET_KEY', '');
        $this->apiUrl = $this->getParam('binance_pay.api_url', 'BINANCE_PAY_API_URL', 'https://bpay.binanceapi.com');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    /**
     * Crea una orden de pago en Binance Pay.
     * @param string $merchantTradeNo ID unico del merchant (ej: tnsvt_bn_{user_code}_{timestamp})
     * @param float $amountUSD Monto en USD
     * @param string $returnUrl URL de retorno post-pago
     * @return array{code:string,message:string,data?:array{prepayId:string,checkoutUrl:string}}|null
     */
    public function createOrder(
        string $merchantTradeNo,
        float $amountUSD,
        string $returnUrl,
    ): ?array {
        if (!$this->isConfigured()) {
            $this->logger->warning('[BN] Not configured, skipping createOrder');
            return null;
        }

        $timestamp = round(microtime(true) * 1000);
        $body = [
            'env' => [
                'terminalType' => 'WEB',
            ],
            'merchantTradeNo' => $merchantTradeNo,
            'orderAmount' => number_format($amountUSD, 2, '.', ''),
            'currency' => 'USDT',
            'goods' => [
                'goodsType' => '02',
                'goodsCategory' => 'X',
                'referenceGoodsId' => $merchantTradeNo,
                'goodsName' => "Carga de saldo TNSVT - \${$amountUSD}",
                'goodsDetail' => "Carga de saldo wallet TNSVT",
            ],
            'returnUrl' => $returnUrl,
        ];

        try {
            $resp = $this->callAPI('POST', '/binancepay/openapi/orders', $body, $timestamp);
            if ($resp && ($resp['status'] === 'SUCCESS' || $resp['status'] === 'ACCEPT')) {
                return $resp;
            }
            $this->logger->error('[BN] createOrder failed', ['resp' => $resp]);
        } catch (\Throwable $e) {
            $this->logger->error('[BN] createOrder error: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Consulta el estado de una orden por merchantTradeNo.
     */
    public function queryOrder(string $merchantTradeNo): ?array
    {
        if (!$this->isConfigured()) return null;
        $timestamp = round(microtime(true) * 1000);
        try {
            return $this->callAPI('POST', '/binancepay/openapi/orders/query', [
                'merchantTradeNo' => $merchantTradeNo,
            ], $timestamp);
        } catch (\Throwable $e) {
            $this->logger->error("[BN] queryOrder($merchantTradeNo) error: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verifica la firma de un webhook de Binance Pay.
     * @param string $payload JSON string del body
     * @param string $signature Header BinancePay-Signature
     * @return bool
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (!$this->isConfigured()) return false;
        $expected = hash_hmac('sha512', $payload, $this->secretKey);
        return hash_equals($expected, $signature);
    }

    private function callAPI(string $method, string $path, array $body, int $timestamp): ?array
    {
        $url = $this->apiUrl . $path;
        $payload = json_encode($body);
        $nonce = bin2hex(random_bytes(16));

        $signature = $this->sign($timestamp, $nonce, $body);

        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'BinancePay-Timestamp: ' . $timestamp,
                    'BinancePay-Nonce: ' . $nonce,
                    'BinancePay-Certificate-SN: ' . $this->apiKey,
                    'BinancePay-Signature: ' . $signature,
                ]),
                'content' => $payload,
                'timeout' => 15,
                'ignore_errors' => true,
            ],
        ]);

        $result = @file_get_contents($url, false, $ctx);
        if ($result === false) {
            $this->logger->error("[BN] HTTP call failed: $method $path");
            return null;
        }

        return json_decode($result, true) ?: null;
    }

    /**
     * Firma HMAC-SHA256 segun especificacion Binance Pay.
     * payload = timestamp + "\n" + nonce + "\n" + body + "\n"
     */
    private function sign(int $timestamp, string $nonce, array $body): string
    {
        $payload = $timestamp . "\n" . $nonce . "\n" . json_encode($body) . "\n";
        return strtoupper(hash_hmac('sha256', $payload, $this->secretKey));
    }

    private function getParam(string $paramName, string $envName, string $default): string
    {
        if ($this->params->has($paramName)) {
            return $this->params->get($paramName);
        }
        return $_ENV[$envName] ?? $default;
    }
}
