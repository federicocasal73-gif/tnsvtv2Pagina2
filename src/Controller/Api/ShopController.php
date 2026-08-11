<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Entity\ShopPurchase;
use App\Repository\ShopItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/shop')]
class ShopController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ShopItemRepository $shopItemRepo,
    ) {}

    /**
     * GET /api/shop/items?category=frame
     * Returns active shop items catalog.
     */
    #[Route('/items', name: 'api_shop_items', methods: ['GET'])]
    public function items(Request $request): JsonResponse
    {
        $category = $request->query->get('category');
        $items = $this->shopItemRepo->findActive($category);
        return new JsonResponse([
            'items' => array_map(fn($i) => $i->toArray(), $items),
        ]);
    }

    /**
     * POST /api/shop/purchase  { itemId: "fr_bronze" }
     * Spends user's coins, registers purchase in shop_purchases.
     * Idempotent: if already owned, returns success without re-charging.
     */
    #[Route('/purchase', name: 'api_shop_purchase', methods: ['POST'])]
    public function purchase(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $itemId = trim($data['itemId'] ?? '');
        if (empty($itemId)) {
            return new JsonResponse(['error' => 'itemId_required'], 400);
        }

        $item = $this->shopItemRepo->findOneBy(['itemId' => $itemId, 'active' => true]);
        if (!$item) {
            return new JsonResponse(['error' => 'item_not_found'], 404);
        }

        // Already owned?
        $owned = $this->shopItemRepo->userHasPurchased($user, $itemId);
        if ($owned) {
            return new JsonResponse([
                'success' => true,
                'alreadyOwned' => true,
                'itemId' => $itemId,
                'coins' => $user->getCoins(),
            ]);
        }

        // Validate spend
        $cost = (int) $item->getCoinCost();
        if ($cost > 0 && $user->getCoins() < $cost) {
            return new JsonResponse(['error' => 'insufficient_coins', 'needed' => $cost, 'have' => $user->getCoins()], 400);
        }

        // Charge
        if ($cost > 0) $user->spendCoins($cost);
        // Register purchase
        $purchase = (new ShopPurchase())
            ->setUser($user)
            ->setItemId($itemId)
            ->setCoinsSpent($cost);
        $this->em->persist($purchase);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'itemId' => $itemId,
            'name' => $item->getName(),
            'category' => $item->getCategory(),
            'coinsSpent' => $cost,
            'coins' => $user->getCoins(),
        ]);
    }

    /**
     * GET /api/shop/user-owned
     * List of itemIds the user has purchased.
     */
    #[Route('/user-owned', name: 'api_shop_user_owned', methods: ['GET'])]
    public function userOwned(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse(['owned' => []]);
        }
        $owned = $this->shopItemRepo->findUserOwnedIds($user);
        return new JsonResponse([
            'owned' => $owned,
        ]);
    }

    /**
     * POST /api/shop/equip  { category: "frame", itemId: "fr_bronze" }
     * Marks the itemId as the active one for that category.
     * Persisted in user.shopEquipped (JSON).
     */
    #[Route('/equip', name: 'api_shop_equip', methods: ['POST'])]
    public function equip(Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        $user = $user ?? $this->authByCode($request);
        if (!$user) {
            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $data = json_decode($request->getContent(), true) ?: [];
        $category = trim($data['category'] ?? '');
        $itemId = trim($data['itemId'] ?? '');
        if (empty($category) || empty($itemId)) {
            return new JsonResponse(['error' => 'category_and_itemId_required'], 400);
        }

        $equipped = $user->getShopEquipped() ?? [];
        $equipped[$category] = $itemId;
        $user->setShopEquipped($equipped);

        // Grant the item if not yet owned (equip != purchase, but for free items we auto-own)
        if (!$this->shopItemRepo->userHasPurchased($user, $itemId)) {
            $purchase = (new ShopPurchase())
                ->setUser($user)
                ->setItemId($itemId)
                ->setCoinsSpent(0);
            $this->em->persist($purchase);
        }

        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'category' => $category,
            'itemId' => $itemId,
            'equipped' => $equipped,
        ]);
    }

    private function authByCode(Request $request): ?User
    {
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            if (is_array($data) && isset($data['code'])) $code = trim((string)$data['code']);
        }
        if (!$code) return null;
        return $this->em->getRepository(User::class)->findOneBy(['code' => $code, 'active' => true]);
    }
}
