<?php

namespace App\Service;

use App\Entity\Device;
use App\Entity\User;
use App\Repository\DeviceRepository;
use Psr\Log\LoggerInterface;

class PushNotificationService
{
    private ?array $serviceAccount = null;
    private ?string $serverKey = null;
    private ?string $projectId = null;

    public function __construct(
        private DeviceRepository $deviceRepo,
        private LoggerInterface $logger,
    ) {
        $saPath = $_ENV['FCM_SERVICE_ACCOUNT'] ?? '';
        if ($saPath) {
            // Resolve relative to project root
            if (!file_exists($saPath)) {
                $root = dirname(__DIR__, 2);
                $saPath = $root . '/' . ltrim($saPath, '/');
            }
            if (file_exists($saPath)) {
                $this->serviceAccount = json_decode(file_get_contents($saPath), true);
                $this->projectId = $this->serviceAccount['project_id'] ?? null;
                $this->logger->info('[PUSH] Using FCM v1 API with service account');
            } else {
                $this->logger->warning("[PUSH] Service account file not found: {$saPath}");
            }
        } else {
            $this->serverKey = $_ENV['FCM_SERVER_KEY'] ?? '';
            if ($this->serverKey) {
                $this->logger->info('[PUSH] Using FCM legacy API with server key');
            }
        }
    }

    public function isConfigured(): bool
    {
        return $this->serviceAccount !== null || ($this->serverKey !== null && $this->serverKey !== '');
    }

    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $devices = $this->deviceRepo->findByUser($user);
        if (!$devices) {
            $this->logger->info("[PUSH] No devices for user {$user->getCode()}");
            return 0;
        }
        return $this->sendToDevices($devices, $title, $body, $data);
    }

    public function broadcast(string $title, string $body, array $data = []): int
    {
        $devices = $this->deviceRepo->findAll();
        if (!$devices) return 0;
        return $this->sendToDevices($devices, $title, $body, $data);
    }

    private function sendToDevices(array $devices, string $title, string $body, array $data = []): int
    {
        if (!$this->isConfigured()) {
            $this->logger->warning('[PUSH] FCM not configured, skipping notification');
            return 0;
        }

        $sent = 0;
        foreach ($devices as $device) {
            if ($this->sendToDevice($device, $title, $body, $data)) {
                $sent++;
            }
        }
        return $sent;
    }

    private function sendToDevice(Device $device, string $title, string $body, array $data = []): bool
    {
        $token = $device->getFcmToken();
        if (!$token) return false;

        if ($this->serviceAccount) {
            return $this->sendV1($token, $title, $body, $data);
        }
        return $this->sendLegacy($token, $title, $body, $data);
    }

    private function sendV1(string $token, string $title, string $body, array $data = []): bool
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) return false;

        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $data,
            ],
        ];

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        "Authorization: Bearer {$accessToken}",
                        'Content-Type: application/json',
                    ]),
                    'content' => json_encode($payload),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $url = "https://fcm.googleapis.com/v1/projects/{$this->projectId}/messages:send";
            $result = @file_get_contents($url, false, $ctx);
            if ($result === false) {
                $this->logger->error("[PUSH] FCM v1 send failed for token {$token}");
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            $this->logger->error('[PUSH] FCM v1 exception: ' . $e->getMessage());
            return false;
        }
    }

    private function sendLegacy(string $token, string $title, string $body, array $data = []): bool
    {
        $payload = [
            'to' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => '1',
            ],
            'data' => array_merge($data, ['click_action' => 'FLUTTER_NOTIFICATION_CLICK']),
        ];

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", [
                        'Authorization: key=' . $this->serverKey,
                        'Content-Type: application/json',
                    ]),
                    'content' => json_encode($payload),
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            $result = @file_get_contents('https://fcm.googleapis.com/fcm/send', false, $ctx);
            if ($result === false) {
                $this->logger->error("[PUSH] FCM legacy send failed for token {$token}");
                return false;
            }
            $resp = json_decode($result, true);
            $success = ($resp['success'] ?? 0) > 0;
            if (!$success) {
                $this->logger->warning("[PUSH] FCM legacy error for {$token}: {$result}");
            }
            return $success;
        } catch (\Throwable $e) {
            $this->logger->error('[PUSH] FCM legacy exception: ' . $e->getMessage());
            return false;
        }
    }

    private function getAccessToken(): ?string
    {
        if (!$this->serviceAccount) return null;
        $clientEmail = $this->serviceAccount['client_email'] ?? '';
        $privateKey = $this->serviceAccount['private_key'] ?? '';
        if (!$clientEmail || !$privateKey) return null;

        $now = time();
        $header = $this->base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claim = $this->base64url(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signInput = "{$header}.{$claim}";
        $key = openssl_get_privatekey($privateKey);
        if (!$key) {
            $this->logger->error('[PUSH] Failed to parse private key');
            return null;
        }
        openssl_sign($signInput, $signature, $key, 'sha256');
        $jwt = "{$header}.{$claim}." . $this->base64url($signature);
        openssl_free_key($key);

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => 'Content-Type: application/x-www-form-urlencoded',
                    'content' => http_build_query([
                        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                        'assertion' => $jwt,
                    ]),
                    'timeout' => 10,
                ],
            ]);
            $resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
            if (!$resp) {
                $this->logger->error('[PUSH] Failed to get OAuth2 token');
                return null;
            }
            $data = json_decode($resp, true);
            return $data['access_token'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->error('[PUSH] OAuth2 exception: ' . $e->getMessage());
            return null;
        }
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
