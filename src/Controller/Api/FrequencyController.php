<?php

namespace App\Controller\Api;

use App\Entity\FrequencySession;
use App\Entity\UserFrequency;
use App\Repository\FrequencyPresetRepository;
use App\Repository\FrequencySessionRepository;
use App\Repository\UserFrequencyRepository;
use App\Service\FrequencySessionGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
    private const ALLOWED_AUDIO_MIMES = ['audio/mpeg', 'audio/mp3', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/x-vorbis+ogg'];
    private const ALLOWED_AUDIO_EXTS  = ['mp3', 'wav', 'ogg'];
    private const MAX_UPLOAD_BYTES    = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private EntityManagerInterface $em,
        private FrequencyPresetRepository $presetRepo,
        private UserFrequencyRepository $userFreqRepo,
        private FrequencySessionRepository $sessionRepo,
        private FrequencySessionGuard $guard,
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

    /**
     * GET /api/frequencies/session/active
     *
     * Returns the user's currently-active session (if any) so a global
     * mini-player can resume playback after navigation. 204 No Content when
     * there's nothing to resume.
     */
    #[Route('/session/active', name: 'session_active', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionActive(): JsonResponse
    {
        $payload = $this->guard->snapshotActiveSession($this->getUser());
        if ($payload === null) {
            return $this->json(null, 204);
        }
        return $this->json(['success' => true, 'session' => $payload]);
    }

    /**
     * PATCH /api/frequencies/session/{id}
     *
     * Adjust the duration of a live (not-yet-ended) session. Used to extend
     * a timer without creating a new session or to convert an infinite
     * session into a timed one.
     */
    #[Route('/session/{id}', name: 'session_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionPatch(int $id, Request $request): JsonResponse
    {
        $loaded = $this->guard->loadOwnedOrFail($id, $this->getUser());
        if (!$loaded['success']) {
            $code = $loaded['error'] === 'Session not found' ? 404 : 403;
            return $this->json(['success' => false, 'error' => $loaded['error']], $code);
        }
        /** @var FrequencySession $session */
        $session = $loaded['session'];

        if ($session->isCompleted() || $session->getEndedAt() !== null) {
            return $this->json(['success' => false, 'error' => 'Session already finished'], 409);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        if (!array_key_exists('duration_minutes', $data)) {
            return $this->json(['success' => false, 'error' => 'duration_minutes required'], 400);
        }

        $minutes = (int) $data['duration_minutes'];
        if ($minutes < 0 || $minutes > 24 * 60) {
            return $this->json(['success' => false, 'error' => 'duration_minutes out of range (0-1440)'], 400);
        }

        $session->setDurationMinutes($minutes);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'id' => $session->getId(),
            'durationMinutes' => $session->getDurationMinutes(),
        ]);
    }

    /**
     * DELETE /api/frequencies/session/{id}/abandon
     *
     * Close a session without counting its minutes. Useful when the user
     * closes the tab mid-session, OR when a stale session is detected at
     * login time.
     */
    #[Route('/session/{id}/abandon', name: 'session_abandon', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function sessionAbandon(int $id): JsonResponse
    {
        $loaded = $this->guard->loadOwnedOrFail($id, $this->getUser());
        if (!$loaded['success']) {
            $code = $loaded['error'] === 'Session not found' ? 404 : 403;
            return $this->json(['success' => false, 'error' => $loaded['error']], $code);
        }
        /** @var FrequencySession $session */
        $session = $loaded['session'];

        if ($session->isCompleted()) {
            return $this->json(['success' => false, 'error' => 'Session already ended'], 409);
        }

        $abandonedMinutes = $this->guard->abandon($session);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'abandonedMinutes' => $abandonedMinutes,
            'note' => 'Minutes discarded — session not counted toward totals.',
        ]);
    }

    /**
     * POST /api/frequencies/upload
     *
     * Upload an audio file (mp3/wav/ogg, ≤10MB) and create a UserFrequency
     * pointing at it. Reuses the existing `user_frequencies.file_path`
     * column (which had been declared in the schema but never wired up).
     */
    #[Route('/upload', name: 'upload', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'file field required (multipart/form-data)'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['success' => false, 'error' => 'upload failed: ' . $file->getErrorMessage()], 400);
        }

        $mime = (string) $file->getMimeType();
        $ext  = strtolower($file->getClientOriginalExtension());
        if (!in_array($mime, self::ALLOWED_AUDIO_MIMES, true) || !in_array($ext, self::ALLOWED_AUDIO_EXTS, true)) {
            return $this->json([
                'success' => false,
                'error' => 'unsupported audio type (allowed: mp3, wav, ogg)',
                'got' => ['mime' => $mime, 'ext' => $ext],
            ], 415);
        }

        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json([
                'success' => false,
                'error' => 'file too large (max 10MB)',
            ], 413);
        }

        $user = $this->getUser();
        $userCode = method_exists($user, 'getCode') ? (string) $user->getCode() : 'anon';
        $userDir  = 'uploads/frequencies/' . preg_replace('/[^A-Za-z0-9_-]/', '', $userCode);

        $projectDir = $this->getParameter('kernel.project_dir');
        $targetDir  = $projectDir . '/public/' . $userDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->json(['success' => false, 'error' => 'cannot create upload dir'], 500);
        }

        $hash = bin2hex(random_bytes(8));
        $filename = sprintf('%s.%s', $hash, $ext);
        $file->move($targetDir, $filename);
        $relativePath = $userDir . '/' . $filename;

        $name = trim((string) $request->request->get('name', pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)));
        if ($name === '') {
            $name = 'Frecuencia ' . substr($hash, 0, 6);
        }

        $frequency = (int) $request->request->get('frequency', 432);
        if ($frequency < 50 || $frequency > 2000) {
            $frequency = 432;
        }

        $notes = $request->request->get('notes');
        if ($notes !== null) {
            $notes = mb_substr(trim((string) $notes), 0, 500);
        }

        $uf = new UserFrequency();
        $uf->setUser($user);
        $uf->setName($name);
        $uf->setFrequency($frequency);
        $uf->setType('custom_upload');
        $uf->setFilePath('/' . $relativePath);
        $uf->setNotes($notes);
        $this->em->persist($uf);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'id' => $uf->getId(),
            'filePath' => $uf->getFilePath(),
            'url' => $uf->getFilePath(),
            'name' => $uf->getName(),
            'frequency' => $uf->getFrequency(),
            'type' => $uf->getType(),
        ], 201);
    }
}