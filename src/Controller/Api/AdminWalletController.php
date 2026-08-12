<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\WalletTransaction;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use App\Security\AdminAuthTrait;
use App\Service\PushNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints admin para acreditar/debitar wallets y gestionar withdrawals.
 * Auth: header X-Admin-Password.
 */
#[Route('/api/admin/wallet')]
class AdminWalletController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private WalletTransactionRepository $txRepository,
        private PushNotificationService $push,
    ) {}

    /**
     * Acredita USD a un user (uso: confirmaste MP/Binance/crypto).
     * Crea una wallet_transaction type=deposit.
     */
    #[Route('/credit', name: 'api_admin_wallet_credit', methods: ['POST'])]
    public function credit(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['code']) || !isset($data['amount'])) {
            return new JsonResponse(['error' => 'Falta code y/o amount'], 400);
        }

        $code = trim((string) $data['code']);
        $amount = (float) $data['amount'];
        $notes = $data['notes'] ?? null;
        $method = $data['method'] ?? WalletTransaction::METHOD_MANUAL_MP;
        $paymentId = $data['payment_id'] ?? null;

        if ($amount <= 0) {
            return new JsonResponse(['error' => 'amount debe ser > 0'], 400);
        }

        $user = $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_found', 'code' => $code], 404);
        }

        // Idempotencia: si ya hay una tx con este payment_id, no duplicar
        if ($paymentId) {
            $existing = $this->txRepository->findByPaymentId($paymentId);
            if ($existing) {
                return new JsonResponse([
                    'success' => true,
                    'idempotent' => true,
                    'transaction_id' => $existing->getId(),
                    'message' => 'Ya procesado',
                ], 200);
            }
        }

        // Atomic credit with transaction
        $this->em->getConnection()->beginTransaction();
        try {
            $affected = $this->em->getConnection()->executeStatement(
                'UPDATE "user" SET wallet_balance = CAST(wallet_balance AS REAL) + :amount WHERE id = :id',
                ['amount' => $amount, 'id' => $user->getId()]
            );
            if ($affected === 0) {
                $this->em->getConnection()->rollBack();
                return new JsonResponse(['error' => 'user_not_found'], 404);
            }
            $this->em->refresh($user);

            $tx = new WalletTransaction();
            $tx->setUser($user);
            $tx->setType(WalletTransaction::TYPE_DEPOSIT);
            $tx->setAmount(number_format($amount, 2, '.', ''));
            $tx->setCurrency('USD');
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setNotes($notes);
            $tx->setRefPaymentId($paymentId);
            $tx->setRefPaymentMethod($method);
            $tx->setConfirmedAt(new \DateTimeImmutable());

            $this->em->persist($tx);
            $this->em->flush();
            $this->em->getConnection()->commit();

            // Push notification (fuera de la transacción)
            $this->push->sendToUser($user, 'Saldo acreditado', "Recibiste \${$amount} USD en tu wallet");

            return new JsonResponse([
                'success' => true,
                'transaction_id' => $tx->getId(),
                'user_id' => $user->getId(),
                'username' => $user->getCode(),
                'amount' => number_format($amount, 2, '.', ''),
                'new_balance_usd' => $user->getWalletBalance(),
                'method' => $method,
            ], 200);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return new JsonResponse(['error' => 'Error al acreditar saldo'], 500);
        }
    }

    /**
     * Debita USD de un user (uso: pagaste un payout, queres descontar del wallet).
     */
    #[Route('/debit', name: 'api_admin_wallet_debit', methods: ['POST'])]
    public function debit(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['code']) || !isset($data['amount'])) {
            return new JsonResponse(['error' => 'Falta code y/o amount'], 400);
        }

        $code = trim((string) $data['code']);
        $amount = (float) $data['amount'];
        $notes = $data['notes'] ?? null;
        $method = $data['method'] ?? WalletTransaction::METHOD_MANUAL_MP;

        if ($amount <= 0) {
            return new JsonResponse(['error' => 'amount debe ser > 0'], 400);
        }

        $user = $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
        if (!$user) {
            return new JsonResponse(['error' => 'user_not_found', 'code' => $code], 404);
        }

        // Atomic debit with transaction
        $this->em->getConnection()->beginTransaction();
        try {
            $affected = $this->em->getConnection()->executeStatement(
                'UPDATE "user" SET wallet_balance = CAST(wallet_balance AS REAL) - :amount WHERE id = :id AND CAST(wallet_balance AS REAL) >= :amount2',
                ['amount' => $amount, 'id' => $user->getId(), 'amount2' => $amount]
            );
            if ($affected === 0) {
                $this->em->getConnection()->rollBack();
                $currentBalance = $this->em->getConnection()->fetchOne(
                    'SELECT wallet_balance FROM "user" WHERE id = :id', ['id' => $user->getId()]
                );
                return new JsonResponse([
                    'error' => 'wallet_insufficient',
                    'available' => (float) $currentBalance,
                    'requested' => $amount,
                ], 400);
            }
            $this->em->refresh($user);

            $tx = new WalletTransaction();
            $tx->setUser($user);
            $tx->setType(WalletTransaction::TYPE_WITHDRAW);
            $tx->setAmount(number_format(-$amount, 2, '.', ''));
            $tx->setCurrency('USD');
            $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
            $tx->setNotes($notes);
            $tx->setRefPaymentMethod($method);
            $tx->setConfirmedAt(new \DateTimeImmutable());

            $this->em->persist($tx);
            $this->em->flush();
            $this->em->getConnection()->commit();

            return new JsonResponse([
                'success' => true,
                'transaction_id' => $tx->getId(),
                'user_id' => $user->getId(),
                'amount' => number_format($amount, 2, '.', ''),
                'new_balance_usd' => $user->getWalletBalance(),
            ], 200);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return new JsonResponse(['error' => 'Error al debitar saldo'], 500);
        }
    }

    /**
     * Lista de withdrawals pendientes (para que el admin sepa a quien pagarle).
     */
    #[Route('/pending', name: 'api_admin_wallet_pending', methods: ['GET'])]
    public function pending(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $txs = $this->txRepository->findPendingWithdraws(50);
        $data = array_map(fn( WalletTransaction $tx ) => [
            'transaction_id' => $tx->getId(),
            'user_id' => $tx->getUser()?->getId(),
            'username' => $tx->getUser()?->getCode(),
            'display_name' => $tx->getUser()?->getName(),
            'amount' => $tx->getAmount(),
            'amount_abs' => abs((float) $tx->getAmount()),
            'method' => $tx->getRefPaymentMethod(),
            'notes' => $tx->getNotes(),
            'created_at' => $tx->getCreatedAt()?->format('c'),
        ], $txs);

        return new JsonResponse([
            'count' => count($data),
            'pending_withdraws' => $data,
        ], 200);
    }

    /**
     * Aprueba un withdraw pendiente (marca como confirmed, NO devuelve el dinero porque ya se desconto).
     */
    #[Route('/withdraw/{id}/approve', name: 'api_admin_wallet_withdraw_approve', methods: ['POST'])]
    public function approveWithdraw(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $tx = $this->txRepository->find($id);
        if (!$tx) {
            return new JsonResponse(['error' => 'transaction_not_found'], 404);
        }
        if ($tx->getType() !== WalletTransaction::TYPE_WITHDRAW) {
            return new JsonResponse(['error' => 'No es un withdraw'], 400);
        }
        if ($tx->getStatus() !== WalletTransaction::STATUS_PENDING) {
            return new JsonResponse([
                'error' => 'already_processed',
                'current_status' => $tx->getStatus(),
            ], 400);
        }

        $tx->setStatus(WalletTransaction::STATUS_CONFIRMED);
        $tx->setConfirmedAt(new \DateTimeImmutable());
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'transaction_id' => $tx->getId(),
            'status' => 'confirmed',
            'message' => 'Withdraw aprobado. Transferile al user por MP/Binance.',
        ], 200);
    }

    /**
     * Rechaza un withdraw (devuelve el dinero al user).
     */
    #[Route('/withdraw/{id}/reject', name: 'api_admin_wallet_withdraw_reject', methods: ['POST'])]
    public function rejectWithdraw(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $tx = $this->txRepository->find($id);
        if (!$tx) {
            return new JsonResponse(['error' => 'transaction_not_found'], 404);
        }
        if ($tx->getType() !== WalletTransaction::TYPE_WITHDRAW) {
            return new JsonResponse(['error' => 'No es un withdraw'], 400);
        }
        if ($tx->getStatus() !== WalletTransaction::STATUS_PENDING) {
            return new JsonResponse([
                'error' => 'already_processed',
                'current_status' => $tx->getStatus(),
            ], 400);
        }

        $amount = abs((float) $tx->getAmount());
        $user = $tx->getUser();

        // Atomic refund with transaction
        $this->em->getConnection()->beginTransaction();
        try {
            $affected = $this->em->getConnection()->executeStatement(
                'UPDATE "user" SET wallet_balance = CAST(wallet_balance AS REAL) + :amount WHERE id = :id',
                ['amount' => $amount, 'id' => $user->getId()]
            );
            if ($affected === 0) {
                $this->em->getConnection()->rollBack();
                return new JsonResponse(['error' => 'user_not_found'], 404);
            }
            $this->em->refresh($user);

            $tx->setStatus(WalletTransaction::STATUS_REJECTED);
            $tx->setConfirmedAt(new \DateTimeImmutable());
            $tx->setNotes(($tx->getNotes() ?? '') . ' [REJECTED - refunded]');
            $this->em->flush();
            $this->em->getConnection()->commit();

            return new JsonResponse([
                'success' => true,
                'transaction_id' => $tx->getId(),
                'status' => 'rejected',
                'refunded_amount' => number_format($amount, 2, '.', ''),
                'new_balance_usd' => $user->getWalletBalance(),
                'message' => 'Withdraw rechazado. Le devolvimos el saldo al user.',
            ], 200);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();
            return new JsonResponse(['error' => 'Error al rechazar withdraw'], 500);
        }
    }

    /**
     * Auditoria: ultimas 200 transacciones de todos los users.
     */
    #[Route('/transactions', name: 'api_admin_wallet_transactions', methods: ['GET'])]
    public function allTransactions(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $limit = min(200, (int) $request->query->get('limit', 100));
        $txs = $this->em->getRepository(WalletTransaction::class)
            ->createQueryBuilder('w')
            ->orderBy('w.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        $data = array_map(fn( WalletTransaction $tx ) => [
            'id' => $tx->getId(),
            'user_id' => $tx->getUser()?->getId(),
            'username' => $tx->getUser()?->getCode(),
            'type' => $tx->getType(),
            'type_label' => $tx->getTypeLabel(),
            'amount' => $tx->getAmount(),
            'is_credit' => $tx->isCredit(),
            'status' => $tx->getStatus(),
            'method' => $tx->getRefPaymentMethod(),
            'payment_id' => $tx->getRefPaymentId(),
            'notes' => $tx->getNotes(),
            'created_at' => $tx->getCreatedAt()?->format('c'),
        ], $txs);

        return new JsonResponse([
            'count' => count($data),
            'transactions' => $data,
        ], 200);
    }
}
