<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Service\GoogleDriveClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class GoogleDriveClientTest extends \Symfony\Bundle\FrameworkBundle\Test\KernelTestCase
{
    public function testExtractFolderIdAcceptsVariousForms(): void
    {
        $client = new GoogleDriveClient('key', new MockHttpClient());
        $this->assertSame('1abcXYZ_-', $client->extractFolderId('https://drive.google.com/drive/folders/1abcXYZ_-'));
        $this->assertSame('1fileABC', $client->extractFolderId('https://drive.google.com/file/d/1fileABC/view?usp=sharing'));
        $this->assertSame('1rawXY', $client->extractFolderId('1rawXY'));
        $this->assertSame('1query', $client->extractFolderId('https://example.com/?id=1query'));
    }

    public function testExtractFolderIdRejectsBadInput(): void
    {
        $client = new GoogleDriveClient('key', new MockHttpClient());
        $this->expectException(\InvalidArgumentException::class);
        $client->extractFolderId('hola mundo');
    }

    public function testFileMediaUrl(): void
    {
        $client = new GoogleDriveClient('abc123', new MockHttpClient());
        $this->assertSame(
            'https://www.googleapis.com/drive/v3/files/FILEID?alt=media&key=abc123',
            $client->fileMediaUrl('FILEID')
        );
    }

    public function testListAudioThrowsOnUnauthorized(): void
    {
        $mock = new MockHttpClient([new MockResponse('{"error":{"code":401}}', ['http_code' => 401])]);
        $client = new GoogleDriveClient('badkey', $mock);
        $this->expectException(\App\Service\GoogleDriveException::class);
        $client->listAudioInFolder('folderABC');
    }

    public function testListAudioThrowsOnFolderNotFound(): void
    {
        $mock = new MockHttpClient([new MockResponse('{"error":{"code":404}}', ['http_code' => 404])]);
        $client = new GoogleDriveClient('key', $mock);
        $this->expectException(\App\Service\GoogleDriveException::class);
        $client->listAudioInFolder('folderABC');
    }

    public function testListAudioReturnsParsedItems(): void
    {
        $body = json_encode([
            'files' => [
                ['id' => 'A1', 'name' => 'healing.mp3', 'mimeType' => 'audio/mpeg', 'size' => '1234567'],
                ['id' => 'B2', 'name' => 'calm.wav', 'mimeType' => 'audio/wav'],
            ],
        ]);
        $mock = new MockHttpClient([new MockResponse($body, ['http_code' => 200])]);
        $client = new GoogleDriveClient('key', $mock);
        $items = $client->listAudioInFolder('folderABC');

        $this->assertCount(2, $items);
        $this->assertSame('A1', $items[0]['id']);
        $this->assertSame('healing.mp3', $items[0]['name']);
        $this->assertSame('audio/mpeg', $items[0]['mime']);
        $this->assertSame(1234567, $items[0]['size']);
        $this->assertNull($items[1]['size']);
    }
}