<?php

namespace App\Controller\Api;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/chat')]
class ChatUploadController extends AbstractController
{
    private string $uploadDir;
    private const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB
    private const ALLOWED_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/webm',
        'audio/mpeg', 'audio/ogg', 'audio/wav',
        'application/pdf',
    ];

    public function __construct(
        private UserRepository $userRepository,
    ) {
        $this->uploadDir = dirname(__DIR__, 3) . '/public/uploads/chat';
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0775, true);
        }
    }

    private function resolveUser(Request $request): ?\App\Entity\User
    {
        $code = $request->query->get('user_code') ?? ($request->request->get('user_code') ?? $request->getContent() ? json_decode($request->getContent(), true)['user_code'] ?? null : null);
        $data = $request->request->all();
        $code = $data['user_code'] ?? $code;
        if (!$code) return null;
        $user = $this->userRepository->findByCode(strtoupper(trim($code)));
        return ($user && $user->isActive()) ? $user : null;
    }

    #[Route('/upload', name: 'api_chat_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'user_code requerido'], 400);

        $file = $request->files->get('file');
        if (!$file) return $this->json(['error' => 'Archivo no recibido'], 400);

        if (!$file->isValid()) return $this->json(['error' => 'Archivo inválido'], 400);

        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return $this->json(['error' => 'Archivo demasiado grande (máx 20MB)'], 413);
        }

        $mime = $file->getMimeType();
        if (!in_array($mime, self::ALLOWED_TYPES, true)) {
            return $this->json(['error' => 'Tipo de archivo no permitido: ' . $mime], 400);
        }

        $ext = $file->guessExtension() ?? 'bin';
        $filename = sprintf('%s_%s_%s.%s',
            $me->getCode(),
            time(),
            bin2hex(random_bytes(4)),
            $ext
        );
        $originalSize = $file->getSize();
        $originalName = $file->getClientOriginalName();

        $file->move($this->uploadDir, $filename);
        $url = '/uploads/chat/' . $filename;

        return $this->json([
            'success' => true,
            'url' => $url,
            'mime' => $mime,
            'size' => $originalSize,
            'name' => $originalName,
        ], 201);
    }
}
