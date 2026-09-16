<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\CampusAssignment;
use App\Entity\CampusCourse;
use App\Entity\CampusLesson;
use App\Entity\CampusModule;
use App\Service\CampusStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Student assignment submission flow (Campus):
 * upload files -> submit assignment with file metadata.
 */
class CampusAssignmentSubmitTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'campus_submissions',
            'campus_assignments',
            'campus_lessons',
            'campus_modules',
            'campus_courses',
        ]);
    }

    public function testAllowlistCoversOfficeTypes(): void
    {
        $this->assertSame(
            ['xlsx'],
            CampusStorage::ALLOWED_MIMES['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'] ?? null
        );
        $this->assertSame(['xls'], CampusStorage::ALLOWED_MIMES['application/vnd.ms-excel'] ?? null);
        $this->assertSame(['csv'], CampusStorage::ALLOWED_MIMES['text/csv'] ?? null);
        $this->assertSame(
            ['pptx'],
            CampusStorage::ALLOWED_MIMES['application/vnd.openxmlformats-officedocument.presentationml.presentation'] ?? null
        );
        $this->assertSame(50 * 1024 * 1024, CampusStorage::MAX_FILE_SIZE);
    }

    private function makeAssignment(string $title = 'Tarea 1'): CampusAssignment
    {
        $course = (new CampusCourse())->setTitle('Curso Test')->setActive(true);
        $this->em->persist($course);
        $module = (new CampusModule())->setCourse($course)->setTitle('Módulo 1');
        $this->em->persist($module);
        $lesson = (new CampusLesson())->setModule($module)->setTitle('Clase 1');
        $this->em->persist($lesson);
        $assignment = (new CampusAssignment())->setLesson($lesson)->setTitle($title);
        $this->em->persist($assignment);
        $this->em->flush();

        return $assignment;
    }

    private function uploadPdf(string $userCode): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tarea') . '.pdf';
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");

        $file = new UploadedFile($tmp, 'tarea.pdf', 'application/pdf', null, true);
        $this->client->request(
            'POST',
            '/api/campus/upload',
            [],
            ['file' => $file],
            ['HTTP_X-GAME-CODE' => $userCode]
        );
        $res = $this->parseJsonResponse();
        @unlink($tmp);

        return $res;
    }

    public function testSubmitAssignmentWithPdfFile(): void
    {
        $user = $this->createUser(['code' => 'ALUMNO01', 'name' => 'Alumno Uno']);
        $this->loginAs($user);
        $assignment = $this->makeAssignment();

        $up = $this->uploadPdf('ALUMNO01');
        $this->assertSame(201, $up['status'], 'Upload body: ' . json_encode($up['data']));
        $this->assertNotEmpty($up['data']['storage_name'] ?? null);

        $r = $this->jsonRequest('POST', '/api/campus/assignments/' . $assignment->getId() . '/submit', [
            'comments' => 'Mi entrega',
            'files' => [[
                'storage_name' => $up['data']['storage_name'],
                'name' => 'tarea.pdf',
                'mime' => 'application/pdf',
                'size' => $up['data']['size'],
            ]],
        ]);
        $this->assertSame(201, $r['status'], 'Submit body: ' . json_encode($r['data']));
        $this->assertSame('submitted', $r['data']['status'] ?? null);
        $this->assertNotEmpty($r['data']['files'] ?? null);
        $this->assertStringContainsString('/api/campus/files/', $r['data']['files'][0]['url'] ?? '');
    }

    public function testUploadRejectsExe(): void
    {
        $user = $this->createUser(['code' => 'ALUMNO02', 'name' => 'Alumno Dos']);
        $this->loginAs($user);

        $tmp = tempnam(sys_get_temp_dir(), 'evil') . '.exe';
        file_put_contents($tmp, "MZ\x90\x00binary-stub");
        $file = new UploadedFile($tmp, 'evil.exe', 'application/x-msdownload', null, true);
        $this->client->request(
            'POST',
            '/api/campus/upload',
            [],
            ['file' => $file],
            ['HTTP_X-GAME-CODE' => 'ALUMNO02']
        );
        $res = $this->parseJsonResponse();
        @unlink($tmp);

        $this->assertSame(400, $res['status'], 'Body: ' . json_encode($res['data']));
    }

    public function testSubmitRejectsOversizedFileMetadata(): void
    {
        $user = $this->createUser(['code' => 'ALUMNO03', 'name' => 'Alumno Tres']);
        $this->loginAs($user);
        $assignment = $this->makeAssignment();

        // 60 MB declared size exceeds the 50 MB cap → 400 without touching disk.
        $r = $this->jsonRequest('POST', '/api/campus/assignments/' . $assignment->getId() . '/submit', [
            'comments' => 'demasiado grande',
            'files' => [[
                'storage_name' => 'x.pdf',
                'name' => 'gigante.pdf',
                'mime' => 'application/pdf',
                'size' => 60 * 1024 * 1024,
            ]],
        ]);
        $this->assertSame(400, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    public function testSubmitRequiresAuth(): void
    {
        $assignment = $this->makeAssignment();

        $r = $this->jsonRequest('POST', '/api/campus/assignments/' . $assignment->getId() . '/submit', [
            'comments' => 'anon',
            'files' => [],
        ]);
        $this->assertSame(401, $r['status']);
    }
}
