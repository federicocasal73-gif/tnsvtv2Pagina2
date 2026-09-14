<?php

declare(strict_types=1);

namespace App\Service;

/**
 * HMAC sign/verify for chat attachment URLs.
 *
 * Replaces the old /uploads/chat/<filename> world-readable scheme.
 * Format: base64url(payload).base64url(signature)
 *   payload = json{url, exp, owner_code}
 *
 * The download endpoint validates the signature and checks that the
 * requester is a participant of the conversation that owns the message
 * that references the file.
 */
class ChatAttachmentSigner
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function sign(string $url, string $ownerCode, int $ttlSeconds = 86400): string
    {
        $payload = [
            'url' => $url,
            'owner' => $ownerCode,
            'exp' => time() + $ttlSeconds,
        ];
        $b64 = $this->b64url(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig = $this->b64url(hash_hmac('sha256', $b64, $this->secret, true));
        return $b64 . '.' . $sig;
    }

    /**
     * @return array{ok: bool, url?: string, owner?: string, reason?: string}
     */
    public function verify(string $token): array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return ['ok' => false, 'reason' => 'malformed'];
        }
        [$b64, $sig] = $parts;

        $expected = $this->b64url(hash_hmac('sha256', $b64, $this->secret, true));
        if (!hash_equals($expected, $sig)) {
            return ['ok' => false, 'reason' => 'bad_signature'];
        }

        $json = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($json === false) {
            return ['ok' => false, 'reason' => 'bad_payload'];
        }
        try {
            $payload = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['ok' => false, 'reason' => 'bad_payload'];
        }
        if (!is_array($payload) || !isset($payload['url'], $payload['owner'], $payload['exp'])) {
            return ['ok' => false, 'reason' => 'bad_payload'];
        }
        if ((int) $payload['exp'] < time()) {
            return ['ok' => false, 'reason' => 'expired'];
        }
        return ['ok' => true, 'url' => (string) $payload['url'], 'owner' => (string) $payload['owner']];
    }

    private function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }
}
