<?php

namespace App\Controller\Api\Sanctum;

use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sanctum Tasks API — Phase 1a.
 * CRUD for global tasks (broadcasts).
 */
#[Route('/sanctum/api/tasks', name: 'sanctum_api_tasks_')]
class TasksController extends AbstractController
{
    public function __construct(
        private TaskRepository $taskRepository,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $tasks = $this->taskRepository->createQueryBuilder('t')
            ->orderBy('t.orden', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $this->json([
            'success' => true,
            'count' => count($tasks),
            'tasks' => array_map(fn($t) => [
                'id' => $t->getId(),
                'title' => $t->getTitle(),
                'description' => $t->getDescription(),
                'orden' => $t->getOrden(),
                'active' => $t->isActive(),
                'createdAt' => $t->getCreatedAt()?->format('Y-m-d H:i'),
            ], $tasks),
        ]);
    }

    #[Route('', name: 'create', methods: ['POST'])]
    public function create(\Symfony\Component\HttpFoundation\Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $title = trim($data['title'] ?? '');
        $description = trim($data['description'] ?? '');

        if (empty($title)) {
            return $this->json(['success' => false, 'error' => 'Title required'], 400);
        }

        $task = new \App\Entity\Task();
        $task->setTitle($title);
        $task->setDescription($description);
        $task->setOrden((int)($data['orden'] ?? 0));
        $task->setActive(true);
        $task->setCreatedAt(new \DateTimeImmutable());

        $this->taskRepository->getEntityManager()->persist($task);
        $this->taskRepository->getEntityManager()->flush();

        return $this->json(['success' => true, 'id' => $task->getId()], 201);
    }

    #[Route('/{id}/toggle', name: 'toggle', methods: ['PATCH'])]
    public function toggle(int $id): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            return $this->json(['success' => false, 'error' => 'Task not found'], 404);
        }

        $task->setActive(!$task->isActive());
        $this->taskRepository->getEntityManager()->flush();

        return $this->json(['success' => true, 'active' => $task->isActive()]);
    }
}