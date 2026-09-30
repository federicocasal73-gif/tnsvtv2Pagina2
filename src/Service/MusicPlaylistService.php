<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Centraliza la lectura/escritura del playlist del Santuario Sonoro en
 * var/audio/current.json. Extracted from MusicController to make Meditación
 * (Track C) and uploads (Track D) testable in isolation.
 *
 * Schema (version=2):
 *   {
 *     "version": 2,
 *     "tracks": [
 *       {
 *         "id":         "local:abcd1234" | "drive:xyz",
 *         "name":       "Track name",
 *         "source":     "upload" | "external",
 *         "mime":       "audio/mpeg" | "audio/wav" | "audio/ogg" | "audio/mp4",
 *         "addedAt":    "2026-09-29 12:34:56",
 *         "addedBy":    "admin" | "user@example",
 *         // upload
 *         "filename":   "abcd1234/abcd1234.mp3",
 *         "size":       1234567,
 *         // external
 *         "url":          "https://...",
 *         "downloadUrl":  "https://...",
 *       }
 *     ],
 *     "activeIndex": 0,
 *     "loop": "all" | "one" | "off"
 *   }
 *
 * Concurrent safety: writes go to .tmp + rename; reads tolerate partial
 * legacy schema (single-track) and migrate transparently.
 */
final class MusicPlaylistService
{
    private const PLAYLIST_VERSION = 2;

    public function __construct(
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function audioDir(): string
    {
        return $this->projectDir . '/var/audio';
    }

    public function audioFilePath(): string
    {
        return $this->audioDir() . '/current.json';
    }

    /**
     * @return array{version:int,tracks:array<int,array>,activeIndex:int,loop:string}
     */
    public function load(): array
    {
        $path = $this->audioFilePath();
        if (!is_file($path)) {
            return ['version' => self::PLAYLIST_VERSION, 'tracks' => [], 'activeIndex' => 0, 'loop' => 'all'];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return ['version' => self::PLAYLIST_VERSION, 'tracks' => [], 'activeIndex' => 0, 'loop' => 'all'];
        }
        if (isset($data['source']) && !isset($data['tracks'])) {
            $track = $this->buildTrackFromLegacy($data);
            $data = [
                'version' => self::PLAYLIST_VERSION,
                'tracks' => $track ? [$track] : [],
                'activeIndex' => 0,
                'loop' => 'all',
            ];
            $this->saveAll($data);
        }
        if (!isset($data['tracks']) || !is_array($data['tracks'])) {
            $data['tracks'] = [];
        }
        $data['version'] = $data['version'] ?? self::PLAYLIST_VERSION;
        $data['activeIndex'] = max(0, min((int) ($data['activeIndex'] ?? 0), max(0, count($data['tracks']) - 1)));
        $data['loop'] = in_array($data['loop'] ?? 'all', ['all', 'one', 'off'], true) ? $data['loop'] : 'all';

        return $data;
    }

    public function save(array $playlist): void
    {
        $this->saveAll($playlist);
    }

    /**
     * Appends a track. If $track['id'] is missing, generates one. Returns
     * the persisted track (with id and addedAt).
     *
     * @return array the persisted track
     */
    public function addTrack(array $track): array
    {
        $playlist = $this->load();
        if (empty($track['id'])) {
            $track['id'] = bin2hex(random_bytes(6));
        }
        $track['addedAt'] = $track['addedAt'] ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $playlist['tracks'][] = $track;
        $this->saveAll($playlist);
        return $track;
    }

    public function findTrack(string $id): ?array
    {
        foreach ($this->load()['tracks'] as $t) {
            if (($t['id'] ?? null) === $id) return $t;
        }
        return null;
    }

    public function removeTrack(string $id): bool
    {
        $playlist = $this->load();
        $before = count($playlist['tracks']);
        $playlist['tracks'] = array_values(array_filter(
            $playlist['tracks'],
            fn ($t) => ($t['id'] ?? null) !== $id
        ));
        if (count($playlist['tracks']) === $before) {
            return false;
        }
        if (($playlist['activeIndex'] ?? 0) >= count($playlist['tracks'])) {
            $playlist['activeIndex'] = max(0, count($playlist['tracks']) - 1);
        }
        $this->saveAll($playlist);
        return true;
    }

    public function setActive(int $index): void
    {
        $playlist = $this->load();
        $playlist['activeIndex'] = max(0, min($index, max(0, count($playlist['tracks']) - 1)));
        $this->saveAll($playlist);
    }

    public function setLoop(string $loop): void
    {
        if (!in_array($loop, ['all', 'one', 'off'], true)) {
            $loop = 'all';
        }
        $playlist = $this->load();
        $playlist['loop'] = $loop;
        $this->saveAll($playlist);
    }

    public function currentTrack(?array $playlist = null): ?array
    {
        $playlist ??= $this->load();
        if (empty($playlist['tracks'])) return null;
        $idx = $playlist['activeIndex'] ?? 0;
        return $playlist['tracks'][$idx] ?? null;
    }

    public function toClientResponse(?array $playlist = null): array
    {
        $playlist ??= $this->load();
        $current = $this->currentTrack($playlist);
        return [
            'hasMusic' => $current !== null,
            'current' => $current,
            'activeIndex' => $playlist['activeIndex'],
            'total' => count($playlist['tracks']),
            'loop' => $playlist['loop'] ?? 'all',
            'playlist' => $playlist['tracks'],
        ];
    }

    private function saveAll(array $playlist): void
    {
        $path = $this->audioFilePath();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            $this->logger?->error('MusicPlaylistService: cannot create dir', ['dir' => $dir]);
            throw new \RuntimeException('cannot create audio dir');
        }
        $tmp = $path . '.tmp';
        $json = json_encode($playlist, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('cannot encode playlist');
        }
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('cannot write playlist tmp');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('cannot rename playlist');
        }
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
}