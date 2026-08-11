<?php

namespace App\Controller\Api;

use App\Controller\Api\Admin\RequireAdminTrait;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

#[Route('/api/music')]
class MusicController extends AbstractController
{
    use RequireAdminTrait;

    private const ALLOWED_MIME = [
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/x-wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/x-m4a' => 'm4a',
        'audio/aac' => 'aac',
    ];

    private const MAX_BYTES = 200 * 1024 * 1024;
    private const PLAYLIST_VERSION = 2;

    public function __construct(
        private UserRepository $userRepository,
        private TokenStorageInterface $tokenStorage,
    ) {}

    private function audioDir(): string
    {
        return $this->getParameter('kernel.project_dir') . '/var/audio';
    }

    /**
     * Lee current.json y devuelve la playlist normalizada.
     * Si el archivo viejo no tiene formato playlist, lo migra.
     */
    private function readPlaylist(): array
    {
        $dir = $this->audioDir();
        $metaPath = $dir . '/current.json';
        if (!is_file($metaPath)) {
            return ['version' => self::PLAYLIST_VERSION, 'tracks' => [], 'activeIndex' => 0, 'loop' => 'all'];
        }
        $data = json_decode((string) file_get_contents($metaPath), true);
        if (!is_array($data)) {
            return ['version' => self::PLAYLIST_VERSION, 'tracks' => [], 'activeIndex' => 0, 'loop' => 'all'];
        }
        // Migración desde formato viejo (single track)
        if (isset($data['source']) && !isset($data['tracks'])) {
            $track = $this->buildTrackFromLegacy($data);
            $data = [
                'version' => self::PLAYLIST_VERSION,
                'tracks' => $track ? [$track] : [],
                'activeIndex' => 0,
                'loop' => 'all',
            ];
            file_put_contents($metaPath, json_encode($data, JSON_PRETTY_PRINT));
        }
        if (!isset($data['tracks']) || !is_array($data['tracks'])) {
            $data['tracks'] = [];
        }
        $data['version'] = $data['version'] ?? self::PLAYLIST_VERSION;
        $data['activeIndex'] = max(0, min((int) ($data['activeIndex'] ?? 0), max(0, count($data['tracks']) - 1)));
        $data['loop'] = in_array($data['loop'] ?? 'all', ['all', 'one', 'off'], true) ? $data['loop'] : 'all';
        return $data;
    }

    private function buildTrackFromLegacy(array $data): ?array
    {
        if (empty($data['source'])) return null;
        $track = [
            'id' => substr(bin2hex(random_bytes(6)), 0, 8),
            'name' => $data['originalName'] ?? 'Track',
            'source' => $data['source'],
            'mime' => $data['mime'] ?? ($data['source'] === 'external' ? 'audio/mpeg' : 'audio/mpeg'),
            'addedAt' => $data['uploadedAt'] ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'addedBy' => $data['uploadedBy'] ?? 'admin',
        ];
        if ($data['source'] === 'external') {
            $track['url'] = $data['url'] ?? null;
            $track['downloadUrl'] = $data['downloadUrl'] ?? $data['url'] ?? null;
        } else {
            $track['filename'] = $data['filename'] ?? null;
            if (!$track['filename'] || !is_file($this->audioDir() . '/' . $track['filename'])) {
                return null;
            }
            $track['size'] = filesize($this->audioDir() . '/' . $track['filename']);
        }
        return $track;
    }

    private function writePlaylist(array $playlist): void
    {
        $dir = $this->audioDir();
        $metaPath = $dir . '/current.json';
        file_put_contents($metaPath, json_encode($playlist, JSON_PRETTY_PRINT));
    }

    private function findTrack(array $playlist, string $id): ?array
    {
        foreach ($playlist['tracks'] as $idx => $t) {
            if (($t['id'] ?? null) === $id) return ['index' => $idx, 'track' => $t];
        }
        return null;
    }

    private function currentTrack(array $playlist): ?array
    {
        if (empty($playlist['tracks'])) return null;
        $idx = $playlist['activeIndex'] ?? 0;
        return $playlist['tracks'][$idx] ?? null;
    }

    // ========================================================================
    // ENDPOINTS PÚBLICOS
    // ========================================================================

    #[Route('', name: 'api_music_current', methods: ['GET'])]
    public function current(): JsonResponse
    {
        $playlist = $this->readPlaylist();
        $current = $this->currentTrack($playlist);
        return $this->json([
            'hasMusic' => $current !== null,
            'current' => $current,
            'activeIndex' => $playlist['activeIndex'],
            'total' => count($playlist['tracks']),
            'loop' => $playlist['loop'] ?? 'all',
            'playlist' => $playlist['tracks'],
        ]);
    }

    #[Route('/stream', name: 'api_music_stream', methods: ['GET'])]
    public function streamFile(Request $request): Response
    {
        $playlist = $this->readPlaylist();
        $trackId = $request->query->get('id');
        $track = null;
        if ($trackId) {
            $found = $this->findTrack($playlist, $trackId);
            $track = $found['track'] ?? null;
        } else {
            $track = $this->currentTrack($playlist);
        }
        if (!$track) {
            return new JsonResponse(['error' => 'No hay música configurada'], Response::HTTP_NOT_FOUND);
        }
        if (($track['source'] ?? '') === 'external') {
            return $this->proxyExternal($track, $request);
        }
        $path = $this->audioDir() . '/' . ($track['filename'] ?? '');
        if (!is_file($path)) {
            return new JsonResponse(['error' => 'Archivo no encontrado en disco'], Response::HTTP_NOT_FOUND);
        }
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $track['mime'] ?? 'audio/mpeg');
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            $track['name'] ?? $track['filename']
        );
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache', true);
        $response->headers->addCacheControlDirective('must-revalidate', true);
        return $response;
    }

    private function proxyExternal(array $track, Request $request): Response
    {
        $src = $track['downloadUrl'] ?? $track['url'] ?? null;
        if (!$src) {
            return new JsonResponse(['error' => 'URL externa inválida'], Response::HTTP_BAD_REQUEST);
        }
        $trackId = $track['id'] ?? 'default';
        $dir = $this->audioDir();
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
                return new JsonResponse(['error' => 'No se pudo descargar el audio desde la URL externa. Verificá que sea público o probá subir el archivo.'], Response::HTTP_BAD_GATEWAY);
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

    // ========================================================================
    // ENDPOINTS ADMIN
    // ========================================================================

    #[Route('/playlist/add-upload', name: 'api_admin_music_add_upload', methods: ['POST'])]
    public function addUpload(Request $request): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $file = $request->files->get('file');
        if (!$file) return $this->json(['error' => 'Subí un archivo de audio'], Response::HTTP_BAD_REQUEST);
        if (!$file->isValid()) return $this->json(['error' => 'Archivo inválido'], Response::HTTP_BAD_REQUEST);
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->json(['error' => 'Máximo 200 MB. Para más grande usá URL externa.'], Response::HTTP_BAD_REQUEST);
        }
        $mime = (string) $file->getMimeType();
        if (!isset(self::ALLOWED_MIME[$mime])) {
            return $this->json(['error' => 'Formato no soportado. Usá mp3, wav, ogg, m4a o aac.', 'mimeRecibido' => $mime], Response::HTTP_BAD_REQUEST);
        }
        $ext = self::ALLOWED_MIME[$mime];
        $dir = $this->audioDir();
        $trackId = substr(bin2hex(random_bytes(6)), 0, 8);
        $filename = 'track-' . $trackId . '.' . $ext;
        $file->move($dir, $filename);
        $track = [
            'id' => $trackId,
            'name' => $file->getClientOriginalName() ?: $filename,
            'source' => 'local',
            'filename' => $filename,
            'mime' => $mime,
            'size' => filesize($dir . '/' . $filename),
            'addedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'addedBy' => $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'admin',
        ];
        $playlist = $this->readPlaylist();
        $playlist['tracks'][] = $track;
        if (count($playlist['tracks']) === 1) $playlist['activeIndex'] = 0;
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'track' => $track, 'total' => count($playlist['tracks'])]);
    }

    #[Route('/playlist/add-external', name: 'api_admin_music_add_external', methods: ['POST'])]
    public function addExternal(Request $request): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $data = json_decode($request->getContent(), true) ?? [];
        $url = trim((string) ($data['url'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        if (!$url) return $this->json(['error' => 'La URL es requerida'], Response::HTTP_BAD_REQUEST);
        if (!preg_match('#^https?://#i', $url)) {
            return $this->json(['error' => 'La URL debe empezar con http:// o https://'], Response::HTTP_BAD_REQUEST);
        }
        $isGoogleDrive = (bool) preg_match('#^https?://(drive|drive\.usercontent)\.google\.com/#i', $url);
        $downloadUrl = $url;
        if ($isGoogleDrive) {
            $fileId = $this->extractGoogleDriveId($url);
            if ($fileId) {
                $downloadUrl = 'https://drive.usercontent.google.com/download?id=' . $fileId . '&export=download&confirm=t';
            }
        }
        $trackId = substr(bin2hex(random_bytes(6)), 0, 8);
        $track = [
            'id' => $trackId,
            'name' => $label ?: ('Track ' . substr($url, 0, 40)),
            'source' => 'external',
            'url' => $url,
            'downloadUrl' => $downloadUrl,
            'mime' => 'audio/mpeg',
            'addedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'addedBy' => $this->tokenStorage->getToken()?->getUserIdentifier() ?? 'admin',
        ];
        $playlist = $this->readPlaylist();
        $playlist['tracks'][] = $track;
        if (count($playlist['tracks']) === 1) $playlist['activeIndex'] = 0;
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'track' => $track, 'total' => count($playlist['tracks'])]);
    }

    #[Route('/playlist/{id}', name: 'api_admin_music_remove', methods: ['DELETE'], requirements: ['id' => '[A-Za-z0-9_-]+'])]
    public function remove(string $id): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $playlist = $this->readPlaylist();
        $found = $this->findTrack($playlist, $id);
        if (!$found) return $this->json(['error' => 'Track no encontrado'], Response::HTTP_NOT_FOUND);
        $track = $found['track'];
        $idx = $found['index'];
        // Borrar archivo si es local
        if (($track['source'] ?? '') === 'local' && !empty($track['filename'])) {
            @unlink($this->audioDir() . '/' . $track['filename']);
        }
        // Borrar cache si es externo
        if (($track['source'] ?? '') === 'external') {
            $cached = $this->audioDir() . '/cache-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) . '.bin';
            @unlink($cached);
            @unlink($cached . '.meta.json');
        }
        array_splice($playlist['tracks'], $idx, 1);
        if ($playlist['activeIndex'] >= count($playlist['tracks'])) {
            $playlist['activeIndex'] = max(0, count($playlist['tracks']) - 1);
        } elseif ($idx < $playlist['activeIndex']) {
            $playlist['activeIndex']--;
        }
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'total' => count($playlist['tracks'])]);
    }

    #[Route('/playlist/reorder', name: 'api_admin_music_reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $data = json_decode($request->getContent(), true) ?? [];
        $order = $data['order'] ?? null;
        if (!is_array($order) || count($order) === 0) {
            return $this->json(['error' => 'Se requiere un array "order" con los ids en el nuevo orden'], Response::HTTP_BAD_REQUEST);
        }
        $playlist = $this->readPlaylist();
        $byId = [];
        foreach ($playlist['tracks'] as $t) {
            if (!empty($t['id'])) $byId[$t['id']] = $t;
        }
        $newTracks = [];
        foreach ($order as $id) {
            if (isset($byId[$id])) {
                $newTracks[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        // Agregar los que faltaron al final
        foreach ($byId as $t) $newTracks[] = $t;
        if (count($newTracks) !== count($playlist['tracks'])) {
            return $this->json(['error' => 'Faltan tracks en el orden enviado'], Response::HTTP_BAD_REQUEST);
        }
        $activeId = $playlist['tracks'][$playlist['activeIndex']]['id'] ?? null;
        $playlist['tracks'] = $newTracks;
        if ($activeId) {
            foreach ($newTracks as $i => $t) {
                if (($t['id'] ?? null) === $activeId) { $playlist['activeIndex'] = $i; break; }
            }
        }
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'playlist' => $playlist['tracks'], 'activeIndex' => $playlist['activeIndex']]);
    }

    #[Route('/playlist/active', name: 'api_admin_music_set_active', methods: ['POST'])]
    public function setActive(Request $request): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $data = json_decode($request->getContent(), true) ?? [];
        $id = $data['id'] ?? null;
        $playlist = $this->readPlaylist();
        if (!$id) {
            return $this->json(['error' => 'Se requiere el id del track'], Response::HTTP_BAD_REQUEST);
        }
        $found = $this->findTrack($playlist, $id);
        if (!$found) return $this->json(['error' => 'Track no encontrado'], Response::HTTP_NOT_FOUND);
        $playlist['activeIndex'] = $found['index'];
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'activeIndex' => $playlist['activeIndex'], 'current' => $found['track']]);
    }

    #[Route('/playlist/loop', name: 'api_admin_music_set_loop', methods: ['POST'])]
    public function setLoop(Request $request): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $data = json_decode($request->getContent(), true) ?? [];
        $loop = $data['loop'] ?? 'all';
        if (!in_array($loop, ['all', 'one', 'off'], true)) {
            return $this->json(['error' => 'loop debe ser all, one u off'], Response::HTTP_BAD_REQUEST);
        }
        $playlist = $this->readPlaylist();
        $playlist['loop'] = $loop;
        $this->writePlaylist($playlist);
        return $this->json(['success' => true, 'loop' => $loop]);
    }

    #[Route('/playlist', name: 'api_admin_music_clear', methods: ['DELETE'])]
    public function clearAll(): JsonResponse
    {
        if ($denied = $this->requireAdmin($this->userRepository, $this->tokenStorage)) return $denied;
        $dir = $this->audioDir();
        // Borrar todos los archivos de tracks locales
        foreach (glob($dir . '/track-*.*') as $f) @unlink($f);
        foreach (glob($dir . '/cache-*.bin*') as $f) @unlink($f);
        foreach (glob($dir . '/bg-music.*') as $f) @unlink($f);
        $metaPath = $dir . '/current.json';
        if (is_file($metaPath)) @unlink($metaPath);
        return $this->json(['success' => true, 'hasMusic' => false, 'total' => 0]);
    }

    private function extractGoogleDriveId(string $url): ?string
    {
        if (preg_match('#/file/d/([a-zA-Z0-9_-]+)#', $url, $m)) return $m[1];
        if (preg_match('#[?&]id=([a-zA-Z0-9_-]+)#', $url, $m)) return $m[1];
        return null;
    }
}
