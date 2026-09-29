<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\AuthAuditLogger;
use Symfony\Component\HttpFoundation\Request;

/**
 * Single source of truth for "who is this user?" when the firewall
 * already authenticated via X-Game-Code (or didn't authenticate yet
 * because the caller is sending the code via query string / body
 * instead of the header).
 *
 * Lookup order (matches the order documented in
 * templates/_partials/api_helper.html.twig auto-attach):
 *   1. X-Game-Code header (primary path used by apiFetch helper)
 *   2. ?user_code= query string (curl / raw fetch)
 *   3. user_code POST field (legacy form posts)
 *   4. JSON body { "user_code": ... } (raw fetch without header)
 *
 * Codes are uppercased + trimmed before lookup. Inactive users are
 * rejected. Every fallback usage (anything other than the header)
 * is logged via AuthAuditLogger for security review.
 *
 * NOTE: when the firewall authenticator `LegacyHeaderAuthenticator`
 * runs, the user is ALREADY set on the request token. In that case
 * `$this->getUser()` from `AbstractController` returns it directly
 * and this resolver is only consulted if the firewall did not
 * authenticate (no X-Game-Code header → caller used a fallback
 * source). Controllers should prefer `$this->getUser()` and only
 * fall back to this resolver when they explicitly need to support
 * the query/body paths.
 */
final class GameCodeResolver
{
    public function __construct(
        private UserRepository $userRepository,
        private ?AuthAuditLogger $auditLogger = null,
    ) {}

    /**
     * Resolve the current user from the request, scanning header →
     * query → body for a game code.
     *
     * @return array{0: ?User, 1: ?string} Tuple of [user, auth_source].
     *                                       auth_source is null when no
     *                                       code was present in the
     *                                       request, otherwise one of:
     *                                       "X-Game-Code-header",
     *                                       "user_code-query",
     *                                       "user_code-post",
     *                                       "user_code-json".
     */
    public function resolve(Request $request): array
    {
        $source = null;
        $code = '';

        $headerCode = $request->headers->get('X-Game-Code', '');
        if ($headerCode !== '') {
            $code = (string) $headerCode;
            $source = 'X-Game-Code-header';
        } else {
            $queryCode = $request->query->get('user_code');
            if (is_string($queryCode) && $queryCode !== '') {
                $code = $queryCode;
                $source = 'user_code-query';
            }
        }

        if ($code === '') {
            $postCode = $request->request->get('user_code');
            if (is_string($postCode) && $postCode !== '') {
                $code = $postCode;
                $source = 'user_code-post';
            }
        }

        if ($code === '' && $request->getContentTypeFormat() === 'json') {
            $raw = $request->getContent();
            if ($raw !== '' && $raw !== null) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $jsonCode = $decoded['user_code'] ?? null;
                    if (is_string($jsonCode) && $jsonCode !== '') {
                        $code = $jsonCode;
                        $source = 'user_code-json';
                    }
                }
            }
        }

        if ($code === '') {
            return [null, null];
        }

        $normalized = strtoupper(trim($code));
        $user = $this->userRepository->findByCode($normalized);

        if ($user !== null && !$user->isActive()) {
            $user = null;
        }

        if ($user !== null && $source !== null && $source !== 'X-Game-Code-header') {
            $this->auditLogger?->logFallbackUsage($request, $normalized, $source);
        }

        return [$user, $source];
    }

    /**
     * Convenience wrapper returning only the user.
     */
    public function resolveUser(Request $request): ?User
    {
        return $this->resolve($request)[0];
    }
}
