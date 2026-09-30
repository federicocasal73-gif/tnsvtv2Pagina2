<?php

namespace App\Controller\Api;

use App\Service\GoogleDriveClient;
use App\Service\GoogleDriveException;
use App\Service\MusicPlaylistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/music')]
class MusicController extends AbstractController
{
    private const ALLOWED_AUDIO_EXTS = ['mp3', 'wav', 'ogg', 'mp4'];
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private readonly MusicPlaylistService $playlist,
    ) {}

    #[Route('/current', name: 'api_music_current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        return $this->json($this->playlist->toClientResponse());
    }

    #[Route('/stream', name: 'api_music_stream', methods: ['GET'])]
    public function streamFile(Request $request): Response
    {
        $playlist = $this->playlist->load();
        $trackId = $request->query->get('id');
        $track = null;
        if ($trackId) {
            foreach ($playlist['tracks'] as $t) {
                if (($t['id'] ?? null) === $trackId) { $track = $t; break; }
            }
        } else {
            $track = $this->playlist->currentTrack($playlist);
        }
        if (!$track) {
            return new JsonResponse(['error' => 'No hay música configurada'], Response::HTTP_NOT_FOUND);
        }
        if (($track['source'] ?? '') === 'external') {
            return $this->proxyExternal($track, $request);
        }

        // source === 'upload': local file
        $path = $this->playlist->audioDir() . '/' . ($track['filename'] ?? '');
        if (!is_file($path)) {
            return new JsonResponse(['error' => 'Archivo no encontrado en disco'], Response::HTTP_NOT_FOUND);
        }
        return $this->buildAudioResponse($path, $track);
    }

    #[Route('/upload', name: 'api_music_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->json(['success' => false, 'error' => 'file (multipart) requerido'], 400);
        }
        if (!$file->isValid()) {
            return $this->json(['success' => false, 'error' => 'upload failed: ' . $file->getErrorMessage()], 400);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_AUDIO_EXTS, true)) {
            return $this->json([
                'success' => false,
                'error' => 'unsupported audio type (allowed: ' . implode(', ', self::ALLOWED_AUDIO_EXTS) . ')',
            ], 415);
        }
        if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
            return $this->json(['success' => false, 'error' => 'file too large (max 10MB)'], 413);
        }

        $hash = bin2hex(random_bytes(8));
        $relativeDir = 'uploads/' . $hash;
        $targetDir = $this->playlist->audioDir() . '/' . $relativeDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            return $this->json(['success' => false, 'error' => 'cannot create upload dir'], 500);
        }
        $filename = $hash . '.' . $ext;
        $file->move($targetDir, $filename);

        $mime = match ($ext) {
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            default => 'audio/mp4',  // mp4 es el único que llega a default (ya validado arriba)
        };

        $track = $this->playlist->addTrack([
            'id' => 'local:' . $hash,
            'name' => pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'Track',
            'source' => 'upload',
            'mime' => $mime,
            'addedBy' => 'admin',
            'filename' => $relativeDir . '/' . $filename,
            'size' => filesize($targetDir . '/' . $filename),
        ]);

        return $this->json([
            'success' => true,
            'id' => $track['id'],
            'name' => $track['name'],
            'mime' => $track['mime'],
            'size' => $track['size'],
        ], 201);
    }

    #[Route('/sync-from-drive', name: 'api_music_sync_drive', methods: ['POST'])]
    public function syncFromDrive(Request $request, GoogleDriveClient $drive): JsonResponse
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $data = json_decode($request->getContent(), true) ?? [];
        $folderInput = trim((string) ($data['folder'] ?? ''));
        if ($folderInput === '') {
            return $this->json(['success' => false, 'error' => 'folder requerido'], 400);
        }

        try {
            $folderId = $drive->extractFolderId($folderInput);
        } catch (\InvalidArgumentException) {
            return $this->json(['success' => false, 'error' => 'URL/folder ID inválido'], 400);
        }

        try {
            $files = $drive->listAudioInFolder($folderId);
        } catch (GoogleDriveException $e) {
            return $this->json([
                'success' => false,
                'error' => 'Drive: ' . $e->getMessage(),
                'code' => $e->getCode(),
            ], 502);
        }

        $added = [];
        $skipped = [];
        foreach ($files as $file) {
            $driveId = 'drive:' . $file['id'];
            if ($this->playlist->findTrack($driveId) !== null) {
                $skipped[] = $file['name'];
                continue;
            }
            $this->playlist->addTrack([
                'id' => $driveId,
                'name' => $file['name'],
                'source' => 'external',
                'mime' => $file['mime'],
                'addedBy' => 'admin',
                'url' => $drive->fileMediaUrl($file['id']),
                'downloadUrl' => $drive->fileMediaUrl($file['id']),
            ]);
            $added[] = $file['name'];
        }

        return $this->json([
            'success' => true,
            'folder_id' => $folderId,
            'added' => $added,
            'skipped' => $skipped,
            'total' => count($files),
        ]);
    }

    // ─── Stream (external) ───────────────────────────────────────────

    private function proxyExternal(array $track, Request $request): Response
    {
        $src = $track['downloadUrl'] ?? $track['url'] ?? null;
        if (!$src) {
            return new JsonResponse(['error' => 'URL externa inválida'], Response::HTTP_BAD_REQUEST);
        }
        $trackId = $track['id'] ?? 'default';
        $dir = $this->playlist->audioDir();
        $cachedPath = $dir . '/cache-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $trackId) . '.bin';
        $metaCache = $cachedPath . '.meta.json';

        $needDownload = true;
        if (is_file($cachedPath) && is_file($metaCache)) {
            $cm = json_decode((string) file_get_contents($metaCache), true);
            if (is_array($cm) && ($cm['url'] ?? null) === $src && ($cm['downloaded'] ?? false)) {
                $needDownload = false;
            }
        }

        if ($needDownload) {
            $bytes = $this->downloadToFile($src, $cachedPath);
            if ($bytes === false || $bytes === 0) {
                return new JsonResponse([
                    'error' => 'No se pudo descargar el audio desde la URL externa. Verificá que sea público o probá subir el archivo.',
                ], Response::HTTP_BAD_GATEWAY);
            }
            $mime = $this->detectAudioMime($cachedPath);
            file_put_contents($metaCache, json_encode([
                'url' => $src,
                'size' => $bytes,
                'mime' => $mime,
                'downloaded' => true,
                'downloadedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            ], JSON_PRETTY_PRINT));
        }

        $size = filesize($cachedPath);
        $mime = 'audio/mpeg';
        if (is_file($metaCache)) {
            $cm = json_decode((string) file_get_contents($metaCache), true);
            if (!empty($cm['mime'])) $mime = $cm['mime'];
        }

        $rangeHeader = $request->headers->get('Range');
        $start = 0;
        $end = $size - 1;
        $statusCode = 200;
        $headers = [
            'Content-Type' => $mime,
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'no-cache, must-revalidate',
        ];
        if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end = $m[2] !== '' ? (int) $m[2] : ($size - 1);
            if ($start > $end || $start >= $size) {
                return new Response('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $statusCode = 206;
            $headers['Content-Range'] = 'bytes ' . $start . '-' . $end . '/' . $size;
        }
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        $fh = fopen($cachedPath, 'rb');
        if ($fh === false) {
            return new JsonResponse(['error' => 'No se pudo abrir el cache'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
        fseek($fh, $start);
        $body = stream_get_contents($fh, $length);
        fclose($fh);

        return new Response($body !== false ? $body : '', $statusCode, $headers);
    }

    private function buildAudioResponse(string $path, array $track): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $track['mime'] ?? 'audio/mpeg');
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            ($track['name'] ?? $track['filename']) . '.' . pathinfo($path, PATHINFO_EXTENSION)
        );
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        return $response;
    }

    private function downloadToFile(string $url, string $destPath): int|false
    {
        if (function_exists('curl_init')) {
            return $this->downloadToFileCurl($url, $destPath);
        }
        $ctx = stream_context_create(['http' => [
            'timeout' => 30,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
            'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) TNSVT-Music/1.0\r\n",
        ]]);
        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || $data === '') return false;
        if (file_put_contents($destPath, $data) === false) return false;
        return strlen($data);
    }

    private function downloadToFileCurl(string $url, string $destPath): int|false
    {
        $fp = @fopen($destPath, 'wb');
        if (!$fp) return false;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) TNSVT-Music/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $ok = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);
        if (!$ok || $code >= 400) {
            @unlink($destPath);
            return false;
        }
        $size = filesize($destPath);
        return $size === false ? false : $size;
    }

    private function detectAudioMime(string $path): string
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return 'audio/mpeg';
        $head = fread($fh, 16);
        fclose($fh);
        $h = substr($head, 0, 4);
        if ($h === "RIFF" && substr($head, 8, 4) === 'WAVE') return 'audio/wav';
        if (substr($head, 0, 3) === 'ID3' || (ord($head[0] ?? "\0") === 0xFF && (ord($head[1] ?? "\0") & 0xE0) === 0xE0)) return 'audio/mpeg';
        if (substr($head, 0, 4) === "OggS") return 'audio/ogg';
        if (substr($head, 4, 4) === 'ftyp') return 'audio/mp4';
        return 'audio/mpeg';
    }
}