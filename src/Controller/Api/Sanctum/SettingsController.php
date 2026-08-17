<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\Setting;
use App\Repository\SettingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Settings API — Phase 1c.
 * Admin-only CRUD for global settings.
 */
#[Route('/sanctum/api/settings', name: 'sanctum_api_settings_')]
#[IsGranted('ROLE_ADMIN')]
class SettingsController extends AbstractController
{
    public function __construct(
        private SettingRepository $settingRepo,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $settings = $this->settingRepo->findAll();
        $byCategory = [];
        foreach ($settings as $s) {
            $cat = $s->getCategory() ?? 'general';
            $byCategory[$cat][] = [
                'key' => $s->getKey(),
                'value' => $s->getValue(),
                'category' => $cat,
                'description' => $s->getDescription(),
                'updated_at' => $s->getUpdatedAt()->format('c'),
            ];
        }
        return $this->json([
            'success' => true,
            'count' => count($settings),
            'by_category' => $byCategory,
        ]);
    }

    #[Route('/{key}', name: 'get', methods: ['GET'])]
    public function get(string $key): JsonResponse
    {
        $setting = $this->settingRepo->find($key);
        if (!$setting) {
            return $this->json(['success' => false, 'error' => 'Setting not found'], 404);
        }
        return $this->json([
            'success' => true,
            'setting' => [
                'key' => $setting->getKey(),
                'value' => $setting->getValue(),
                'category' => $setting->getCategory(),
                'description' => $setting->getDescription(),
                'updated_at' => $setting->getUpdatedAt()->format('c'),
            ],
        ]);
    }

    #[Route('/{key}', name: 'update', methods: ['PATCH', 'PUT'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(string $key, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!isset($data['value'])) {
            return $this->json(['success' => false, 'error' => 'Missing "value" field'], 400);
        }

        $setting = $this->settingRepo->find($key);
        if (!$setting) {
            // Create new
            $setting = new Setting();
            $setting->setKey($key);
            $setting->setCategory($data['category'] ?? 'general');
            $setting->setDescription($data['description'] ?? null);
            $this->em->persist($setting);
        }
        $setting->setValue((string)$data['value']);
        $setting->setUpdatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $this->json([
            'success' => true,
            'setting' => [
                'key' => $setting->getKey(),
                'value' => $setting->getValue(),
                'category' => $setting->getCategory(),
                'description' => $setting->getDescription(),
            ],
        ]);
    }

    #[Route('/{key}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(string $key): JsonResponse
    {
        $setting = $this->settingRepo->find($key);
        if (!$setting) {
            return $this->json(['success' => false, 'error' => 'Setting not found'], 404);
        }
        $this->em->remove($setting);
        $this->em->flush();
        return $this->json(['success' => true, 'deleted' => $key]);
    }
}