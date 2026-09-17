<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\FrequencyPreset;
use App\Entity\FrequencySession;
use App\Entity\UserFrequency;
use App\Entity\User;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class FrequencyControllerTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'frequency_sessions',
            'frequency_presets',
            'user_frequencies',
        ]);
    }

    private function seedPreset(string $name, int $hz, string $category = 'solfeggio'): FrequencyPreset
    {
        $preset = new FrequencyPreset();
        $preset->setName($name);
        $preset->setFrequency($hz);
        $preset->setCategory($category);
        $preset->setActive(true);
        $preset->setBenefits(['focus']);
        $this->em->persist($preset);
        $this->em->flush();
        return $preset;
    }

    private function startSessionFor(User $user, int $durationMin, ?FrequencyPreset $preset = null): FrequencySession
    {
        $session = new FrequencySession();
        $session->setUser($user);
        $session->setDurationMinutes($durationMin);
        if ($preset) {
            $session->setPreset($preset);
        }
        $this->em->persist($session);
        $this->em->flush();
        return $session;
    }

    // ---------------------------------------------------------------
    // Public: presets
    // ---------------------------------------------------------------

    public function testPresetsEndpointReturns200WithoutAuth(): void
    {
        $this->seedPreset('Test 432Hz', 432);

        $r = $this->jsonRequest('GET', '/api/frequencies/presets');
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success'] ?? false);
        $this->assertGreaterThanOrEqual(1, $r['data']['count'] ?? 0);
        $this->assertNotEmpty($r['data']['presets']);
        $this->assertSame(432, $r['data']['presets'][0]['frequency']);
    }

    // ---------------------------------------------------------------
    // Auth-gated: mine
    // ---------------------------------------------------------------

    public function testMineRequiresAuthentication(): void
    {
        $r = $this->jsonRequest('GET', '/api/frequencies/mine');
        $this->assertSame(401, $r['status']);
    }

    public function testMineReturnsUserFrequencies(): void
    {
        $user = $this->createUser(['code' => 'FREQ01', 'name' => 'Freq Tester']);

        $uf = new UserFrequency();
        $uf->setUser($user);
        $uf->setName('Mi 528');
        $uf->setFrequency(528);
        $uf->setType('custom_generated');
        $this->em->persist($uf);
        $this->em->flush();

        $this->loginAs($user);

        $r = $this->jsonRequest('GET', '/api/frequencies/mine');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertSame(1, $r['data']['count'] ?? null);
        $this->assertSame('Mi 528', $r['data']['frequencies'][0]['name'] ?? null);
    }

    // ---------------------------------------------------------------
    // Session lifecycle: start / end / abandon / active
    // ---------------------------------------------------------------

    public function testSessionStartPersistsAndReturnsId(): void
    {
        $user = $this->createUser(['code' => 'SESS01', 'name' => 'Session Start']);
        $preset = $this->seedPreset('528Hz', 528);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', '/api/frequencies/session/start', [
            'duration_minutes' => 30,
            'preset_id' => $preset->getId(),
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success'] ?? false);
        $this->assertIsInt($r['data']['id'] ?? null);
        $this->assertNotEmpty($r['data']['started_at'] ?? null);
    }

    public function testSessionEndSetsCompletedAndEndedAt(): void
    {
        $user = $this->createUser(['code' => 'SESS02', 'name' => 'Session End']);
        $session = $this->startSessionFor($user, 15);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', "/api/frequencies/session/{$session->getId()}/end");
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertTrue($r['data']['success']);

        $this->em->clear();
        /** @var FrequencySession $reloaded */
        $reloaded = $this->em->find(FrequencySession::class, $session->getId());
        $this->assertTrue($reloaded->isCompleted());
        $this->assertNotNull($reloaded->getEndedAt());
    }

    public function testSessionAbandonDoesNotCountMinutes(): void
    {
        $user = $this->createUser(['code' => 'SESS03', 'name' => 'Session Abandon']);
        $session = $this->startSessionFor($user, 60);
        $this->loginAs($user);

        $r = $this->jsonRequest('DELETE', "/api/frequencies/session/{$session->getId()}/abandon");
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertTrue($r['data']['success']);
        $this->assertGreaterThanOrEqual(0, $r['data']['abandonedMinutes'] ?? 0);

        $this->em->clear();
        /** @var FrequencySession $reloaded */
        $reloaded = $this->em->find(FrequencySession::class, $session->getId());
        $this->assertNotNull($reloaded->getEndedAt());
        $this->assertFalse($reloaded->isCompleted(), 'Abandoned sessions must NOT be flagged completed');

        // End the (already-completed) session and re-check stats: total stays at 0.
        $stats = $this->jsonRequest('GET', '/api/frequencies/stats');
        $this->assertSame(0, $stats['data']['totalMinutes'] ?? 999);
    }

    public function testSessionActiveReturnsActiveSession(): void
    {
        $user = $this->createUser(['code' => 'SESS04', 'name' => 'Session Active']);
        $preset = $this->seedPreset('432Hz', 432, 'universal');
        $session = $this->startSessionFor($user, 30, $preset);
        $this->loginAs($user);

        $r = $this->jsonRequest('GET', '/api/frequencies/session/active');
        $this->assertSame(200, $r['status']);
        $this->assertSame($session->getId(), $r['data']['session']['sessionId'] ?? null);
        $this->assertSame(432, $r['data']['session']['frequency']['hz'] ?? null);
        $this->assertSame('preset', $r['data']['session']['frequency']['source'] ?? null);
        $this->assertSame(30, $r['data']['session']['durationMinutes'] ?? null);
        $this->assertFalse($r['data']['session']['isInfinite'] ?? true);
    }

    public function testSessionActiveReturns204WhenNoneActive(): void
    {
        $user = $this->createUser(['code' => 'SESS05', 'name' => 'No Active']);
        $this->loginAs($user);

        $r = $this->jsonRequest('GET', '/api/frequencies/session/active');
        $this->assertSame(204, $r['status']);
    }

    public function testSessionPatchAdjustsDuration(): void
    {
        $user = $this->createUser(['code' => 'SESS06', 'name' => 'Session Patch']);
        $session = $this->startSessionFor($user, 15);
        $this->loginAs($user);

        $r = $this->jsonRequest('PATCH', "/api/frequencies/session/{$session->getId()}", [
            'duration_minutes' => 45,
        ]);
        $this->assertSame(200, $r['status']);
        $this->assertSame(45, $r['data']['durationMinutes'] ?? null);
    }

    public function testSessionPatchRejectsCompleted(): void
    {
        $user = $this->createUser(['code' => 'SESS07', 'name' => 'Session Patch Closed']);
        $session = $this->startSessionFor($user, 15);
        $session->setCompleted(true);
        $session->setEndedAt(new \DateTimeImmutable());
        $this->em->flush();
        $this->loginAs($user);

        $r = $this->jsonRequest('PATCH', "/api/frequencies/session/{$session->getId()}", [
            'duration_minutes' => 45,
        ]);
        $this->assertSame(409, $r['status']);
    }

    public function testSessionAbandonIsForbiddenForOtherUser(): void
    {
        $owner = $this->createUser(['code' => 'OWNER01', 'name' => 'Owner']);
        $stranger = $this->createUser(['code' => 'STRANGER1', 'name' => 'Stranger']);
        $session = $this->startSessionFor($owner, 30);
        $this->loginAs($stranger);

        $r = $this->jsonRequest('DELETE', "/api/frequencies/session/{$session->getId()}/abandon");
        $this->assertSame(403, $r['status']);
    }

    // ---------------------------------------------------------------
    // Upload
    // ---------------------------------------------------------------

    private function tmpAudioFile(string $ext): string
    {
        $path = tempnam(sys_get_temp_dir(), 'freq_') . '.' . $ext;
        // Write a tiny but valid-ish MP3 frame header so mime detection has bytes to look at.
        $bytes = $ext === 'mp3' ? "\xFF\xFB\x90\x00" . str_repeat("\x00", 64) : str_repeat("\x00", 64);
        file_put_contents($path, $bytes);
        return $path;
    }

    public function testUploadAcceptsMp3AndPersistsFilePath(): void
    {
        $user = $this->createUser(['code' => 'UPLOAD01', 'name' => 'Uploader']);
        $this->loginAs($user);

        $fakePath = $this->tmpAudioFile('mp3');
        $uploaded = new UploadedFile(
            $fakePath,
            'mi-track.mp3',
            'audio/mpeg',
            null,
            true // test mode
        );

        $this->client->request(
            'POST',
            '/api/frequencies/upload',
            ['name' => 'Mi track 432', 'frequency' => 432, 'notes' => 'Tag test'],
            ['file' => $uploaded]
        );

        $r = $this->parseJsonResponse();
        $this->assertSame(201, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertTrue($r['data']['success'] ?? false);
        $this->assertStringContainsString('UPLOAD01/', $r['data']['filePath'] ?? '');
        $this->assertSame('Mi track 432', $r['data']['name'] ?? null);

        // Verify it surfaces in /mine
        $mine = $this->jsonRequest('GET', '/api/frequencies/mine');
        $this->assertSame(1, $mine['data']['count'] ?? 0);
        $this->assertSame('Mi track 432', $mine['data']['frequencies'][0]['name'] ?? null);

        @unlink($fakePath);
    }

    public function testUploadRejectsExeMime(): void
    {
        $user = $this->createUser(['code' => 'UPLOAD02', 'name' => 'Uploader 2']);
        $this->loginAs($user);

        $fakePath = $this->tmpAudioFile('exe');
        $uploaded = new UploadedFile($fakePath, 'malware.exe', 'application/x-msdownload', null, true);

        $this->client->request('POST', '/api/frequencies/upload', [], ['file' => $uploaded]);
        $r = $this->parseJsonResponse();
        $this->assertSame(415, $r['status']);

        @unlink($fakePath);
    }

    public function testUploadRequiresFile(): void
    {
        $user = $this->createUser(['code' => 'UPLOAD03', 'name' => 'Uploader 3']);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', '/api/frequencies/upload', ['name' => 'no file']);
        $this->assertSame(400, $r['status']);
    }

    // ---------------------------------------------------------------
    // /api/frequencies/stream/{id}  (H1 security fix)
    // ---------------------------------------------------------------

    private function uploadOne(User $user, string $code = 'UPLOAD10', string $name = 'Streamed track'): array
    {
        $this->loginAs($user);
        $fakePath = $this->tmpAudioFile('mp3');
        $uploaded = new UploadedFile($fakePath, 'mi-track.mp3', 'audio/mpeg', null, true);
        $this->client->request('POST', '/api/frequencies/upload', ['name' => $name, 'frequency' => 432], ['file' => $uploaded]);
        $r = $this->parseJsonResponse();
        $this->assertSame(201, $r['status'], 'upload body: ' . json_encode($r['data']));
        @unlink($fakePath);
        return $r['data'];
    }

    /**
     * Hard reset: drop the auth token and restart the kernel browser
     * with a clean cookie jar. Without this, the firewall rehydrates the
     * previous user from the persisted session cookie.
     */
    private function goAnonymous(): void
    {
        try {
            $this->client->getContainer()->get('security.token_storage')->setToken(null);
        } catch (\Throwable) {
            // token_storage may be unavailable; restart() handles cleanup.
        }
        $this->client->restart();
    }

    public function testStreamRequiresAuth(): void
    {
        $owner = $this->createUser(['code' => 'OWNER01', 'name' => 'Owner']);
        $data = $this->uploadOne($owner);
        $this->goAnonymous();

        $this->client->request('GET', '/api/frequencies/stream/' . $data['id']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testStreamReturnsAudioForOwner(): void
    {
        $owner = $this->createUser(['code' => 'OWNER02', 'name' => 'Owner 2']);
        $data = $this->uploadOne($owner, 'OWNER02', 'My track');

        $this->client->request('GET', '/api/frequencies/stream/' . $data['id']);
        $response = $this->client->getResponse();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('audio/', $response->headers->get('Content-Type') ?? '');
        // BinaryFileResponse is lazy — `getContent()` returns false until
        // the response is actually sent. We assert the file exists on disk
        // (proves the controller resolved and authorized it) and that the
        // Accept-Ranges header is set (proves it's a streaming response).
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $absPath = dirname(__DIR__, 2) . '/public' . $data['filePath'];
        $this->assertFileExists($absPath);
    }

    public function testStreamForbidsOtherUsers(): void
    {
        $owner = $this->createUser(['code' => 'OWNER03', 'name' => 'Owner 3']);
        $intruder = $this->createUser(['code' => 'INTRUDER01', 'name' => 'Intruder']);
        $data = $this->uploadOne($owner, 'OWNER03', 'Private');

        // Restart between owner and intruder to flush the persisted session
        // cookie (otherwise the firewall rehydrates the previous user).
        $this->client->restart();
        $this->loginAs($intruder);
        $this->client->request('GET', '/api/frequencies/stream/' . $data['id']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testStreamReturns404ForGeneratedFrequency(): void
    {
        $owner = $this->createUser(['code' => 'OWNER04', 'name' => 'Owner 4']);
        $this->loginAs($owner);

        // Add a custom_generated entry (no file)
        $r = $this->jsonRequest('POST', '/api/frequencies/add', ['name' => 'Pure tone', 'frequency' => 432]);
        $this->assertSame(201, $r['status']);
        $id = $r['data']['id'];

        $this->client->request('GET', '/api/frequencies/stream/' . $id);
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testStreamReturns404ForMissingEntry(): void
    {
        $user = $this->createUser(['code' => 'OWNER05', 'name' => 'Owner 5']);
        $this->loginAs($user);

        $this->client->request('GET', '/api/frequencies/stream/999999');
        $this->assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testMineExposesFilePathAndStreamUrl(): void
    {
        $owner = $this->createUser(['code' => 'OWNER06', 'name' => 'Owner 6']);
        $this->uploadOne($owner, 'OWNER06', 'Exposed');

        $mine = $this->jsonRequest('GET', '/api/frequencies/mine');
        $this->assertSame(200, $mine['status']);
        $entry = $mine['data']['frequencies'][0] ?? null;
        $this->assertNotNull($entry);
        $this->assertTrue($entry['hasFile'] ?? false);
        $this->assertStringContainsString('/uploads/frequencies/OWNER06/', $entry['filePath'] ?? '');
        $this->assertSame('/api/frequencies/stream/' . $entry['id'], $entry['streamUrl'] ?? '');
    }

    // ---------------------------------------------------------------
    // DELETE /api/frequencies/mine/{id}
    // ---------------------------------------------------------------

    public function testDeleteRemovesEntityAndFile(): void
    {
        $owner = $this->createUser(['code' => 'OWNER07', 'name' => 'Owner 7']);
        $data = $this->uploadOne($owner, 'OWNER07', 'Delete me');

        // filePath is stored as `/uploads/frequencies/OWNER07/{hash}.mp3` —
        // resolve against public/, which is the document root.
        // dirname(__DIR__, 2) is the project root (tnsvt-app/).
        $absPath = dirname(__DIR__, 2) . '/public' . $data['filePath'];
        $this->assertFileExists($absPath, 'File should exist before deletion');

        $r = $this->jsonRequest('DELETE', '/api/frequencies/mine/' . $data['id']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success'] ?? false);
        $this->assertTrue($r['data']['fileDeleted'] ?? false);
        $this->assertFileDoesNotExist($absPath);

        // Confirm gone from /mine
        $mine = $this->jsonRequest('GET', '/api/frequencies/mine');
        $this->assertSame(0, $mine['data']['count'] ?? -1);
    }

    public function testDeleteRequiresAuth(): void
    {
        $owner = $this->createUser(['code' => 'OWNER08', 'name' => 'Owner 8']);
        $data = $this->uploadOne($owner);
        $this->goAnonymous();

        $this->client->request('DELETE', '/api/frequencies/mine/' . $data['id']);
        $this->assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testDeleteForbidsOtherUsers(): void
    {
        $owner = $this->createUser(['code' => 'OWNER09', 'name' => 'Owner 9']);
        $intruder = $this->createUser(['code' => 'INTRUDER02', 'name' => 'Intruder']);
        $data = $this->uploadOne($owner, 'OWNER09', 'Mine only');

        $this->client->restart();
        $this->loginAs($intruder);
        $this->client->request('DELETE', '/api/frequencies/mine/' . $data['id']);
        $this->assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testAdminCanDeleteAnyUsersFile(): void
    {
        $owner = $this->createUser(['code' => 'OWNER10', 'name' => 'Owner 10']);
        $admin = $this->createUser([
            'code' => 'ADMIN01',
            'name' => 'Admin',
            'roles' => ['ROLE_ADMIN', 'ROLE_USER'],
        ]);
        $data = $this->uploadOne($owner, 'OWNER10', 'admin power');

        $this->loginAs($admin);
        $r = $this->jsonRequest('DELETE', '/api/frequencies/mine/' . $data['id']);
        $this->assertSame(200, $r['status']);
        $this->assertTrue($r['data']['success'] ?? false);
    }
}
