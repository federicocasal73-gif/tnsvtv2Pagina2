<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\FrequencySession;
use App\Entity\User;
use App\Repository\FrequencySessionRepository;

/**
 * Reconciles orphaned and active frequency sessions for a user.
 *
 * Used by:
 *   - FrequencyController::sessionActive() — returns serialized active session.
 *   - FrequencyController::sessionAbandon() — closes a session without counting minutes.
 *   - FrequencyController::sessionPatch()   — adjusts duration on a live session.
 */
final class FrequencySessionGuard
{
    public function __construct(
        private FrequencySessionRepository $sessionRepo,
    ) {}

    /**
     * @return array{success: bool, error?: string, session?: FrequencySession}
     */
    public function loadOwnedOrFail(int $sessionId, User $user): array
    {
        $session = $this->sessionRepo->find($sessionId);
        if (!$session) {
            return ['success' => false, 'error' => 'Session not found'];
        }
        if ($session->getUser() !== $user) {
            return ['success' => false, 'error' => 'Session does not belong to you'];
        }
        return ['success' => true, 'session' => $session];
    }

    /**
     * Abandon a session: mark endedAt + completed=false (so it does NOT count toward totals).
     * Computes partial minutes actually listened but does NOT persist them as if completed.
     */
    public function abandon(FrequencySession $session): int
    {
        $elapsed = $this->sessionRepo->elapsedSeconds($session);
        $session->setEndedAt(new \DateTimeImmutable());
        $session->setCompleted(false);
        // We intentionally do NOT update durationMinutes — abandoned minutes are discarded
        // and reflected only in stats via getTotalMinutesForUser() which filters completed=true.
        return (int) floor($elapsed / 60);
    }

    /**
     * @return array<string, mixed>|null  payload ready to JSON-encode, or null if none active
     */
    public function snapshotActiveSession(User $user): ?array
    {
        $active = $this->sessionRepo->findActiveByUserId($user->getId() ?? 0);
        if (!$active) {
            return null;
        }

        $elapsed = $this->sessionRepo->elapsedSeconds($active);
        $durationMin = $active->getDurationMinutes();
        $remainingSec = $durationMin > 0 ? max(0, $durationMin * 60 - $elapsed) : null;

        $preset = $active->getPreset();
        $userFreq = $active->getUserFrequency();

        return [
            'sessionId' => $active->getId(),
            'startedAt' => $active->getStartedAt()->format('c'),
            'elapsedSeconds' => $elapsed,
            'durationMinutes' => $durationMin,
            'remainingSeconds' => $remainingSec,
            'isInfinite' => $durationMin === 0,
            'frequency' => [
                'id' => $preset?->getId() ?? $userFreq?->getId(),
                'name' => $preset?->getName() ?? $userFreq?->getName(),
                'hz' => $preset?->getFrequency() ?? $userFreq?->getFrequency(),
                'category' => $preset?->getCategory(),
                'source' => $preset ? 'preset' : ($userFreq ? 'user' : null),
            ],
        ];
    }
}
