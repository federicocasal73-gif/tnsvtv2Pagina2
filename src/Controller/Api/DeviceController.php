<?php

namespace App\Controller\Api;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/devices')]
class DeviceController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DeviceRepository $deviceRepository,
        private UserRepository $userRepository,
    ) {}

    #[Route('/register', name: 'api_device_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        $userCode = trim($data['user_code'] ?? '');
        $fcmToken = trim($data['fcm_token'] ?? '');
        $platform = trim($data['platform'] ?? 'android') ?: 'android';
        $deviceModel = isset($data['device_model']) ? trim((string) $data['device_model']) : null;

        if ($userCode === '' || $fcmToken === '') {
            return $this->json(['error' => 'user_code y fcm_token son requeridos'], 400);
        }

        $user = $this->userRepository->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        $device = $this->deviceRepository->findByToken($fcmToken);
        if (!$device) {
            $device = new Device();
            $device->setFcmToken($fcmToken);
        } else {
            $device->touch();
        }
        $device->setUser($user);
        $device->setPlatform($platform);
        if ($deviceModel !== null) $device->setDeviceModel($deviceModel);

        $this->em->persist($device);
        $this->em->flush();

        return $this->json(['success' => true, 'device_id' => $device->getId()]);
    }

    #[Route('/unregister', name: 'api_device_unregister', methods: ['POST'])]
    public function unregister(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $fcmToken = trim($data['fcm_token'] ?? '');

        if ($fcmToken === '') {
            return $this->json(['error' => 'fcm_token requerido'], 400);
        }

        $device = $this->deviceRepository->findByToken($fcmToken);
        if ($device) {
            $this->em->remove($device);
            $this->em->flush();
        }

        return $this->json(['success' => true]);
    }
}
