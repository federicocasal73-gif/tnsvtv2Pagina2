<?php

namespace App\Controller\Api;

use App\Entity\DiaryEntry;
use App\Entity\User;
use App\Repository\DiaryEntryRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/diary')]
class DiaryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private DiaryEntryRepository $diaryRepo,
        private UserRepository $userRepository,
    ) {}

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

    #[Route('', name: 'api_diary_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $entries = $this->diaryRepo->findByUser($user);

        return new JsonResponse([
            'success' => true,
            'entries' => array_map(fn(DiaryEntry $e) => [
                'id' => $e->getId(),
                'encrypted_data' => $e->getEncryptedData(),
                'iv' => $e->getIv(),
                'created_at' => $e->getCreatedAt()->format('c'),
                'updated_at' => $e->getUpdatedAt()?->format('c'),
            ], $entries),
        ]);
    }

    #[Route('', name: 'api_diary_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['encrypted_data'])) {
            return new JsonResponse(['error' => 'encrypted_data required'], 400);
        }

        $entry = new DiaryEntry();
        $entry->setUser($user);
        $entry->setEncryptedData($data['encrypted_data']);
        $entry->setIv($data['iv'] ?? '');
        $entry->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($entry);
        $this->em->flush();

        return new JsonResponse([
            'success' => true,
            'id' => $entry->getId(),
            'created_at' => $entry->getCreatedAt()->format('c'),
        ], 201);
    }

    #[Route('/{id}', name: 'api_diary_update', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $entry = $this->diaryRepo->find($id);
        if (!$entry || $entry->getUser() !== $user) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (isset($data['encrypted_data'])) $entry->setEncryptedData($data['encrypted_data']);
        if (isset($data['iv'])) $entry->setIv($data['iv']);
        $entry->setUpdatedAt(new \DateTime());

        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/{id}', name: 'api_diary_delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        $entry = $this->diaryRepo->find($id);
        if (!$entry || $entry->getUser() !== $user) {
            return new JsonResponse(['error' => 'Not found'], 404);
        }

        $this->em->remove($entry);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/setup', name: 'api_diary_setup', methods: ['POST', 'GET'])]
    public function setup(Request $request): JsonResponse
    {
        $user = $this->getCurrentUser($request);
        if (!$user) return new JsonResponse(['error' => 'Unauthorized'], 401);

        if ($request->isMethod('GET')) {
            if ($user->getDiarySetupToken()) {
                return new JsonResponse([
                    'success' => true,
                    'setup_token' => $user->getDiarySetupToken(),
                    'setup_iv' => $user->getDiarySetupIv(),
                ]);
            }
            return new JsonResponse(['success' => true, 'setup_token' => null]);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || empty($data['setup_token'])) {
            return new JsonResponse(['error' => 'setup_token required'], 400);
        }

        $user->setDiarySetupToken($data['setup_token']);
        $user->setDiarySetupIv($data['setup_iv'] ?? null);
        $this->em->flush();

        return new JsonResponse(['success' => true]);
    }
}
