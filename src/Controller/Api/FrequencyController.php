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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
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

    /**
     * GET /api/frequencies/stream/{id}
     *
     * Secure stream for a user-uploaded audio file. The file lives under
     * public/uploads/frequencies/{userCode}/{hash}.{ext} which is ALSO
     * blocked by .htaccess (defense in depth) — this endpoint is the
     * only authorised playback path.
     *
     * Owner OR admin can stream. Returns 404 if the frequency has no
     * upload (custom_generated entries have no file).
     *
     * NOTE: method name is `serveAudio` (not `stream`) to avoid clashing
     * with AbstractController::stream() which renders a streamed view.
     */
    #[Route('/stream/{id}', name: 'stream', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function serveAudio(int $id): Response
    {
        $uf = $this->userFreqRepo->find($id);
        if (!$uf) {
            return new JsonResponse(['success' => false, 'error' => 'Frequency not found'], 404);
        }
        if ($uf->getType() !== 'custom_upload' || !$uf->getFilePath()) {
            return new JsonResponse([
                'success' => false,
                'error' => 'This frequency has no audio file (it is a custom-generated tone)',
            ], 404);
        }

        $current = $this->getUser();
        $isOwner = $current === $uf->getUser();
        $isAdmin = is_array($current?->getRoles()) && in_array('ROLE_ADMIN', $current->getRoles(), true);
        if (!$isOwner && !$isAdmin) {
            return new JsonResponse(['success' => false, 'error' => 'Forbidden'], 403);
        }

        // filePath is stored as `/uploads/frequencies/USER/HASH.ext` (with
        // leading slash) — strip the slash and resolve from public/.
        $rel = ltrim($uf->getFilePath(), '/');
        $projectDir = $this->getParameter('kernel.project_dir');
        $absPath = $projectDir . '/public/' . $rel;

        // Defense-in-depth: verify the resolved file is actually under
        // public/uploads/frequencies/ to neutralise any path traversal.
        $realBase = realpath($projectDir . '/public/uploads/frequencies');
        $realFile = $realBase ? realpath($absPath) : false;
        if (!$realFile || !$realBase || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'File path is invalid',
            ], 410);
        }
        if (!is_file($realFile)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'File missing on disk',
            ], 410);
        }

        $response = new BinaryFileResponse($realFile);
        $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => 'application/octet-stream',
        };
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $uf->getName() . '.' . $ext
        );
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        return $response;
    }

    /**
     * DELETE /api/frequencies/mine/{id}
     *
     * Delete one of the user's uploaded frequencies. Cascade:
     *  - removes the UserFrequency entity
     *  - removes the actual file from public/uploads/frequencies/{userCode}/
     *
     * Owner OR admin can delete. Admins can purge users' entries from
     * the audit/moderation flow.
     */
    #[Route('/mine/{id}', name: 'mine_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function mineDelete(int $id): JsonResponse
    {
        $uf = $this->userFreqRepo->find($id);
        if (!$uf) {
            return $this->json(['success' => false, 'error' => 'Frequency not found'], 404);
        }

        $current = $this->getUser();
        $isOwner = $current === $uf->getUser();
        $isAdmin = is_array($current?->getRoles()) && in_array('ROLE_ADMIN', $current->getRoles(), true);
        if (!$isOwner && !$isAdmin) {
            return $this->json(['success' => false, 'error' => 'Forbidden'], 403);
        }

        $deleted = ['id' => $uf->getId(), 'name' => $uf->getName()];
        $fileDeleted = false;
        $fileError = null;

        if ($uf->getFilePath()) {
            $rel = ltrim($uf->getFilePath(), '/');
            $projectDir = $this->getParameter('kernel.project_dir');
            $absPath = $projectDir . '/public/' . $rel;
            // Belt-and-braces: ensure the resolved path is still under
            // public/uploads/frequencies/ to avoid any traversal vector.
            $realBase = realpath($projectDir . '/public/uploads/frequencies');
            $realFile = realpath($absPath);
            if ($realFile && $realBase && str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
                if (@unlink($realFile)) {
                    $fileDeleted = true;
                } else {
                    $fileError = 'Could not unlink file (check filesystem permissions)';
                }
            } else {
                $fileError = 'File path looked suspicious; skipped file deletion';
            }
        }

        $this->em->remove($uf);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'deleted' => $deleted,
            'fileDeleted' => $fileDeleted,
            'fileError' => $fileError,
        ]);
    }
}