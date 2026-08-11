<?php

namespace App\Service;

use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Storage privado de archivos entregados en Campus.
 *
 * - Los archivos se guardan en `var/storage/campus/{submission_id}/{storage_name}` (fuera de public/).
 * - La URL pública devuelta es un token opaco (`storage_name`) que solo sirve para el endpoint
 *   autenticado `/api/campus/files/{token}`.
 * - Limpieza: al reemplazar/borrar una entrega, los archivos anteriores se eliminan del disco.
 */
class CampusStorage
{
    public const MAX_FILE_SIZE = 20 * 1024 * 1024; // 20 MB

    public const ALLOWED_MIMES = [
        'image/jpeg'             => ['jpg', 'jpeg'],
        'image/png'              => ['png'],
        'image/gif'              => ['gif'],
        'image/webp'             => ['webp'],
        'application/pdf'        => ['pdf'],
        'application/msword'     => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'text/plain'             => ['txt'],
    ];

    private string $baseDir;

    public function __construct(string $projectDir)
    {
        $this->baseDir = rtrim($projectDir, '/') . '/var/storage/campus';
        if (!is_dir($this->baseDir)) {
            @mkdir($this->baseDir, 0775, true);
        }
    }

    /**
     * Genera un nombre aleatorio seguro (sin incluir user_code en el nombre físico).
     */
    public function generateStorageName(string $extension): string
    {
        return bin2hex(random_bytes(16)) . ($extension ? '.' . $extension : '');
    }

    /**
     * Valida y sanea el array de archivos recibido del cliente.
     * Cada item es `{url, name, mime, size}` y puede incluir `storage_name` propio.
     * Si el cliente envía un `storage_name` se verifica que pertenezca al usuario.
     *
     * Devuelve una lista normalizada apta para persistir en DB.
     */
    public function validateClientFiles(mixed $raw, User $owner): array
    {
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('files debe ser un array');
        }

        $out = [];
        $seen = [];
        foreach ($raw as $i => $item) {
            if (!is_array($item)) continue;
            $storageName = isset($item['storage_name']) && is_string($item['storage_name'])
                ? basename(trim($item['storage_name']))
                : '';
            $mime = isset($item['mime']) && is_string($item['mime']) ? trim($item['mime']) : '';
            $originalName = isset($item['name']) && is_string($item['name'])
                ? $this->sanitizeFilename($item['name'])
                : '';
            $size = isset($item['size']) ? (int) $item['size'] : 0;

            if ($storageName === '' || $this->isUnsafeFilename($storageName)) {
                throw new \InvalidArgumentException("storage_name inválido en item $i");
            }

            $allowedExts = self::ALLOWED_MIMES[$mime] ?? null;
            if (!$allowedExts && $mime !== '') {
                throw new \InvalidArgumentException("Tipo no permitido: $mime");
            }

            $ext = strtolower(pathinfo($storageName, PATHINFO_EXTENSION));
            if ($mime !== '' && !in_array($ext, $allowedExts, true)) {
                throw new \InvalidArgumentException("Extensión $ext no coincide con MIME $mime");
            }

            if ($size > self::MAX_FILE_SIZE) {
                throw new \InvalidArgumentException("Archivo demasiado grande");
            }

            if (!$this->storageBelongsToUser($storageName, $owner)) {
                throw new \InvalidArgumentException("storage_name no pertenece al usuario");
            }

            if (isset($seen[$storageName])) {
                continue;
            }
            $seen[$storageName] = true;

            $out[] = [
                'storage_name'  => $storageName,
                'name'          => $originalName,
                'mime'          => $mime,
                'size'          => $size,
                'user_code'     => $owner->getCode(),
            ];
        }
        return $out;
    }

    /**
     * Serializa la lista persistida para devolver al cliente.
     * Convierte `storage_name` en una URL relativa al endpoint autenticado.
     */
    public function serializeFilesForClient(?array $files): array
    {
        if (!is_array($files)) return [];
        $out = [];
        foreach ($files as $f) {
            if (!is_array($f) || empty($f['storage_name'])) continue;
            $storageName = (string) $f['storage_name'];
            $out[] = [
                'url'           => '/api/campus/files/' . $storageName,
                'storage_name'  => $storageName,
                'name'          => $f['name'] ?? '',
                'mime'          => $f['mime'] ?? '',
                'size'          => (int) ($f['size'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Almacena un UploadedFile físico y devuelve metadata para persistir.
     */
    public function storeUploadedFile(UploadedFile $file, User $owner): array
    {
        if (!$file->isValid()) {
            throw new \InvalidArgumentException('Archivo inválido');
        }
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException('Archivo demasiado grande (máx 20MB)');
        }

        // Detectamos mime con prioridad: server-detected > client-provided.
        $mime = $file->getMimeType();
        if (!$mime) {
            $mime = (string) ($file->getClientMimeType() ?? '');
        }
        if (!$mime) $mime = 'application/octet-stream';
        $allowedExts = self::ALLOWED_MIMES[$mime] ?? null;
        if (!$allowedExts) {
            throw new \InvalidArgumentException("Tipo no permitido: $mime");
        }

        $extCandidate = strtolower($file->guessExtension() ?? $file->getClientOriginalExtension() ?? '');
        if (!in_array($extCandidate, $allowedExts, true)) {
            // Si guess falla (común con docx), intentar por client original
            $clientExt = strtolower(pathinfo($file->getClientOriginalName() ?? '', PATHINFO_EXTENSION));
            if (in_array($clientExt, $allowedExts, true)) {
                $extCandidate = $clientExt;
            } else {
                $extCandidate = $allowedExts[0];
            }
        }

        $size = (int) (method_exists($file, 'getSize') ? $file->getSize() : filesize($file->getPathname() ?: ''));
        $storageName = $this->generateStorageName($extCandidate);

        $userDir = $this->baseDir . '/' . $this->userDirName($owner);
        if (!is_dir($userDir)) {
            @mkdir($userDir, 0775, true);
        }
        $finalName = $userDir . '/' . $storageName;

        $moved = @$file->move($userDir, $storageName);
        if ($moved === false) {
            // Fallback a moveWithUploadedName / copy
            @rename($file->getPathname(), $finalName);
        }
        if (!is_file($finalName)) {
            throw new \RuntimeException('No se pudo persistir el archivo');
        }
        @chmod($finalName, 0640);

        if ($size <= 0) {
            $size = (int) filesize($finalName);
        }

        return [
            'storage_name'  => $storageName,
            'name'          => $this->sanitizeFilename($file->getClientOriginalName() ?? $storageName),
            'mime'          => $mime,
            'size'          => $size,
            'user_code'     => $owner->getCode(),
        ];
    }

    /**
     * Borra del disco los archivos de una entrega (best-effort).
     */
    public function cleanupFiles(?array $files): void
    {
        if (!is_array($files)) return;
        foreach ($files as $f) {
            if (!is_array($f) || empty($f['storage_name'])) continue;
            $storage = (string) $f['storage_name'];
            $userCode = (string) ($f['user_code'] ?? '');
            $userDir = $userCode !== '' ? $this->baseDir . '/' . $this->userDirNameFromCode($userCode) : $this->baseDir;
            // Intento 1: dir por user_code derivado
            $candidate = $userDir . '/' . $storage;
            if (is_file($candidate)) { @unlink($candidate); continue; }
            // Intento 2: búsqueda recursiva (por si el user_code está desfasado)
            $found = $this->findRecursive($this->baseDir, $storage);
            if ($found) { @unlink($found); }
        }
    }

    public function getAbsolutePath(string $storageName): string
    {
        // Se busca a través de todos los subdirs; el endpoint valida ownership antes.
        $found = $this->findRecursive($this->baseDir, $storageName);
        if ($found) return $found;
        return $this->baseDir . '/' . $storageName;
    }

    /**
     * Extrae el user_code del path (carpeta inmediata que contiene el archivo).
     * Útil cuando no hay sidecar JSON. Retorna null si no se puede inferir.
     */
    public function extractUserCodeFromPath(string $storageName): ?string
    {
        $abs = $this->getAbsolutePath($storageName);
        if (!is_file($abs)) return null;
        $parent = dirname($abs);
        if ($parent === $this->baseDir || $parent === '.') return null;
        $code = basename($parent);
        if ($code === '' || $code === 'campus' || $code === 'storage') return null;
        return $code;
    }

    /**
     * Devuelve información del archivo a partir del token.
     * Retorna null si no existe.
     *
     * @return array{storage_name: string, original_name: string, mime: string, user_code: string, size: int}|null
     */
    public function resolveDownload(string $storageName): ?array
    {
        $storageName = basename($storageName);
        if ($this->isUnsafeFilename($storageName) || $storageName === '') {
            return null;
        }
        $found = $this->findRecursive($this->baseDir, $storageName);
        if (!$found || !is_file($found)) return null;

        // El ownership real se valida en el controller (compara con submission del user).
        // Aquí extraemos metadata desde una sidecar JSON si existe; si no, defaults seguros.
        $sidecar = $found . '.meta.json';
        $meta = [
            'storage_name' => $storageName,
            'original_name' => $storageName,
            'mime' => 'application/octet-stream',
            'user_code' => '',
            'size' => filesize($found),
        ];
        if (is_file($sidecar)) {
            $data = json_decode((string) file_get_contents($sidecar), true);
            if (is_array($data)) {
                $meta['original_name'] = (string) ($data['name'] ?? $storageName);
                $meta['mime'] = (string) ($data['mime'] ?? $meta['mime']);
                $meta['user_code'] = (string) ($data['user_code'] ?? '');
            }
        }
        return $meta;
    }

    /**
     * Confirma que un storage_name pertenece al user (busca en su dir).
     */
    private function storageBelongsToUser(string $storageName, User $owner): bool
    {
        $userDir = $this->baseDir . '/' . $this->userDirName($owner);
        if (is_file($userDir . '/' . $storageName)) return true;
        // Fallback: si el archivo existe en el dir de otro user, no pertenece.
        $found = $this->findRecursive($this->baseDir, $storageName);
        if (!$found) return false;
        return str_starts_with($found, $userDir . DIRECTORY_SEPARATOR);
    }

    private function userDirName(User $owner): string
    {
        return $this->userDirNameFromCode($owner->getCode());
    }

    /**
     * Forma estable un subdirectorio por usuario sin filtrar el código (solo se valida upper+trim).
     */
    private function userDirNameFromCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = preg_replace('/[^A-Z0-9_\-]/', '_', $code);
        return substr($code, 0, 32) ?: 'anon';
    }

    private function sanitizeFilename(string $name): string
    {
        $name = basename(trim($name));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'archivo';
        }
        return mb_substr($name, 0, 200);
    }

    private function isUnsafeFilename(string $name): bool
    {
        return $name === '' || $name === '.' || $name === '..'
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0");
    }

    private function findRecursive(string $dir, string $basename): ?string
    {
        if (!is_dir($dir)) return null;
        if ($this->isUnsafeFilename($basename)) return null;
        // Búsqueda rápida: probar directamente en cada subdir user_code/ (un nivel).
        // Si no está, fallback recursivo completo.
        $candidates = glob($dir . '/*/' . $basename, GLOB_NOSORT) ?: [];
        foreach ($candidates as $cand) {
            if (is_file($cand)) return $cand;
        }
        // Fallback recursivo (por si la estructura es más profunda).
        try {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ($iter as $file) {
                if ($file->isFile() && $file->getFilename() === $basename) {
                    return $file->getPathname();
                }
            }
        } catch (\Throwable $e) {
            return null;
        }
        return null;
    }
}
