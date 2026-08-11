<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\WalletTransactionRepository;
use App\Repository\UserRepository;
use App\Controller\Api\DolarController;
use App\Security\AdminAuthTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints publicos/user del wallet.
 * Auth: sesion web O X-Game-Code header.
 */
#[Route('/api/wallet')]
class WalletController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private WalletTransactionRepository $txRepository,
    ) {}

    /**
     * Resuelve el user actual desde la sesion web o el header X-Game-Code.
     * Reutiliza el patron de GameController::authByCode.
     */
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

    #[Route('/balance', name: 'api_wallet_balance', methods: ['GET'])]
    public function balance(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado. Usa sesion o X-Game-Code header'], 401);
        }

        // Calcula equivalente en ARS usando rate blue (venta)
        $usdBalance = (float) $user->getWalletBalance();
        $arsEquivalent = null;
        $rate = null;
        try {
            $dolarController = new DolarController();
            $ratesResp = $dolarController->rates();
            $ratesData = json_decode($ratesResp->getContent(), true);
            if (isset($ratesData['blue']['sell'])) {
                $rate = (float) $ratesData['blue']['sell'];
                $arsEquivalent = round($usdBalance * $rate, 2);
            }
        } catch (\Throwable $e) {
            // Sin rate, devolvemos solo USD
        }

        return new JsonResponse([
            'user_id' => $user->getId(),
            'username' => $user->getCode(),
            'balance_usd' => number_format($usdBalance, 2, '.', ''),
            'balance_ars_equivalent' => $arsEquivalent,
            'rate_usd_ars' => $rate,
            'currency' => 'USD',
        ], 200);
    }

    #[Route('/transactions', name: 'api_wallet_transactions', methods: ['GET'])]
    public function transactions(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }

        $limit = min(100, (int) $request->query->get('limit', 50));
        $txs = $this->txRepository->findRecentForUser($user, $limit);

        $data = array_map(fn( WalletTransaction $tx ) => [
            'id' => $tx->getId(),
            'type' => $tx->getType(),
            'type_label' => $tx->getTypeLabel(),
            'amount' => $tx->getAmount(),
            'amount_formatted' => $tx->getFormattedAmount(),
            'currency' => $tx->getCurrency(),
            'is_credit' => $tx->isCredit(),
            'status' => $tx->getStatus(),
            'notes' => $tx->getNotes(),
            'ref_tournament_id' => $tx->getRefTournament()?->getId(),
            'ref_payment_id' => $tx->getRefPaymentId(),
            'ref_payment_method' => $tx->getRefPaymentMethod(),
            'created_at' => $tx->getCreatedAt()?->format('c'),
            'confirmed_at' => $tx->getConfirmedAt()?->format('c'),
        ], $txs);

        return new JsonResponse([
            'user_id' => $user->getId(),
            'count' => count($data),
            'transactions' => $data,
        ], 200);
    }

    #[Route('/withdraw', name: 'api_wallet_withdraw', methods: ['POST'])]
    public function withdraw(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['amount'])) {
            return new JsonResponse(['error' => 'Falta amount'], 400);
        }

        $amount = (float) $data['amount'];
        if ($amount <= 0) {
            return new JsonResponse(['error' => 'amount debe ser > 0'], 400);
        }
        if ($amount < 1) {
            return new JsonResponse(['error' => 'monto minimo de retiro: $1 USD'], 400);
        }

        if (!$user->hasBalance($amount)) {
            return new JsonResponse([
                'error' => 'wallet_insufficient',
                'message' => "Balance insuficiente. Tenes $" . $user->getWalletBalance() . " USD",
                'requested' => $amount,
                'available' => (float) $user->getWalletBalance(),
                'shortfall' => $amount - (float) $user->getWalletBalance(),
            ], 400);
        }

        // Descuenta inmediatamente (queda pending hasta que admin confirme el MP)
        $user->subtractFromWallet($amount);

        $tx = new WalletTransaction();
        $tx->setUser($user);
        $tx->setType(WalletTransaction::TYPE_WITHDRAW);
        $tx->setAmount(number_format(-$amount, 2, '.', ''));  // negativo
        $tx->setCurrency('USD');
        $tx->setStatus(WalletTransaction::STATUS_PENDING);
        $tx->setNotes($data['notes'] ?? null);
        $tx->setRefPaymentMethod($data['method'] ?? 'manual_mp');

        $this->em->persist($tx);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'transaction_id' => $tx->getId(),
            'amount' => $amount,
            'status' => 'pending',
            'message' => 'Tu solicitud de retiro fue creada. El admin te la confirmara cuando te transfiera por MP/Binance.',
            'new_balance_usd' => $user->getWalletBalance(),
        ], 200);
    }

    /**
     * Endpoint auxiliar para tests/devuelve user actual (por X-Game-Code)
     */
    #[Route('/me', name: 'api_wallet_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return new JsonResponse(['error' => 'No autenticado'], 401);
        }
        return new JsonResponse([
            'user_id' => $user->getId(),
            'username' => $user->getCode(),
            'display_name' => $user->getName(),
            'is_active' => $user->isActive(),
            'roles' => $user->getRoles(),
        ], 200);
    }
}
