<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Server-Sent Events endpoint for real-time updates via Mercure.
 * Clients (chat, notifications, leaderboard) subscribe to /chat/{id}, /user/{id}/notifications, /tournament/{id}.
 *
 * Note: For Mercure SSE, the browser subscribes directly to the hub URL
 * (this controller is mostly for token generation / fallback polling).
 */
class MercureController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/mercure/subscribe-token', name: 'api_mercure_subscribe_token', methods: ['POST'])]
    public function subscribeToken(Request $request, HubInterface $hub): JsonResponse
    {
        // Auth: Symfony session user OR X-Game-Code (same convention as
        // ChatController::resolveUser — the chat widget authenticates with
        // the code header, not with a session).
        $user = $this->getUser();
        if (!$user instanceof User) {
            $user = $this->resolveUserByCode($request);
        }
        if (!$user) {
            return $this->json(['error' => 'user_code requerido'], 401);
        }

        // Topics: JSON body first (what apiFetch sends), form bag fallback.
        // $request->request is only populated for form-encoded posts, so a
        // JSON body would silently yield [] without the getContent() branch
        // (bug 2026-09-29: token always scoped to zero topics).
        $rawTopics = [];
        $body = json_decode($request->getContent(), true);
        $bodyTopics = $body['topics'] ?? null;
        if (is_array($bodyTopics)) {
            $rawTopics = $bodyTopics;
        } else {
            $rawTopics = $request->request->all('topics');
        }
        /** @var list<string> $topics */
        $topics = array_values(array_filter(array_map(
            fn ($t) => is_string($t) ? trim($t) : '',
            $rawTopics
        ), fn ($t) => $t !== '' && str_starts_with($t, '/')));
        $topics = array_slice($topics, 0, 20);

        try {
            $factory = $hub->getFactory();
            if (null === $factory) {
                return $this->json(['error' => 'realtime no disponible'], 503);
            }
            // Locked vendor is symfony/mercure 0.7.2, whose factory signs
            // create(?array $subscribe, ?array $publish, array
            // $additionalClaims). Prod briefly ran a stale 0.6-era vendor
            // copy (create(array $grants, ...)) which 500'd on the named
            // arg — fixed by re-syncing prod vendor with composer.lock
            // (bug 2026-09-29: "Unknown named parameter $subscribe").
            $token = $factory->create(subscribe: $topics, publish: null);
        } catch (\Throwable $e) {
            // Never 500 the widget: realtime is best-effort, clients poll.
            $this->logger->warning('[Mercure] subscribe-token failed, falling back to polling', [
                'error' => $e->getMessage(),
            ]);

            return $this->json(['error' => 'realtime no disponible'], 503);
        }

        return new JsonResponse(['token' => $token]);
    }

    #[Route('/api/mercure/publish-test', name: 'api_mercure_publish_test', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function publishTest(Request $request, HubInterface $hub): JsonResponse
    {
        $hub->publish(new Update(
            '/test-channel',
            json_encode([
                'message' => $request->request->get('message', 'hello'),
                'timestamp' => time(),
            ], JSON_THROW_ON_ERROR),
        ));
        return new JsonResponse(['success' => true]);
    }

    private function resolveUserByCode(Request $request): ?User
    {
        // Same order as ChatController::resolveUser: header > query > body.
        $code = $request->headers->get('X-Game-Code', '');
        if (!$code) {
            $code = $request->query->get('user_code') ?? $request->request->get('user_code');
        }
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            $code = is_array($data) ? ($data['user_code'] ?? null) : null;
        }
        if (!$code || !is_string($code)) return null;
        $user = $this->userRepository->findByCode(strtoupper(trim($code)));

        return ($user && $user->isActive()) ? $user : null;
    }
}