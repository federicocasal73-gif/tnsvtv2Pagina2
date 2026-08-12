<?php

namespace App\Controller\Api;

use App\Entity\Task;
use App\Repository\TaskRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/tasks')]
class TaskController extends AbstractController
{
    #[Route('', name: 'api_tasks_list', methods: ['GET'])]
    public function list(TaskRepository $taskRepository): JsonResponse
    {
        $tasks = $taskRepository->findAllActiveOrdered();

        $data = array_map(fn(Task $t) => [
            'id' => $t->getId(),
            'title' => $t->getTitle(),
            'description' => $t->getDescription(),
            'orden' => $t->getOrden(),
            'active' => $t->isActive(),
        ], $tasks);

        return $this->json($data);
    }
}
