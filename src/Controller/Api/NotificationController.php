<?php

namespace App\Controller\Api;

use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications')]
class NotificationController extends AbstractController
{
    private const RELATED_URLS = [
        'comment' => 'feed',
        'like' => 'feed',
        'post' => 'feed',
        'mention' => 'feed',
        'signal' => 'signals',
        'dm' => 'chat',
        'academia' => 'academia',
        'task' => 'tasks',
        'access_request' => 'social',
        'access_accepted' => 'social',
        'access_rejected' => 'social',
        'connection_removed' => 'social',
        'permissions_changed' => 'social',
        'economic_alert' => 'calendar',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private NotificationRepository $notificationRepository,
        private UserRepository $userRepository,
    ) {}

    private function getCurrentUser(Request $request): ?\App\Entity\User
    {
        $user = $this->getUser();
        if ($user instanceof \App\Entity\User) return $user;
        $code = trim($request->headers->get('X-Game-Code', ''));
        if (!$code) {
            $code = trim((string) $request->query->get('user_code', ''));
        }
        if (!$code) return null;
        return $this->userRepository->findOneBy(['code' => $code, 'active' => true]);
    }

    #[Route('', name: 'api_notif_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json([]);
        }

        $notifs = $this->notificationRepository->findByUser($user);

        $data = array_map(function ($n) {
            return [
                'id' => $n->getId(),
                'type' => $n->getType(),
                'text' => $n->getContent(),
                'ts' => $n->getCreatedAt()?->format('c'),
                'read' => $n->isRead(),
                'related_url' => self::RELATED_URLS[$n->getType()] ?? 'feed',
                'link' => $n->getLink(),
            ];
        }, $notifs);

        return $this->json($data);
    }

    #[Route('/{id}/read', name: 'api_notif_read', methods: ['PUT'])]
    public function markRead(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'No autorizado'], Response::HTTP_UNAUTHORIZED);
        }

        $notif = $this->notificationRepository->find($id);
        if (!$notif || $notif->getUser()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Notificación no encontrada'], Response::HTTP_NOT_FOUND);
        }

        $notif->setIsRead(true);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/read-all', name: 'api_notif_read_all', methods: ['PUT'])]
    public function markAllRead(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'No autorizado'], Response::HTTP_UNAUTHORIZED);
        }

        $notifs = $this->notificationRepository->findByUser($user);
        foreach ($notifs as $n) {
            $n->setIsRead(true);
        }
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/count', name: 'api_notif_count', methods: ['GET'])]
    public function count(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['count' => 0]);
        }

        return $this->json(['count' => $this->notificationRepository->countUnread($user)]);
    }

    #[Route('/{id}', name: 'api_notif_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) {
            return $this->json(['success' => false, 'error' => 'No autorizado'], Response::HTTP_UNAUTHORIZED);
        }

        $notif = $this->notificationRepository->find($id);
        if (!$notif || $notif->getUser()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Notificación no encontrada'], Response::HTTP_NOT_FOUND);
        }

        $this->em->remove($notif);
        $this->em->flush();

        return $this->json(['success' => true]);
    }
}
