<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/user')]
class UserSoundController extends AbstractController
{
    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
    ) {}

    private function resolveUser(Request $request): ?\App\Entity\User
    {
        $code = $request->query->get('user_code') ?? ($request->request->get('user_code') ?? null);
        if (!$code) {
            $data = json_decode($request->getContent(), true);
            $code = $data['user_code'] ?? null;
        }
        if (!$code) return null;
        $user = $this->userRepository->findByCode(strtoupper(trim($code)));
        return ($user && $user->isActive()) ? $user : null;
    }

    #[Route('/sound', name: 'api_user_sound_get', methods: ['GET'])]
    public function getSound(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        return $this->json([
            'notification_sound' => $me->getNotificationSound() ?? 'chime',
        ]);
    }

    #[Route('/sound', name: 'api_user_sound_set', methods: ['PUT'])]
    public function setSound(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $data = json_decode($request->getContent(), true);
        $sound = $data['sound'] ?? null;
        if (!$sound) return $this->json(['error' => 'sound requerido'], 400);

        $allowed = ['chime', 'mario_coin', 'zelda_secret', 'sonic_ring', 'apple_tritone', 'pixel_popcorn', 'pokemon_levelup', 'deus_ex_scan', 'indiana_jones_whip', 'msn_message', 'swoosh'];
        if (!in_array($sound, $allowed, true)) {
            return $this->json(['error' => 'Sonido no válido'], 400);
        }

        $me->setNotificationSound($sound);
        $this->em->flush();

        return $this->json(['success' => true, 'notification_sound' => $sound]);
    }
}
