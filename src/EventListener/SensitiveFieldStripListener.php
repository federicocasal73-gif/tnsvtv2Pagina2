<?php

declare(strict_types=1);

namespace App\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * kernel.response — strips sensitive User fields from JSON responses.
 *
 * Background (Risk #8 in RISK_MITIGATION.md):
 *   `App\Entity\User` carries sensitive fields (password hash,
 *   currentRefreshTokenHash, email, lastLogin, lastActivityAt, etc.).
 *   Most controllers explicitly build their response arrays and never
 *   leak those fields. But if a future controller serialises a User
 *   via `$user->toArray()`, `json_encode($user)`, or Symfony's default
 *   serializer, the entire entity leaks.
 *
 *   This listener is the defence-in-depth: any JSON response that
 *   reaches the client is recursively scrubbed for known-sensitive keys.
 *   When a strip happens, we log a `warning` so the offending controller
 *   can be audited and fixed properly. We do NOT mutate non-JSON
 *   responses (HTML, files, images, etc.).
 *
 * The stripped keys are matched case-insensitively at any depth. See
 * SENSITIVE_KEYS for the full list.
 *
 * Tested in tests/Functional/SensitiveFieldStripTest.php.
 */
final class SensitiveFieldStripListener
{
    /**
     * Keys that must NEVER reach the client in JSON responses.
     * Add a new sensitive field here ONLY after auditing every endpoint.
     *
     * NOTE: `sessionId` is intentionally NOT here — the term is used
     * ambiguously across the codebase. PHP's PHPSESSID cookie is
     * already httpOnly + secure, never reaches the JSON body.
     * If a future endpoint returns a sensitive session identifier under
     * a different key, add THAT key here.
     */
    public const SENSITIVE_KEYS = [
        // Credentials & tokens
        'password',
        'passwordHash',
        'currentRefreshTokenHash',
        'currentRefreshToken',
        'refreshToken',
        'refreshTokenHash',
        'apiKey',
        'api_key',
        'rememberMeToken',
        'resetPasswordToken',
        'verificationToken',
        // PII (GDPR boundary)
        'email',
        'phone',
        'taxId',
        // Auth metadata (info disclosure, not a credential)
        'lastLoginIp',
        'last_login_ip',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    #[AsEventListener(event: KernelEvents::RESPONSE)]
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $response = $event->getResponse();
        if (!$response instanceof JsonResponse) {
            return;
        }

        // Symfony JsonResponse::getContent() returns the encoded JSON.
        $content = $response->getContent();
        if ($content === '' || $content === false) {
            return;
        }

        try {
            $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Not parseable JSON; leave alone (defensive: don't break).
            return;
        }

        if (!is_array($decoded)) {
            return;
        }

        [$scrubbed, $strippedKeys] = $this->scrub($decoded);

        if ($strippedKeys === []) {
            return; // clean — no rewrite needed
        }

        // Re-encode and replace response body.
        $response->setContent(json_encode(
            $scrubbed,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        ));

        // Audit log so devs can find and fix the offending controller.
        $request = $event->getRequest();
        $this->logger->warning('SensitiveFieldStripListener: stripped keys from JSON response', [
            'path' => $request->getPathInfo(),
            'method' => $request->getMethod(),
            'stripped_keys' => array_values(array_unique($strippedKeys)),
        ]);
    }

    /**
     * Recursively walk $data and strip any sensitive key.
     *
     * @param array<int|string, mixed> $data
     * @return array{0: array<int|string, mixed>, 1: list<string>}
     */
    private function scrub(array $data, array $found = []): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $found[] = $key;
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                [$child, $found] = $this->scrub($value, $found);
                $data[$key] = $child;
            }
        }
        return [$data, $found];
    }

    private function isSensitiveKey(string $key): bool
    {
        // Case-insensitive match against the allow-list.
        $needle = strtolower($key);
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            if ($needle === strtolower($sensitive)) {
                return true;
            }
        }
        return false;
    }
}
