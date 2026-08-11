<?php

namespace App\Controller\Api;

use App\Entity\TradingAccount;
use App\Entity\User;
use App\Repository\JournalEntryRepository;
use App\Repository\TradingAccountRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/accounts')]
class TradingAccountController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TradingAccountRepository $accountRepo,
        private UserRepository $userRepository,
        private JournalEntryRepository $journalEntryRepo,
    ) {}

    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            $code = trim($data['user_code'] ?? '');
        }
        if (!$code) {
            $code = trim($request->query->get('user_code', ''));
        }
        if (!$code) return null;
        return $this->userRepository->findByCode($code);
    }

    #[Route('', name: 'api_accounts_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $accounts = $this->accountRepo->findActiveByUser($user);
        $count = count($accounts);

        $payload = array_map(function (TradingAccount $a) use ($user) {
            return [
                'id' => $a->getId(),
                'name' => $a->getName(),
                'account_size' => (float) $a->getAccountSize(),
                'color' => $a->getColor(),
                'icon' => $a->getIcon(),
                'is_active' => $a->isActive(),
                'sort_order' => $a->getSortOrder(),
                'created_at' => $a->getCreatedAt()?->format(\DateTimeInterface::ATOM),
                'trade_count' => $this->journalEntryRepo->countByUserAndAccount($user->getCode(), (string) $a->getId()),
            ];
        }, $accounts);

        return $this->json([
            'success' => true,
            'accounts' => $payload,
            'current_count' => $count,
            'max_accounts' => $user->getMaxAccounts(),
        ]);
    }

    #[Route('', name: 'api_accounts_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $currentCount = $this->accountRepo->countActiveByUser($user);
        $max = $user->getMaxAccounts();
        if ($currentCount >= $max) {
            return $this->json([
                'success' => false,
                'error' => sprintf('Plan actual: %d/%d cuentas. Elimina una cuenta existente para crear una nueva.', $currentCount, $max),
            ], 403);
        }

        $data = json_decode($request->getContent(), true);
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return $this->json(['error' => 'name requerido'], 400);
        }
        if (mb_strlen($name) > 50) {
            return $this->json(['error' => 'name maximo 50 caracteres'], 400);
        }

        if ($this->accountRepo->findByNameAndUser($user, $name)) {
            return $this->json(['error' => 'Ya tenes una cuenta con ese nombre'], 409);
        }

        $account = new TradingAccount();
        $account->setUser($user);
        $account->setName($name);
        $account->setAccountSize((float) ($data['account_size'] ?? 10000));
        $account->setColor((string) ($data['color'] ?? '#d4af37'));
        $account->setIcon((string) ($data['icon'] ?? '💰'));
        $account->setSortOrder($currentCount);

        $this->em->persist($account);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'account' => [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'account_size' => (float) $account->getAccountSize(),
                'color' => $account->getColor(),
                'icon' => $account->getIcon(),
            ],
            'current_count' => $currentCount + 1,
            'max_accounts' => $max,
        ], 201);
    }

    #[Route('/{id}', name: 'api_accounts_update', methods: ['PUT', 'PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $account = $this->accountRepo->find($id);
        if (!$account) return $this->json(['error' => 'Cuenta no encontrada'], 404);
        if ($account->getUser() !== $user) {
            return $this->json(['error' => 'Solo puedes editar tus cuentas'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['name'])) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                return $this->json(['error' => 'name no puede ser vacio'], 400);
            }
            if (mb_strlen($name) > 50) {
                return $this->json(['error' => 'name maximo 50 caracteres'], 400);
            }
            $existing = $this->accountRepo->findByNameAndUser($user, $name);
            if ($existing && $existing->getId() !== $account->getId()) {
                return $this->json(['error' => 'Ya tenes una cuenta con ese nombre'], 409);
            }
            $account->setName($name);
        }

        if (isset($data['account_size'])) {
            $account->setAccountSize((float) $data['account_size']);
        }
        if (isset($data['color'])) {
            $account->setColor((string) $data['color']);
        }
        if (isset($data['icon'])) {
            $account->setIcon((string) $data['icon']);
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'account' => [
                'id' => $account->getId(),
                'name' => $account->getName(),
                'account_size' => (float) $account->getAccountSize(),
                'color' => $account->getColor(),
                'icon' => $account->getIcon(),
            ],
        ]);
    }

    #[Route('/{id}', name: 'api_accounts_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $account = $this->accountRepo->find($id);
        if (!$account) return $this->json(['error' => 'Cuenta no encontrada'], 404);
        if ($account->getUser() !== $user) {
            return $this->json(['error' => 'Solo puedes eliminar tus cuentas'], 403);
        }

        if ($account->isDeleted()) {
            return $this->json(['error' => 'La cuenta ya fue eliminada'], 400);
        }

        $account->softDelete();
        $this->em->flush();

        $remaining = $this->accountRepo->countActiveByUser($user);

        return $this->json([
            'success' => true,
            'account_id' => $id,
            'message' => 'Cuenta eliminada. Los trades fueron preservados.',
            'current_count' => $remaining,
            'max_accounts' => $user->getMaxAccounts(),
        ]);
    }
}
