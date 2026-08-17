<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
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
    #[Route('/api/mercure/subscribe-token', name: 'api_mercure_subscribe_token', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribeToken(Request $request, HubInterface $hub): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $topics = (array) $request->request->all('topics');

        $token = $hub->getFactory()->create(
            subscribe: $topics,
            publish: null,
            additionalClaims: $user ? ['mercure.subscribe' => ['*']] : [],
        );

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
}