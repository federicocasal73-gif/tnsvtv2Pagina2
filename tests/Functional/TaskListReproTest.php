<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Task;

/**
 * Repro: GET /api/tasks and /api/tasks/created-by-me 500 in prod.
 * Seeds edge-case rows (incl. orphaned course_id FK, as prod schema
 * drift allows) and asserts both endpoints stay 200.
 */
class TaskListReproTest extends ApiTestCase
{
    protected function tablesToTruncate(): array
    {
        return array_merge(parent::tablesToTruncate(), [
            'task_comments',
            'task_feedbacks',
            'task_submissions',
            'tasks',
        ]);
    }

    private function makeTask(string $title, $assignee = null, $creator = null): Task
    {
        $t = new Task();
        $t->setTitle($title);
        if ($assignee) $t->setAssignedTo($assignee);
        if ($creator) $t->setAssignedBy($creator);
        $this->em->persist($t);
        $this->em->flush();

        return $t;
    }

    public function testListWithNormalTasksReturns200(): void
    {
        $admin = $this->createAdmin(['code' => 'TASKADM']);
        $user = $this->createUser(['code' => 'TASKUSR', 'name' => 'Task User']);
        $this->makeTask('Tarea normal', $user, $admin);
        $this->loginAs($admin);

        $r = $this->jsonRequest('GET', '/api/tasks?sort=due_date&order=asc');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertSame(1, $r['data']['count'] ?? null);
    }

    public function testCreatedByMeReturns200(): void
    {
        $admin = $this->createAdmin(['code' => 'TASKADM2']);
        $user = $this->createUser(['code' => 'TASKUSR2', 'name' => 'Task User 2']);
        $this->makeTask('Tarea creada', $user, $admin);
        $this->loginAs($admin);

        $r = $this->jsonRequest('GET', '/api/tasks/created-by-me?sort=due_date&order=asc');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
        $this->assertSame(1, $r['data']['count'] ?? null);
    }

    public function testListSurvivesOrphanCourseFk(): void
    {
        $admin = $this->createAdmin(['code' => 'TASKADM3']);
        $user = $this->createUser(['code' => 'TASKUSR3', 'name' => 'Task User 3']);
        $t = $this->makeTask('Tarea huerfana', $user, $admin);
        $this->em->flush();
        $this->em->clear();

        // Simulate prod drift: course_id pointing at a deleted course.
        // (SQLite ignores FKs; emulate with raw SQL like prod MySQL state.)
        $this->em->getConnection()->executeStatement(
            'UPDATE tasks SET course_id = 999999 WHERE id = ?',
            [$t->getId()]
        );
        $this->em->clear();
        $this->loginAs($admin);

        $r = $this->jsonRequest('GET', '/api/tasks?sort=due_date&order=asc');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    public function testListSurvivesInvalidUtf8InTitle(): void
    {
        $admin = $this->createAdmin(['code' => 'TASKADM4']);
        $user = $this->createUser(['code' => 'TASKUSR4', 'name' => 'Task User 4']);
        $t = $this->makeTask('Tarea normal 4', $user, $admin);
        $this->em->flush();
        $this->em->clear();

        // Simulate prod mojibake: Windows-1252 smart quotes pasted into a
        // latin1-era column (json_encode chokes on these bytes).
        $this->em->getConnection()->executeStatement(
            'UPDATE tasks SET title = ? WHERE id = ?',
            ["Meditar \x93 15 minutos \x94 antes", $t->getId()]
        );
        $this->em->clear();
        $this->loginAs($admin);

        $r = $this->jsonRequest('GET', '/api/tasks?sort=due_date&order=asc');
        $this->assertSame(200, $r['status'], 'Body: ' . json_encode($r['data']));
    }

    public function testGlobalScopeFilterAndCreate(): void
    {
        $admin = $this->createAdmin(['code' => 'TASKADM5']);
        $user = $this->createUser(['code' => 'TASKUSR5', 'name' => 'Task User 5']);
        $this->loginAs($admin);

        $g = $this->jsonRequest('POST', '/api/tasks', [
            'title' => 'Meditar 15 minutos antes de la sesión',
            'scope' => 'global',
        ]);
        $this->assertSame(201, $g['status'], 'Body: ' . json_encode($g['data']));
        $this->assertSame('global', $g['data']['task']['scope'] ?? null);

        $p = $this->jsonRequest('POST', '/api/tasks', [
            'title' => 'Tarea personal',
            'scope' => 'nonsense-value',
        ]);
        $this->assertSame(201, $p['status']);
        $this->assertSame('personal', $p['data']['task']['scope'] ?? null);

        $all = $this->jsonRequest('GET', '/api/tasks');
        $this->assertSame(2, $all['data']['count'] ?? null);

        $globals = $this->jsonRequest('GET', '/api/tasks?scope=global');
        $this->assertSame(200, $globals['status']);
        $this->assertSame(1, $globals['data']['count'] ?? null);
        $this->assertSame('global', $globals['data']['tasks'][0]['scope'] ?? null);

        $personals = $this->jsonRequest('GET', '/api/tasks?scope=personal');
        $this->assertSame(1, $personals['data']['count'] ?? null);

        // Regular user sees globals too (read is open).
        $this->loginAs($user);
        $vis = $this->jsonRequest('GET', '/api/tasks?scope=global');
        $this->assertSame(200, $vis['status']);
        $this->assertSame(1, $vis['data']['count'] ?? null);
    }

    public function testRegularUserCannotCreate(): void
    {
        $user = $this->createUser(['code' => 'TASKUSR6', 'name' => 'Task User 6']);
        $this->loginAs($user);

        $r = $this->jsonRequest('POST', '/api/tasks', [
            'title' => 'Intento regular',
            'scope' => 'global',
        ]);
        $this->assertSame(403, $r['status']);
    }
}
