<?php

namespace App\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Refresh token issuance + rotation for TNSVT Reino v2.
 *
 * - Generates a cryptographically random refresh token (64 chars hex)
 * - Stores bcrypt hash in User.currentRefreshTokenHash
 * - Stores rotation timestamp in User.refreshTokenRotatedAt
 * - 5-second atomic lock to prevent concurrent refresh races:
 *   refresh only succeeds if (now - rotatedAt) > 5s OR no token was set
 *
 * TTL: 7 days (604800 seconds)
 *
 * Refresh flow:
 *   POST /api/auth/refresh {refresh_token: "abc..."}
 *   → validate against User.currentRefreshTokenHash (bcrypt)
 *   → check timestamp atomicity
 *   → generate new pair (access + refresh)
 *   → store new hash, update timestamp
 *   → return new tokens
 */
class RefreshTokenService
{
    private const REFRESH_TOKEN_TTL_SECONDS = 604800; // 7 days
    private const REFRESH_TOKEN_ROTATION_LOCK_SECONDS = 5;
    private const REFRESH_TOKEN_BYTES = 32; // 64 hex chars

    public function __construct(
        private UserRepository $userRepository,
        private EntityManagerInterface $em,
        private JWTTokenManagerInterface $jwtManager,
    ) {}

    /**
     * Issue a new refresh token for the user, returns the raw token.
     * The raw token is returned to the client (not stored);
     * only its bcrypt hash is stored in DB.
     */
    public function issue(User $user): string
    {
        $rawToken = bin2hex(random_bytes(self::REFRESH_TOKEN_BYTES));
        $hash = password_hash($rawToken, PASSWORD_BCRYPT);

        $user->setCurrentRefreshTokenHash($hash);
        $user->setRefreshTokenRotatedAt(new \DateTimeImmutable());
        $this->em->flush();

        return $rawToken;
    }

    /**
     * Validate a refresh token against the user's stored hash.
     * Returns true if valid AND rotation lock is respected.
     */
    public function validate(User $user, string $rawToken): bool
    {
        $storedHash = $user->getCurrentRefreshTokenHash();
        if (!$storedHash) {
            return false;
        }
        if (!password_verify($rawToken, $storedHash)) {
            return false;
        }

        // Atomic rotation: prevent two concurrent refreshes from issuing
        // two new tokens. If last rotation was < 5s ago, reject.
        $rotatedAt = $user->getRefreshTokenRotatedAt();
        if ($rotatedAt && $rotatedAt > new \DateTimeImmutable('-' . self::REFRESH_TOKEN_ROTATION_LOCK_SECONDS . ' seconds')) {
            return false;
        }

        return true;
    }

    /**
     * Rotate: validate + invalidate old + issue new pair.
     * Returns ['access_token' => ..., 'refresh_token' => ..., 'expires_in' => 900]
     * or null if invalid.
     */
    public function rotate(User $user, string $rawToken): ?array
    {
        if (!$this->validate($user, $rawToken)) {
            return null;
        }

        // Generate new refresh token (rotates the stored hash)
        $newRefreshToken = $this->issue($user);

        // Generate new access token
        $newAccessToken = $this->jwtManager->create($user);

        return [
            'token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'expires_in' => 900,
        ];
    }

    /**
     * Find user by code, then attempt to rotate their refresh token.
     * Returns null array if user not found or token invalid.
     */
    public function rotateByCode(string $code, string $rawToken): ?array
    {
        $user = $this->userRepository->findByCode($code);
        if (!$user) {
            return null;
        }
        return $this->rotate($user, $rawToken);
    }

    public function getTtlSeconds(): int
    {
        return self::REFRESH_TOKEN_TTL_SECONDS;
    }
}