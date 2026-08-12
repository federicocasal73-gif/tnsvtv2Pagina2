<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use App\Service\BinancePayService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/binance-pay')]
class BinancePayController extends AbstractController
{
    public function __construct(
        private BinancePayService $bn,
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private WalletTransactionRepository $txRepository,
        private LoggerInterface $logger,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) {
                $code = trim((string) $data['code']);
            }
        }
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    /**
     * Crea una orden de pago en Binance Pay.
     * Body: { code: "USER_CODE", amount_usd: 10 }
     */
    #[Route('/create-order', name: 'api_bn_create_order', methods: ['POST'])]
    public function createOrder(Request $request): JsonResponse
    {
        if (!$this->bn->isConfigured()) {
            return new JsonResponse(['error' => 'Binance Pay no configurado. API Key / Secret Key vacias.'], 501);
        }

        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'No autenticado'], 401);

        $body = json_decode($request->getContent(), true);
        if (!is_array($body) || !isset($body['amount_usd'])) {
            return new JsonResponse(['error' => 'Falta amount_usd'], 400);
        }

        $amountUSD = (float) $body['amount_usd'];
        if ($amountUSD < 1 || $amountUSD > 1000) {
            return new JsonResponse(['error' => 'amount_usd debe ser 1-1000'], 400);
        }

        $merchantTradeNo = 'tnsvt_bn_' . $user->getCode() . '_' . time();
        $serverUrl = $_ENV['APP_SERVER_URL'] ?? 'http://192.168.1.2:8000';
        $returnUrl = rtrim($serverUrl, '/') . '/?pg=ok';

        $order = $this->bn->createOrder(
            $merchantTradeNo,
            $amountUSD,
            $returnUrl,
        );

        if (!$order || !isset($order['data']['prepayId'], $order['data']['checkoutUrl'])) {
            $msg = $order['errorMessage'] ?? $order['message'] ?? 'Error al crear orden en Binance Pay';
            return new JsonResponse(['error' => $msg], 502);
        }

        // Crear wallet_transaction como pending
        $tx = new WalletTransaction();
        $tx->setUser($user);
        $tx->setType(WalletTransaction::TYPE_DEPOSIT);
        $tx->setAmount(number_format($amountUSD, 2, '.', ''));
        $tx->setCurrency('USD');
        $tx->setStatus(WalletTransaction::STATUS_PENDING);
        $tx->setRefPaymentId($merchantTradeNo);
        $tx->setRefPaymentMethod(WalletTransaction::METHOD_AUTO_BINANCE);
        $tx->setNotes("BN order {$order['data']['prepayId']} - \${$amountUSD} USDT");
        $this->em->persist($tx);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'prepay_id' => $order['data']['prepayId'],
            'checkout_url' => $order['data']['checkoutUrl'],
            'amount_usd' => $amountUSD,
            'merchant_trade_no' => $merchantTradeNo,
        ]);
    }

    /**
     * Webhook de Binance Pay (payment notification).
     * Binance envia POST con JSON firmado.
     */
    #[Route('/webhook', name: 'api_bn_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->bn->isConfigured()) {
            return new JsonResponse(['error' => 'BN not configured'], 501);
        }

        $payload = $request->getContent();
        $signature = $request->headers->get('BinancePay-Signature', '');

        if (!$this->bn->verifyWebhookSignature($payload, $signature)) {
            $this->logger->warning('[BN] Webhook signature verification failed');
            return new JsonResponse(['error' => 'invalid signature'], 401);
        }

        $data = json_decode($payload, true);
        if (!$data) {
            return new JsonResponse(['error' => 'invalid json'], 400);
        }

        $bizStatus = $data['bizStatus'] ?? $data['data']['bizStatus'] ?? '';
        $merchantTradeNo = $data['data']['merchantTradeNo'] ?? '';
        $status = $data['data']['status'] ?? '';

        $this->logger->info("[BN] Webhook received: merchantTradeNo=$merchantTradeNo bizStatus=$bizStatus status=$status");

        if (!$merchantTradeNo) {
            return new JsonResponse(['error' => 'missing merchantTradeNo'], 400);
        }

        // Buscar wallet_transaction
        $tx = $this->txRepository->findOneBy(['refPaymentId' => $merchantTradeNo]);
        if (!$tx) {
            $this->logger->warning("[BN] No transaction found for $merchantTradeNo");
            // Respond 200 to acknowledge receipt
            return new JsonResponse(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
        }

        if ($tx->getStatus() === WalletTransaction::STATUS_CONFIRMED) {
            $this->logger->info("[BN] Transaction $merchantTradeNo already confirmed, skipping");
            return new JsonResponse(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
        }

        $isPaid = ($bizStatus === 'PAY_SUCCESS' || $status === 'Completed' || $bizStatus === 'PAY_FINISH');
        $isFailed = ($bizStatus === 'PAY_CLOSED' || $bizStatus === 'PAY_FAILED' || $status === 'Expired' || $status === 'Canceled');

        if ($isPaid) {
            $amount = (float) $tx->getAmount();
            $user = $tx->getUser();
            if ($user) {
                $user->addToWallet($amount);
            }
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setConfirmedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->logger->info("[BN] Payment $merchantTradeNo approved, credited \${$amount} to {$user?->getCode()}");
        } elseif ($isFailed) {
            $tx->setStatus(WalletTransaction::STATUS_REJECTED);
            $this->em->flush();
            $this->logger->info("[BN] Payment $merchantTradeNo failed/closed");
        }

        return new JsonResponse(['returnCode' => 'SUCCESS', 'returnMessage' => null]);
    }

    /**
     * Consulta el estado de una orden manualmente.
     */
    #[Route('/query-order', name: 'api_bn_query_order', methods: ['POST'])]
    public function queryOrder(Request $request): JsonResponse
    {
        if (!$this->bn->isConfigured()) {
            return new JsonResponse(['error' => 'Binance Pay no configurado'], 501);
        }

        $body = json_decode($request->getContent(), true);
        $merchantTradeNo = $body['merchant_trade_no'] ?? '';
        if (!$merchantTradeNo) {
            return new JsonResponse(['error' => 'Falta merchant_trade_no'], 400);
        }

        $order = $this->bn->queryOrder($merchantTradeNo);
        if (!$order) {
            return new JsonResponse(['error' => 'Error al consultar orden'], 502);
        }

        return new JsonResponse($order);
    }
}
