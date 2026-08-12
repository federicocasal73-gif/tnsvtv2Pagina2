<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use App\Service\MercadoPagoService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/mercadopago')]
class MercadoPagoController extends AbstractController
{
    public function __construct(
        private MercadoPagoService $mp,
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
     * Crea una preferencia de pago para cargar saldo.
     * Body: { code: "USER_CODE", amount_usd: 10 }
     * amount_usd se convierte a ARS segun tasa de dolarapi.com
     */
    #[Route('/create-payment', name: 'api_mp_create_payment', methods: ['POST'])]
    public function createPayment(Request $request): JsonResponse
    {
        if (!$this->mp->isConfigured()) {
            return new JsonResponse(['error' => 'MercadoPago no configurado. MP_ACCESS_TOKEN vacio.'], 501);
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

        // Obtener tasa USD->ARS
        $rateARS = $this->getDolarRate();
        if ($rateARS <= 0) {
            return new JsonResponse(['error' => 'No se pudo obtener tasa de dolar'], 502);
        }

        $amountARS = round($amountUSD * $rateARS, 2);
        if ($amountARS < 1) {
            return new JsonResponse(['error' => 'Monto muy bajo en ARS'], 400);
        }

        $externalRef = ($_ENV['MP_EXT_REF_PREFIX'] ?? 'tnsvt_mp_') . $user->getCode() . '_' . time();

        $pref = $this->mp->createPreference(
            $externalRef,
            $amountARS,
            "Carga de saldo TNSVT - \${$amountUSD} USD",
        );

        if (!$pref) {
            return new JsonResponse(['error' => 'Error al crear preferencia en MercadoPago'], 502);
        }

        // Crear wallet_transaction como pending para trackear
        $tx = new WalletTransaction();
        $tx->setUser($user);
        $tx->setType(WalletTransaction::TYPE_DEPOSIT);
        $tx->setAmount(number_format($amountUSD, 2, '.', ''));
        $tx->setCurrency('USD');
        $tx->setStatus(WalletTransaction::STATUS_PENDING);
        $tx->setRefPaymentId($externalRef);
        $tx->setRefPaymentMethod(WalletTransaction::METHOD_AUTO_MP);
        $tx->setNotes("MP preference {$pref['id']} - \${$amountUSD} USD (\${$amountARS} ARS)");
        $this->em->persist($tx);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'preference_id' => $pref['id'],
            'init_point' => $pref['init_point'],
            'amount_usd' => $amountUSD,
            'amount_ars' => $amountARS,
            'rate_ars' => $rateARS,
            'external_ref' => $externalRef,
        ]);
    }

    /**
     * Webhook de IPN de MercadoPago.
     * MP envia POST con JSON: { action, data: { id } }
     * Tambien soporta query params: ?topic=payment&id=123
     * La firma X-Signature se verifica si MP_WEBHOOK_SECRET está configurado.
     */
    #[Route('/webhook', name: 'api_mp_webhook', methods: ['POST', 'GET'])]
    public function webhook(Request $request): JsonResponse
    {
        if (!$this->mp->isConfigured()) {
            return new JsonResponse(['error' => 'MP not configured'], 501);
        }

        // Verify X-Signature if webhook secret is configured
        $webhookSecret = $_ENV['MP_WEBHOOK_SECRET'] ?? $_SERVER['MP_WEBHOOK_SECRET'] ?? '';
        if ($webhookSecret !== '') {
            $signature = $request->headers->get('X-Signature', '');
            if (!$this->verifyMPSignature($signature, $request, $webhookSecret)) {
                $this->logger->warning('[MP] Invalid webhook signature');
                return new JsonResponse(['error' => 'invalid_signature'], 401);
            }
        }

        // GET: ?topic=payment&id=123 (MP envía asi en sandbox)
        if ($request->isMethod('GET')) {
            $topic = $request->query->get('topic');
            $paymentId = $request->query->get('id');
            if ($topic === 'payment' && $paymentId) {
                $this->processPaymentNotification((string) $paymentId);
            }
            return new JsonResponse(['ok' => true]);
        }

        // POST: JSON body
        $body = json_decode($request->getContent(), true);
        if (!is_array($body)) {
            return new JsonResponse(['error' => 'invalid_json'], 400);
        }

        $action = $body['action'] ?? $body['type'] ?? '';
        $data = $body['data'] ?? [];
        $paymentId = $data['id'] ?? $request->query->get('id') ?? '';

        if ($action === 'payment.created' || $action === 'payment.updated' || $paymentId) {
            $this->processPaymentNotification((string) $paymentId);
        }

        return new JsonResponse(['ok' => true]);
    }

    /**
     * Verifica la firma X-Signature de MercadoPago.
     * Formato: ts=<timestamp>,v1=<hmac>
     * HMAC-SHA256 sobre: "id:<payment_id>;created-at:<ts>;"
     */
    private function verifyMPSignature(string $header, Request $request, string $secret): bool
    {
        if (empty($header) || empty($secret)) return false;

        $parts = explode(',', $header);
        $ts = '';
        $hash = '';
        foreach ($parts as $part) {
            $kv = explode('=', $part, 2);
            if (count($kv) === 2) {
                $key = trim($kv[0]);
                $value = trim($kv[1]);
                if ($key === 'ts') $ts = $value;
                if ($key === 'v1') $hash = $value;
            }
        }
        if (empty($ts) || empty($hash)) return false;

        // Get payment ID from body or query
        $body = json_decode($request->getContent(), true);
        $paymentId = '';
        if (is_array($body)) {
            $data = $body['data'] ?? [];
            $paymentId = (string) ($data['id'] ?? $body['id'] ?? '');
        }
        if (empty($paymentId)) {
            $paymentId = (string) ($request->query->get('id') ?? '');
        }
        if (empty($paymentId)) return false;

        $template = "id:$paymentId;created-at:$ts;";
        $expected = hash_hmac('sha256', $template, $secret);

        return hash_equals($expected, $hash);
    }

    /**
     * Procesa una notificacion de pago: verifica estado y acredita wallet.
     */
    private function processPaymentNotification(string $paymentId): void
    {
        if (!$paymentId) return;
        $this->logger->info("[MP] Webhook received for payment $paymentId");

        $payment = $this->mp->getPayment($paymentId);
        if (!$payment) {
            $this->logger->warning("[MP] Payment $paymentId not found via API");
            return;
        }

        $status = $payment['status'] ?? '';
        $externalRef = $payment['external_reference'] ?? '';

        if (!$externalRef) {
            $this->logger->warning("[MP] Payment $paymentId has no external_reference");
            return;
        }

        // Buscar wallet_transaction por externalRef
        $tx = $this->txRepository->findOneBy(['refPaymentId' => $externalRef]);
        if (!$tx) {
            $this->logger->warning("[MP] No transaction found for external_ref=$externalRef");
            return;
        }

        if ($tx->getStatus() === WalletTransaction::STATUS_CONFIRMED) {
            $this->logger->info("[MP] Transaction $externalRef already confirmed, skipping");
            return;
        }

        if ($status === 'approved') {
            $amount = (float) $tx->getAmount();
            $user = $tx->getUser();
            if ($user && $amount > 0) {
                // Atomic credit to prevent race on duplicate webhook
                $this->em->getConnection()->executeStatement(
                    'UPDATE "user" SET wallet_balance = CAST(wallet_balance AS REAL) + :amount WHERE id = :id',
                    ['amount' => $amount, 'id' => $user->getId()]
                );
                $this->em->refresh($user);
            }
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setConfirmedAt(new \DateTimeImmutable());
            $this->em->flush();
            $this->logger->info("[MP] Payment $paymentId approved, credited \${$amount} to {$user?->getCode()}");
        } elseif (in_array($status, ['rejected', 'cancelled', 'refunded'], true)) {
            $tx->setStatus(WalletTransaction::STATUS_REJECTED);
            $this->em->flush();
            $this->logger->info("[MP] Payment $paymentId $status");
        } else {
            $this->logger->info("[MP] Payment $paymentId status=$status (pending)");
        }
    }

    /**
     * Obtiene la tasa USD->ARS desde el endpoint de rates.
     */
    private function getDolarRate(): float
    {
        try {
            $resp = @file_get_contents('http://127.0.0.1:8000/api/wallet/rates', false, stream_context_create(['http' => ['timeout' => 3]]));
            if ($resp) {
                $data = json_decode($resp, true);
                if (isset($data['blue']['sell'])) return (float) $data['blue']['sell'];
                if (isset($data['oficial']['sell'])) return (float) $data['oficial']['sell'];
            }
        } catch (\Throwable $e) {
            $this->logger->warning('[MP] Error fetching dolar rate: ' . $e->getMessage());
        }
        return 0;
    }
}
