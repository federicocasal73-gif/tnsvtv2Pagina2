<?php

namespace App\Controller\Api;

use App\Entity\FrequencySession;
use App\Entity\UserFrequency;
use App\Repository\FrequencyPresetRepository;
use App\Repository\FrequencySessionRepository;
use App\Repository\UserFrequencyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Frequencies / Audio Hub API (Phase 3 — Santuario de Frecuencias).
 * Available to all authenticated users.
 */
#[Route('/api/frequencies', name: 'api_frequencies_')]
class FrequencyController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private FrequencyPresetRepository $presetRepo,
        private UserFrequencyRepository $userFreqRepo,
        private FrequencySessionRepository $sessionRepo,
    ) {}

    #[Route('/presets', name: 'presets', methods: ['GET'])]
    public function presets(): JsonResponse
    {
        $presets = $this->presetRepo->findAllActive();
        return $this->json([
            'success' => true,
            'count' => count($presets),
            'presets' => array_map(fn($p) => $p->toArray(), $presets),
        ]);
    }

    #[Route('/mine', name: 'mine', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function mine(): JsonResponse
    {
        $freqs = $this->userFreqRepo->findByUser($this->getUser());
        return $this->json([
            'success' => true,
            'count' => count($freqs),
            'frequencies' => array_map(fn($f) => $f->toArray(), $freqs),
        ]);
    }

    #[Route('/add', name: 'add', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function add(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $name = trim($data['name'] ?? '');
        $frequency = (int)($data['frequency'] ?? 432);

        if (empty($name) || $frequency < 50 || $frequency > 2000) {
            return $this->json(['success' => false, 'error' => 'Invalid name or frequency (50-2000Hz)'], 400);
        }

        $uf = new UserFrequency();
        $uf->setUser($this->getUser());
        $uf->setName($name);
        $uf->setFrequency($frequency);
        $uf->setType($data['type'] ?? 'custom_generated');
        $uf->setNotes($data['notes'] ?? null);
        $this->em->persist($uf);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $uf->getId()], 201);
    }

    #[Route('/session/start', name: 'session_start', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionStart(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $duration = (int)($data['duration_minutes'] ?? 15);

        $session = new FrequencySession();
        $session->setUser($this->getUser());
        $session->setDurationMinutes($duration);

        if (isset($data['preset_id'])) {
            $preset = $this->presetRepo->find($data['preset_id']);
            if ($preset) $session->setPreset($preset);
        }
        if (isset($data['user_frequency_id'])) {
            $uf = $this->userFreqRepo->find($data['user_frequency_id']);
            if ($uf) $session->setUserFrequency($uf);
        }

        $this->em->persist($session);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $session->getId(), 'started_at' => $session->getStartedAt()->format('c')]);
    }

    #[Route('/session/{id}/end', name: 'session_end', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionEnd(int $id): JsonResponse
    {
        $session = $this->sessionRepo->find($id);
        if (!$session || $session->getUser() !== $this->getUser()) {
            return $this->json(['success' => false, 'error' => 'Session not found'], 404);
        }
        $session->setEndedAt(new \DateTimeImmutable());
        $session->setCompleted(true);
        $this->em->flush();

        return $this->json(['success' => true, 'minutes' => $session->getDurationMinutes()]);
    }

    #[Route('/stats', name: 'stats', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function stats(): JsonResponse
    {
        $totalMinutes = $this->sessionRepo->getTotalMinutesForUser($this->getUser());
        $activeSessions = $this->sessionRepo->findActiveByUser($this->getUser());

        return $this->json([
            'success' => true,
            'totalMinutes' => $totalMinutes,
            'totalHours' => round($totalMinutes / 60, 1),
            'activeSessions' => count($activeSessions),
        ]);
    }
}