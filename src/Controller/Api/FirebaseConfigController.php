<?php

namespace App\Controller\Api;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Devuelve la configuracion PUBLICA de Firebase Web (la que puede exponerse al cliente).
 * Los datos sensibles (service-account.json, private keys) NUNCA salen del backend.
 */
#[Route('/api/firebase')]
class FirebaseConfigController extends AbstractController
{
    public function __construct(
        #[Autowire(env: 'FIREBASE_WEB_API_KEY')]
        private readonly string $apiKey = '',
        #[Autowire(env: 'FIREBASE_AUTH_DOMAIN')]
        private readonly string $authDomain = '',
        #[Autowire(env: 'FIREBASE_PROJECT_ID')]
        private readonly string $projectId = '',
        #[Autowire(env: 'FIREBASE_STORAGE_BUCKET')]
        private readonly string $storageBucket = '',
        #[Autowire(env: 'FIREBASE_MESSAGING_SENDER_ID')]
        private readonly string $messagingSenderId = '',
        #[Autowire(env: 'FIREBASE_APP_ID')]
        private readonly string $appId = '',
        #[Autowire(env: 'FIREBASE_WEB_PUSH_VAPID_KEY')]
        private readonly string $vapidKey = '',
    ) {}

    #[Route('/config', name: 'api_firebase_config', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $placeholders = ['YOUR_', 'REEMPLAZAR', 'PLACEHOLDER'];
        $isPlaceholder = fn(string $v) => $v === '' || array_filter($placeholders, fn($p) => str_contains($v, $p)) !== [];

        if ($isPlaceholder($this->apiKey) || $isPlaceholder($this->projectId)
            || $isPlaceholder($this->appId) || $isPlaceholder($this->messagingSenderId)
            || $isPlaceholder($this->vapidKey)) {
            return new JsonResponse([
                'configured' => false,
                'error' => 'Firebase Web no esta configurado. Reemplazar placeholders en .env con las claves reales de Firebase Console.',
                'missing' => array_filter([
                    $isPlaceholder($this->apiKey) ? 'FIREBASE_WEB_API_KEY' : null,
                    $isPlaceholder($this->messagingSenderId) ? 'FIREBASE_MESSAGING_SENDER_ID' : null,
                    $isPlaceholder($this->appId) ? 'FIREBASE_APP_ID' : null,
                    $isPlaceholder($this->vapidKey) ? 'FIREBASE_WEB_PUSH_VAPID_KEY' : null,
                ]),
            ], 503, ['Cache-Control' => 'no-store']);
        }
        return new JsonResponse([
            'configured' => true,
            'apiKey' => $this->apiKey,
            'authDomain' => $this->authDomain ?: ($this->projectId . '.firebaseapp.com'),
            'projectId' => $this->projectId,
            'storageBucket' => $this->storageBucket ?: ($this->projectId . '.appspot.com'),
            'messagingSenderId' => $this->messagingSenderId,
            'appId' => $this->appId,
            'vapidKey' => $this->vapidKey,
        ], 200, ['Cache-Control' => 'public, max-age=3600']);
    }

    /**
     * Diagnostico publico: muestra que keys estan seteadas y cuales son.
     * NO expone la VAPID completa, solo confirma su presencia y formato.
     */
    #[Route('/diagnose', name: 'api_firebase_diagnose', methods: ['GET'])]
    public function diagnose(): JsonResponse
    {
        $vapidLen = strlen($this->vapidKey);
        $vapidPrefix = substr($this->vapidKey, 0, 12);
        $vapidSuffix = substr($this->vapidKey, -4);
        return new JsonResponse([
            'project_id' => $this->projectId,
            'messaging_sender_id' => $this->messagingSenderId,
            'has_vapid' => $vapidLen > 50,
            'vapid_length' => $vapidLen,
            'vapid_prefix' => $vapidPrefix . '...',
            'vapid_suffix' => '...' . $vapidSuffix,
            'has_api_key' => strlen($this->apiKey) > 20,
            'has_app_id' => strlen($this->appId) > 10,
            'hint' => 'Si has_vapid=true pero el navegador rechaza la VAPID, la key es probablemente de OTRO proyecto Firebase. La VAPID debe generarse desde Firebase Console del MISMO proyecto que aparece en project_id.',
        ], 200, ['Cache-Control' => 'no-store']);
    }
}