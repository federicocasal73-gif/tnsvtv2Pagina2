<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google Drive API client (read-only) — Drive v3.
 *
 * Auth: API Key + "Anyone with the link" folder. No OAuth, no service account,
 * no JSON key. Admin pastes URL or folder ID, we list audio files, we cache
 * the binary via MusicController::proxyExternal() when streaming.
 *
 * Limits (2024 policy): 12,000 requests/min/project, 100MB/file via
 * `?alt=media`. Pagination: pageSize ≤ 1000. We never store the API key
 * server-side beyond a process parameter (passed in from container).
 */
final class GoogleDriveClient
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    /** Audios permitos en la carpeta (mismo set que MusicController::upload). */
    public const AUDIO_MIME_TYPES = [
        'audio/mpeg', 'audio/mp3',
        'audio/wav', 'audio/x-wav',
        'audio/ogg', 'audio/x-vorbis+ogg',
        'audio/mp4',
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly HttpClientInterface $http,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Extrae el folder ID de una URL completa o devuelve el ID raw si ya está en formato ID.
     *
     * Acepta:
     *   https://drive.google.com/drive/folders/{ID}
     *   https://drive.google.com/drive/u/0/folders/{ID}
     *   https://drive.google.com/file/d/{ID}/view
     *   {ID} crudo (alfanumérico + _ y -)
     */
    public function extractFolderId(string $urlOrId): string
    {
        $raw = trim($urlOrId);
        if ($raw === '') {
            throw new \InvalidArgumentException('URL/folder ID vacío');
        }

        // Patrones URL. folders o file comparten /d/{ID} al medio.
        $patterns = [
            '~/drive/folders/([A-Za-z0-9_-]+)~',
            '~/file/d/([A-Za-z0-9_-]+)~',
            '~/folders/([A-Za-z0-9_-]+)~',
            '~[?&]id=([A-Za-z0-9_-]+)~',
            '~/d/([A-Za-z0-9_-]+)(?:/|$)~',
        ];
        foreach ($patterns as $p) {
            if (preg_match($p, $raw, $m)) {
                return $m[1];
            }
        }

        // ID raw (alfanumérico, longitud razonable)
        if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $raw)) {
            return $raw;
        }
        throw new \InvalidArgumentException('No se pudo extraer folder ID');
    }

    /**
     * Lista archivos de audio en una carpeta de Drive.
     *
     * @return array<int, array{id:string,name:string,mime:string,size?:int}>
     */
    public function listAudioInFolder(string $folderId): array
    {
        $mimeQ = implode(' or ', array_map(
            fn ($m) => sprintf("mimeType='%s'", $m),
            self::AUDIO_MIME_TYPES
        ));
        $query = sprintf("'%s' in parents and (%s) and trashed=false", addslashes($folderId), $mimeQ);

        $items = [];
        $pageToken = null;
        do {
            $params = [
                'q' => $query,
                'fields' => 'files(id,name,mimeType,size,modifiedTime),nextPageToken',
                'pageSize' => 1000,
                'key' => $this->apiKey,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ];
            if ($pageToken) $params['pageToken'] = $pageToken;

            try {
                $resp = $this->http->request('GET', self::API_BASE . '/files', [
                    'query' => $params,
                    'timeout' => 30,
                ]);
                $status = $resp->getStatusCode();
            } catch (\Throwable $e) {
                $this->logger?->warning('Drive listAudioInFolder network error', ['folder' => $folderId]);
                throw new GoogleDriveException(
                    'Error de red con Google Drive: ' . $e->getMessage(),
                    GoogleDriveException::UPSTREAM_ERROR,
                    0,
                    $e,
                );
            }

            if ($status === 401 || $status === 403) {
                throw new GoogleDriveException(
                    'API key inválida o sin permisos para esta carpeta. Verificá GOOGLE_DRIVE_API_KEY en .env.local.',
                    GoogleDriveException::INVALID_API_KEY,
                    $status,
                );
            }
            if ($status === 404) {
                throw new GoogleDriveException(
                    'Carpeta no encontrada. Verificá que sea pública ("Anyone with the link").',
                    GoogleDriveException::FOLDER_NOT_FOUND,
                    $status,
                );
            }
            if ($status === 429) {
                throw new GoogleDriveException(
                    'Cuota de Drive agotada (429). Reintentá en unos minutos.',
                    GoogleDriveException::RATE_LIMITED,
                    $status,
                );
            }
            if ($status >= 500) {
                throw new GoogleDriveException(
                    "Drive upstream error $status. Reintentá.",
                    GoogleDriveException::UPSTREAM_ERROR,
                    $status,
                );
            }
            if ($status >= 400) {
                throw new GoogleDriveException(
                    "Drive error $status",
                    GoogleDriveException::UPSTREAM_ERROR,
                    $status,
                );
            }

            $data = $resp->toArray(false);
            foreach ($data['files'] ?? [] as $f) {
                $items[] = [
                    'id' => (string) $f['id'],
                    'name' => (string) $f['name'],
                    'mime' => (string) ($f['mimeType'] ?? 'audio/mpeg'),
                    'size' => isset($f['size']) ? (int) $f['size'] : null,
                ];
            }
            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $items;
    }

    /**
     * Construye la URL de descarga directa de un archivo.
     * El key va embebido — Drive exige `key=` en cada GET.
     */
    public function fileMediaUrl(string $fileId): string
    {
        return sprintf('%s/files/%s?alt=media&key=%s', self::API_BASE, $fileId, $this->apiKey);
    }
}