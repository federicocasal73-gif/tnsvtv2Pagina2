<?php

namespace App\Controller\Api;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\RateLimiterTrait;
use App\Service\CampusStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/campus')]
class CampusUploadController extends AbstractController
{
    use RateLimiterTrait;

    public function __construct(
        private UserRepository $userRepository,
        private CampusStorage $storage,
    ) {}

    /**
     * Identifica al usuario autenticado SOLO por X-Game-Code + verificación DB.
     * user_code enviado por query/body NO es autoritativo.
     */
    private function resolveUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim((string) $request->headers->get('X-Game-Code', ''));
        if ($code === '') return null;
        $u = $this->userRepository->findByCode($code);
        return ($u && $u->isActive()) ? $u : null;
    }

    #[Route('/upload', name: 'campus_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);

        $rateLimit = $this->checkRateLimit($request, 'campus_upload_' . $me->getCode(), 10, 60);
        if ($rateLimit) return $rateLimit;

        $file = $request->files->get('file');
        if (!$file) return $this->json(['error' => 'Archivo no recibido'], 400);

        try {
            $meta = $this->storage->storeUploadedFile($file, $me);
            return $this->json([
                'success'      => true,
                'storage_name' => $meta['storage_name'],
                'url'          => '/api/campus/files/' . $meta['storage_name'],
                'name'         => $meta['name'],
                'mime'         => $meta['mime'],
                'size'         => $meta['size'],
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Error al almacenar el archivo'], 500);
        }
    }

    /**
     * Upload de material académico (solo admin).
     */
    #[Route('/material/upload', name: 'campus_material_upload', methods: ['POST'])]
    public function uploadMaterial(Request $request): JsonResponse
    {
        $me = $this->resolveUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$me->getIsAdmin()) return $this->json(['error' => 'Solo admin puede subir material'], 403);

        $file = $request->files->get('file');
        if (!$file) return $this->json(['error' => 'Archivo no recibido'], 400);

        try {
            // Para material admin usamos un "owner" sintético con código único.
            // El archivo queda accesible vía /uploads/campus/material/ (directorio public).
            $adminDir = dirname(__DIR__, 3) . '/public/uploads/campus/material';
            if (!is_dir($adminDir)) @mkdir($adminDir, 0775, true);

            $mime = $file->getMimeType() ?? 'application/octet-stream';
            $allowed = CampusStorage::ALLOWED_MIMES[$mime] ?? null;
            if (!$allowed) return $this->json(['error' => "Tipo no permitido: $mime"], 400);

            $ext = strtolower($file->guessExtension() ?? pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?? 'bin');
            if (!in_array($ext, $allowed, true)) $ext = $allowed[0];

            $storageName = 'material_' . bin2hex(random_bytes(8)) . ($ext ? '.' . $ext : '');
            $finalPath = $adminDir . '/' . $storageName;
            $moved = @$file->move($adminDir, $storageName);
            if ($moved === false) @rename($file->getPathname(), $finalPath);
            if (!is_file($finalPath)) return $this->json(['error' => 'No se pudo guardar el archivo'], 500);
            @chmod($finalPath, 0644);

            return $this->json([
                'success' => true,
                'storage_name' => $storageName,
                'url' => '/uploads/campus/material/' . $storageName,
                'name' => $file->getClientOriginalName() ?? $storageName,
                'mime' => $mime,
                'size' => $file->getSize(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->json(['error' => 'Error al guardar el material'], 500);
        }
    }
}
