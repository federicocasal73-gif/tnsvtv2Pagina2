<?php

namespace App\Controller\Admin;

use App\Entity\Task;
use App\Entity\User;
use App\Repository\TaskCommentRepository;
use App\Repository\TaskFeedbackRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskSubmissionRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTML routes for the Task system — Fase 3.
 *
 * - GET /sanctum/tasks         : list view (already defined in SanctumController)
 * - GET /sanctum/tasks/new     : create form (admin/mentor only)
 * - GET /sanctum/tasks/{id}    : detail view (with submissions, comments, feedback)
 */
#[Route('/sanctum/tasks')]
class TasksPageController extends AbstractController
{
    public function __construct(
        private TaskRepository $taskRepository,
        private TaskSubmissionRepository $submissionRepository,
        private TaskCommentRepository $commentRepository,
        private TaskFeedbackRepository $feedbackRepository,
        private UserRepository $userRepository,
    ) {}

    #[Route('/new', name: 'sanctum_tasks_new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('sanctum/tasks/new.html.twig');
    }

    #[Route('/{id}', name: 'sanctum_tasks_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): Response
    {
        $task = $this->taskRepository->find($id);
        if (!$task) {
            throw $this->createNotFoundException('Tarea no encontrada');
        }

        // Hydrate the User entities for the template
        $assignedTo = null;
        if ($task->getAssignedTo()) {
            $assignedTo = $this->userRepository->find($task->getAssignedTo()->getId());
        }
        $assignedBy = null;
        if ($task->getAssignedBy()) {
            $assignedBy = $this->userRepository->find($task->getAssignedBy()->getId());
        }

        return $this->render('sanctum/tasks/show.html.twig', [
            'task' => $task,
            'submissions' => $this->submissionRepository->findByTask($id),
            'comments' => $this->commentRepository->findByTask($id),
            'feedback' => $this->feedbackRepository->findByTask($id),
        ]);
    }
}
