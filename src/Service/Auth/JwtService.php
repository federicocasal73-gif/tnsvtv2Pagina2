<?php

namespace App\Service\Auth;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * JWT issuance wrapper for TNSVT Reino v2.
 *
 * Wraps Lexik's JWTTokenManager to issue tokens with the payload shape
 * our frontend expects. The returned payload includes:
 *   - sub: user code (string)
 *   - code: same as sub (frontend compat)
 *   - name: user display name
 *   - roles: array of role strings
 *   - isAdmin: bool shortcut for the frontend
 *   - tier: current tier (INITIATE, ASPIRANT, TIER_1..3, MASTER)
 *   - iat, exp: standard JWT claims
 */
class JwtService
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
    ) {}

    /**
     * Build the response payload for /api/auth/login.
     * Includes both the legacy session fields and the new JWT fields.
     */
    public function buildLoginResponse(User $user): array
    {
        $token = $this->jwtManager->create($user);

        return [
            'success' => true,
            'user' => [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'isAdmin' => $user->getIsAdmin(),
                'tier' => $user->getTier(),
            ],
            'token' => $token,
            'refresh_token' => null, // set by RefreshTokenService
            'expires_in' => 900, // 15 min — matches JWT TTL
        ];
    }

    public function createToken(User $user): string
    {
        return $this->jwtManager->create($user);
    }
}