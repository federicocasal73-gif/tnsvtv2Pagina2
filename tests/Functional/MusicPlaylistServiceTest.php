<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\MusicPlaylistService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

class MusicPlaylistServiceTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    private string $projectDir;
    private \App\Service\MusicPlaylistService $svc;
    private string $playlistFile;

    protected function setUp(): void
    {
        $this->projectDir = static::getContainer()->getParameter('kernel.project_dir');
        $this->svc = static::getContainer()->get(MusicPlaylistService::class);
        $this->playlistFile = $this->svc->audioFilePath();
        @unlink($this->playlistFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->playlistFile);
    }

    public function testLoadOnEmptyReturnsDefaults(): void
    {
        $p = $this->svc->load();
        $this->assertSame(2, $p['version']);
        $this->assertSame([], $p['tracks']);
        $this->assertSame(0, $p['activeIndex']);
        $this->assertSame('all', $p['loop']);
    }

    public function testAddTrackPersistsAndReturnsId(): void
    {
        $t = $this->svc->addTrack([
            'name' => 'Test Track',
            'source' => 'upload',
            'mime' => 'audio/mpeg',
            'filename' => 'abc/abc.mp3',
        ]);
        $this->assertNotEmpty($t['id']);
        $this->assertSame('Test Track', $t['name']);
        $this->assertFileExists($this->playlistFile);

        $reloaded = $this->svc->load();
        $this->assertCount(1, $reloaded['tracks']);
        $this->assertSame($t['id'], $reloaded['tracks'][0]['id']);
        $this->assertNotEmpty($reloaded['tracks'][0]['addedAt']);
    }

    public function testFindTrackReturnsMatchById(): void
    {
        $t = $this->svc->addTrack(['name' => 'A', 'source' => 'upload']);
        $this->assertSame($t, $this->svc->findTrack($t['id']));
        $this->assertNull($this->svc->findTrack('nope'));
    }

    public function testRemoveTrackAdjustsActiveIndex(): void
    {
        $t0 = $this->svc->addTrack(['name' => 'A', 'source' => 'upload']);
        $t1 = $this->svc->addTrack(['name' => 'B', 'source' => 'upload']);
        $t2 = $this->svc->addTrack(['name' => 'C', 'source' => 'upload']);
        $this->svc->setActive(2);

        $this->assertTrue($this->svc->removeTrack($t1['id']));
        $reloaded = $this->svc->load();
        $this->assertCount(2, $reloaded['tracks']);
        $this->assertSame(1, $reloaded['activeIndex']);
    }

    public function testToClientResponseShape(): void
    {
        $this->svc->addTrack(['name' => 'A', 'source' => 'upload']);
        $this->svc->addTrack(['name' => 'B', 'source' => 'external', 'mime' => 'audio/wav']);
        $resp = $this->svc->toClientResponse();
        $this->assertArrayHasKey('hasMusic', $resp);
        $this->assertArrayHasKey('current', $resp);
        $this->assertArrayHasKey('activeIndex', $resp);
        $this->assertArrayHasKey('total', $resp);
        $this->assertArrayHasKey('loop', $resp);
        $this->assertArrayHasKey('playlist', $resp);
        $this->assertSame(2, $resp['total']);
        $this->assertTrue($resp['hasMusic']);
        $this->assertSame('A', $resp['current']['name']);
    }
}