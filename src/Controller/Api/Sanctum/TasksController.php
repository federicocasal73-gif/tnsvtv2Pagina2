<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Tasks API — Phase 1b full CRUD.
 * Routes:
 *   GET    /sanctum/api/tasks              list (with optional ?active=1)
 *   POST   /sanctum/api/tasks              create
 *   PATCH  /sanctum/api/tasks/{id}        update fields
 *   PATCH  /sanctum/api/tasks/{id}/toggle  toggle active
 *   DELETE /sanctum/api/tasks/{id}        delete
 *   POST   /sanctum/api/tasks/reorder      bulk update orden
 */
#[Route('/sanctum/api/tasks', name: 'sanctum_api_tasks_')]
class TasksController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRepository $taskRepository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $activeFilter = $request->query->get('active');
        $qb = $this->taskRepository->createQueryBuilder('t')
            ->orderBy('t.orden', 'ASC')
            ->addOrderBy('t.id', 'ASC');

        if ($activeFilter === '1') {
            $qb->where('t.active = 1');
        } elseif ($activeFilter === '0') {
            $qb->where('t.active = 0');
        }

        $tasks = $qb->getQuery()->getResult();

        return $this->json([
            'success' => true,
            'count' => count($tasks),
            'activeCount' => count(array_filter($tasks, fn($t) => $t->isActive())),
            'inactiveCount' => count(array_filter($tasks, fn($t) => !$t->isActive())),
            'tasks' => array_map(fn($t) => $t->toArray(), $tasks),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');

        if (empty($title)) {
            return $this->json(['success' => false, 'error' => 'Title is required'], 400);
        }

        if (mb_strlen($title) > 255) {
            return $this->json(['success' => false, 'error' => 'Title too long (max 255 chars)'], 400);
        }

        $task = new Task();
        $task->setTitle($title);
        $task->setDescription($description ?: null);
        $task->setOrden((int)($data['orden'] ?? $this->taskRepository->getMaxOrden() + 1));
        $task->setActive((bool)($data['active'] ?? true));
        $task->setCreatedAt(new \DateTimeImmutable());

        $this->em->persist($task);
        $this->em->flush();

        // Log to admin_audit_log
        $this->em->getConnection()->executeStatement(
            "INSERT INTO admin_audit_log (admin_code, action, result, ip, user_agent, created_at) VALUES (?, ?, 'success', ?, ?, NOW())",
            [
                $this->getUser()?->getCode() ?? 'unknown',
                'task.create',
                $request->getClientIp() ?? '0.0.0.0',
                substr($request->headers->get('User-Agent', ''), 0, 200),
            ]
        );

        return $this->json(['success' => true, 'id' => $task->getId(), 'task' => $task->toArray()], 201);
    }

    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function update(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return $this->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $changed = [];

        if (isset($data['title'])) {
            $title = trim($data['title']);
            if (empty($title)) {
                return $this->json(['success' => false, 'error' => 'Title cannot be empty'], 400);
            }
            if ($title !== $task->getTitle()) {
                $task->setTitle($title);
                $changed[] = 'title';
            }
        }

        if (isset($data['description'])) {
            $desc = trim($data['description']);
            if ($desc !== $task->getDescription()) {
                $task->setDescription($desc ?: null);
                $changed[] = 'description';
            }
        }

        if (isset($data['active'])) {
            $newActive = (bool)$data['active'];
            if ($newActive !== $task->isActive()) {
                $task->setActive($newActive);
                $changed[] = 'active';
            }
        }

        if (isset($data['orden'])) {
            $newOrden = (int)$data['orden'];
            if ($newOrden !== $task->getOrden()) {
                $task->setOrden($newOrden);
                $changed[] = 'orden';
            }
        }

        if (!empty($changed)) {
            $this->em->flush();
        }

        return $this->json(['success' => true, 'changed' => $changed, 'task' => $task->toArray()]);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['PATCH'])]
    #[IsGranted('ROLE_ADMIN')]
    public function toggle(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return $this->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $task->setActive(!$task->isActive());
        $this->em->flush();

        return $this->json([
            'success' => true,
            'id' => $task->getId(),
            'active' => $task->isActive(),
        ]);
    }

    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function reorder(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $order = $data['order'] ?? null;

        if (!is_array($order) || empty($order)) {
            return $this->json(['success' => false, 'error' => 'order array required (e.g. [1, 3, 2])'], 400);
        }

        $position = 0;
        foreach ($order as $id) {
            $id = (int)$id;
            $task = $this->taskRepository->find($id);
            if ($task) {
                $task->setOrden($position++);
            }
        }
        $this->em->flush();

        return $this->json(['success' => true, 'reordered' => count($order)]);
    }

    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return $this->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $title = $task->getTitle();
        $this->em->remove($task);
        $this->em->flush();

        return $this->json([
            'success' => true,
            'deleted' => $id,
            'title' => $title,
        ]);
    }
}