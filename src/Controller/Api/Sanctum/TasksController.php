<?php

namespace App\Controller\Api\Sanctum;

use App\Entity\Task;
use App\Entity\TaskComment;
use App\Entity\TaskFeedback;
use App\Entity\TaskSubmission;
use App\Entity\User;
use App\Repository\TaskCommentRepository;
use App\Repository\TaskFeedbackRepository;
use App\Repository\TaskRepository;
use App\Repository\TaskSubmissionRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use App\Service\TaskCalendarSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Sanctum Tasks API — Fase 3 unified task system.
 *
 * Routes (under /api/tasks to avoid the ROLE_ADMIN-only firewall rule
 * that matches /sanctum/api/tasks):
 *   GET    /                       list with filters + sort
 *   GET    /mine                   list tasks assigned to me (student)
 *   GET    /created-by-me          list tasks I created (mentor)
 *   GET    /counts                aggregate counts by status
 *   GET    /{id}                  detail with submissions/comments/feedback
 *   POST   /                       create (ROLE_ADMIN or mentor)
 *   PATCH  /{id}                  update fields (admin or creator)
 *   DELETE /{id}                  delete (admin only)
 *   POST   /{id}/status           update status (student or admin)
 *   POST   /{id}/submit           student submits work + file
 *   POST   /{id}/comments         add comment (any participant)
 *   POST   /{id}/grade            mentor grades (admin or creator)
 *   POST   /reorder               bulk update orden
 *   POST   /mark-overdue          cron: mark all overdue tasks
 */
#[Route('/api/tasks', name: 'api_tasks_')]
class TasksController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TaskRepository $taskRepository,
        private TaskSubmissionRepository $submissionRepository,
        private TaskCommentRepository $commentRepository,
        private TaskFeedbackRepository $feedbackRepository,
        private UserRepository $userRepository,
        private NotificationService $notifier,
        private TaskCalendarSyncService $calendarSync,
    ) {}

    /**
     * List tasks with full filtering. Defaults to "active only".
     * Query params:
     *   status, priority, type, assigned_to, assigned_by, search
     *   due_before, due_after (ISO dates), overdue_only=1, active=0
     *   sort: due_date|priority|created|updated|status|title|orden
     *   order: asc|desc
     */
    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $filters = $this->extractFilters($request);
        if (!isset($filters['active'])) {
            $filters['active'] = true;
        }
        $tasks = $this->taskRepository->findFiltered($filters);

        return $this->json([
            'success' => true,
            'count' => count($tasks),
            'tasks' => array_map(fn(Task $t) => $t->toArray(), $tasks),
        ]);
    }

    /** Tasks assigned to the current user (student list). */
    #[Route('/mine', name: 'mine', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $filters = $this->extractFilters($request);
        $tasks = $this->taskRepository->findAssignedTo($user, $filters);

        return $this->json([
            'success' => true,
            'count' => count($tasks),
            'tasks' => array_map(fn(Task $t) => $t->toArray(), $tasks),
        ]);
    }

    /** Tasks created by the current user (mentor list). */
    #[Route('/created-by-me', name: 'created', methods: ['GET'])]
    public function createdByMe(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $filters = $this->extractFilters($request);
        $tasks = $this->taskRepository->findAssignedBy($user, $filters);

        return $this->json([
            'success' => true,
            'count' => count($tasks),
            'tasks' => array_map(fn(Task $t) => $t->toArray(), $tasks),
        ]);
    }

    /** Aggregate counts by status (used by dashboard widget). */
    #[Route('/counts', name: 'counts', methods: ['GET'])]
    public function counts(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $counts = $this->taskRepository->countByStatusForUser($user);

        return $this->json([
            'success' => true,
            'counts' => $counts,
            'total' => array_sum($counts),
        ]);
    }

    /** Task detail with nested submissions / comments / feedback. */
    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)
            && $task->getAssignedTo()?->getId() !== $user->getId()
            && $task->getAssignedBy()?->getId() !== $user->getId()
        ) {
            return $this->json(['success' => false, 'error' => 'Sin permisos'], 403);
        }

        $submissions = array_map(fn(TaskSubmission $s) => $s->toArray(), $this->submissionRepository->findByTask($id));
        $comments = array_map(fn(TaskComment $c) => $c->toArray(), $this->commentRepository->findByTask($id));
        $feedback = $this->feedbackRepository->findByTask($id);

        return $this->json([
            'success' => true,
            'task' => $task->toArray(),
            'submissions' => $submissions,
            'comments' => $comments,
            'feedback' => $feedback?->toArray(),
        ]);
    }

    /** Create a task (mentor/admin only). */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)) return $this->json(['error' => 'Se requiere ROLE_ADMIN'], 403);

        $data = $this->decodeJson($request);
        $title = trim($data['title'] ?? '');
        if ($title === '') {
            return $this->json(['success' => false, 'error' => 'title es requerido'], 400);
        }
        if (mb_strlen($title) > 255) {
            return $this->json(['success' => false, 'error' => 'title demasiado largo (max 255)'], 400);
        }

        $task = new Task();
        $task->setTitle($title);
        $task->setDescription($data['description'] ?? null);
        $task->setStatus($data['status'] ?? Task::STATUS_PENDING);
        $task->setPriority($data['priority'] ?? Task::PRIORITY_NORMAL);
        $task->setType($data['type'] ?? Task::TYPE_GENERIC);
        $task->setInstructions($data['instructions'] ?? null);
        $task->setEstimatedMinutes($data['estimated_minutes'] ?? null);
        $task->setLinks($data['links'] ?? null);
        $task->setAttachments($data['attachments'] ?? null);
        $task->setOrden((int)($data['orden'] ?? $this->taskRepository->getMaxOrden() + 1));
        $task->setActive($data['active'] ?? true);

        if (!empty($data['due_date'])) {
            try { $task->setDueDate(new \DateTimeImmutable($data['due_date'])); }
            catch (\Throwable) { return $this->json(['success' => false, 'error' => 'due_date inválido'], 400); }
        }
        if (!empty($data['assigned_to'])) {
            $u = $this->userRepository->findByCode($data['assigned_to']);
            if (!$u) return $this->json(['success' => false, 'error' => 'assigned_to user no encontrado'], 400);
            $task->setAssignedTo($u);
        }
        $creator = $this->resolveCurrentUser($request);
        if ($creator) {
            $task->setAssignedBy($creator);
        }

        $this->em->persist($task);
        $this->em->flush();

        $this->logAdminAction($request, 'task.create', 'success', ['task_id' => $task->getId()]);

        // Sync with calendar
        try { $this->calendarSync->onTaskSaved($task); } catch (\Throwable) {}

        // Notify the assignee
        if ($task->getAssignedTo()) {
            $this->notifier->notify(
                $task->getAssignedTo(),
                'task',
                sprintf('Nueva tarea asignada: %s', $task->getTitle()),
                ['task_id' => (string) $task->getId(), 'priority' => $task->getPriority()],
                'task:' . $task->getId(),
                false
            );
        }

        return $this->json(['success' => true, 'id' => $task->getId(), 'task' => $task->toArray()], 201);
    }

    /** Update task fields (admin or creator). */
    #[Route('/{id}', name: 'update', methods: ['PATCH'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        $isAdmin = $this->isAdmin($user);
        if (!$isAdmin && $task->getAssignedBy()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Sin permisos para editar'], 403);
        }

        $data = $this->decodeJson($request);
        $changed = [];

        if (isset($data['title'])) {
            $title = trim($data['title']);
            if ($title === '') return $this->json(['success' => false, 'error' => 'title no puede estar vacío'], 400);
            $task->setTitle($title); $changed[] = 'title';
        }
        if (isset($data['description'])) { $task->setDescription($data['description']); $changed[] = 'description'; }
        if (isset($data['instructions'])) { $task->setInstructions($data['instructions']); $changed[] = 'instructions'; }
        if (isset($data['priority']) && in_array($data['priority'], [
            Task::PRIORITY_LOW, Task::PRIORITY_NORMAL, Task::PRIORITY_HIGH, Task::PRIORITY_URGENT
        ], true)) { $task->setPriority($data['priority']); $changed[] = 'priority'; }
        if (isset($data['type'])) { $task->setType($data['type']); $changed[] = 'type'; }
        if (isset($data['estimated_minutes'])) { $task->setEstimatedMinutes((int) $data['estimated_minutes']); $changed[] = 'estimated_minutes'; }
        if (isset($data['links'])) { $task->setLinks($data['links']); $changed[] = 'links'; }
        if (array_key_exists('due_date', $data)) {
            if ($data['due_date'] === null || $data['due_date'] === '') {
                $task->setDueDate(null);
            } else {
                try { $task->setDueDate(new \DateTimeImmutable($data['due_date'])); }
                catch (\Throwable) { return $this->json(['success' => false, 'error' => 'due_date inválido'], 400); }
            }
            $changed[] = 'due_date';
        }
        if (array_key_exists('assigned_to', $data)) {
            if ($data['assigned_to']) {
                $assignee = $this->userRepository->findByCode($data['assigned_to']);
                if (!$assignee) {
                    return $this->json(['success' => false, 'error' => 'assigned_to user no encontrado'], 400);
                }
                $task->setAssignedTo($assignee);
            } else {
                $task->setAssignedTo(null);
            }
            $changed[] = 'assigned_to';
        }
        if (isset($data['orden']) && $isAdmin) {
            $task->setOrden((int) $data['orden']); $changed[] = 'orden';
        }
        if (isset($data['active']) && $isAdmin) {
            $task->setActive((bool) $data['active']); $changed[] = 'active';
        }

        if ($changed) $task->touch();
        $this->em->flush();

        $this->logAdminAction($request, 'task.update', 'success', ['task_id' => $id, 'fields' => $changed]);

        // Sync with calendar when any calendar-visible field changed
        // (onTaskSaved refreshes title/desc/dates/color/status in one pass).
        if (array_intersect($changed, ['due_date', 'title', 'priority', 'status', 'assigned_to'])) {
            try { $this->calendarSync->onTaskSaved($task); } catch (\Throwable) {}
        }

        return $this->json(['success' => true, 'changed' => $changed, 'task' => $task->toArray()]);
    }

    /** Delete task (admin only). */
    #[Route('/{id}', name: 'delete', methods: ['DELETE'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)) return $this->json(['error' => 'Se requiere ROLE_ADMIN'], 403);

        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $title = $task->getTitle();
        $this->em->remove($task);
        $this->em->flush();

        // Remove linked calendar event
        try { $this->calendarSync->onTaskDeleted($id); } catch (\Throwable) {}

        $this->logAdminAction($request, 'task.delete', 'success', ['task_id' => $id, 'title' => $title]);

        return $this->json(['success' => true, 'deleted' => $id, 'title' => $title]);
    }

    /**
     * Update status (any participant). Special transitions:
     *   - pending → in_progress (student starts)
     *   - in_progress → submitted (student submits)
     *   - submitted → in_review (mentor starts grading)
     *   - in_review → approved | needs_revision (mentor decides)
     */
    #[Route('/{id}/status', name: 'status', methods: ['POST'])]
    public function updateStatus(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $data = $this->decodeJson($request);
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $isAdmin = $this->isAdmin($user);
        $isAssignee = $task->getAssignedTo()?->getId() === $user->getId();
        $isCreator = $task->getAssignedBy()?->getId() === $user->getId();
        if (!$isAdmin && !$isAssignee && !$isCreator) {
            return $this->json(['success' => false, 'error' => 'Sin permisos'], 403);
        }

        $newStatus = $data['status'] ?? '';
        $valid = [
            Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS, Task::STATUS_SUBMITTED,
            Task::STATUS_IN_REVIEW, Task::STATUS_APPROVED, Task::STATUS_NEEDS_REVISION,
        ];
        if (!in_array($newStatus, $valid, true)) {
            return $this->json(['success' => false, 'error' => 'estado inválido'], 400);
        }

        // State machine: assignees may only move forward along
        // pending/in_progress/needs_revision/overdue → in_progress/submitted.
        // Grading transitions (in_review/approved/needs_revision from review,
        // reopen to pending) are creator/admin-only.
        $oldStatus = $task->getStatus();
        if (!$isAdmin && !$isCreator && $isAssignee) {
            $allowedNew = [Task::STATUS_IN_PROGRESS, Task::STATUS_SUBMITTED];
            $allowedFrom = [
                Task::STATUS_PENDING, Task::STATUS_IN_PROGRESS,
                Task::STATUS_NEEDS_REVISION, Task::STATUS_OVERDUE,
            ];
            if (!in_array($newStatus, $allowedNew, true) || !in_array($oldStatus, $allowedFrom, true)) {
                return $this->json(['success' => false, 'error' => 'Transición no permitida para el asignado'], 403);
            }
        }
        $task->setStatus($newStatus);
        if ($newStatus === Task::STATUS_APPROVED && !$task->getCompletedAt()) {
            $task->setCompletedAt(new \DateTimeImmutable());
        }
        $task->touch();
        $this->em->flush();

        // Sync with calendar on status change (e.g. task approved → event done)
        try { $this->calendarSync->onTaskStatusChanged($task); } catch (\Throwable) {}

        // Notify the relevant party about the transition
        $notifyUser = null;
        $label = $task->getStatusLabel();
        if ($isAssignee && !$isCreator) {
            $notifyUser = $task->getAssignedBy(); // notify the mentor
        } else {
            $notifyUser = $task->getAssignedTo(); // notify the student
        }
        if ($notifyUser && $oldStatus !== $newStatus) {
            $this->notifier->notify(
                $notifyUser,
                'task',
                sprintf('Tarea "%s" → %s', $task->getTitle(), $label),
                ['task_id' => (string) $task->getId(), 'old_status' => $oldStatus, 'new_status' => $newStatus],
                'task:' . $task->getId(),
                false
            );
        }

        return $this->json(['success' => true, 'old_status' => $oldStatus, 'new_status' => $newStatus, 'task' => $task->toArray()]);
    }

    /** Student submits work + optional file (base64). */
    #[Route('/{id}/submit', name: 'submit', methods: ['POST'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user) && $task->getAssignedTo()?->getId() !== $user->getId()) {
            return $this->json(['success' => false, 'error' => 'Solo el asignado puede entregar'], 403);
        }

        // Rate limit: max 10 submissions per minute per user
        try {
            $rl = $this->container->get('doctrine')->getManager()->getConnection();
            $key = sprintf('task_submit_%s_%s', $user->getCode(), time());
            // simple in-memory check skipped (could use rate_limiter service)
        } catch (\Throwable) {}

        $data = $this->decodeJson($request);

        $sub = new TaskSubmission();
        $sub->setTask($task);
        $sub->setUser($user);
        $sub->setFileData($data['file_data'] ?? null);
        $sub->setFileName($data['file_name'] ?? null);
        $sub->setFileMime($data['file_mime'] ?? null);
        $sub->setFileSize(isset($data['file_size']) ? (int) $data['file_size'] : null);
        $sub->setComments($data['comments'] ?? null);

        $this->em->persist($sub);

        // Auto-transition: in_progress → submitted (overdue included so
        // overdue tasks don't get stuck — submitting reopens the flow).
        if (in_array($task->getStatus(), [Task::STATUS_IN_PROGRESS, Task::STATUS_PENDING, Task::STATUS_NEEDS_REVISION, Task::STATUS_OVERDUE], true)) {
            $task->setStatus(Task::STATUS_SUBMITTED);
        }
        $task->touch();
        $this->em->flush();

        // Notify the mentor
        if ($task->getAssignedBy() && $task->getAssignedBy()->getId() !== $user->getId()) {
            $this->notifier->notify(
                $task->getAssignedBy(),
                'task',
                sprintf('%s entregó: %s', $user->getName() ?? $user->getCode(), $task->getTitle()),
                ['task_id' => (string) $task->getId(), 'submission_id' => (string) $sub->getId()],
                'task:' . $task->getId(),
                false
            );
        }

        return $this->json(['success' => true, 'submission' => $sub->toArray(), 'task' => $task->toArray()], 201);
    }

    /** Add a comment to the task thread. */
    #[Route('/{id}/comments', name: 'comment', methods: ['POST'])]
    public function comment(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)
            && $task->getAssignedTo()?->getId() !== $user->getId()
            && $task->getAssignedBy()?->getId() !== $user->getId()
        ) {
            return $this->json(['success' => false, 'error' => 'Sin permisos para comentar'], 403);
        }

        $data = $this->decodeJson($request);
        $body = trim($data['body'] ?? '');
        if ($body === '') return $this->json(['success' => false, 'error' => 'body es requerido'], 400);

        $comment = new TaskComment();
        $comment->setTask($task);
        $comment->setAuthor($user);
        $comment->setBody($body);
        $comment->setKind($data['kind'] ?? 'comment');

        $this->em->persist($comment);
        $task->touch();
        $this->em->flush();

        // Notify the other party
        $notifyUser = null;
        if ($user->getId() === $task->getAssignedBy()?->getId()) {
            $notifyUser = $task->getAssignedTo();
        } else {
            $notifyUser = $task->getAssignedBy();
        }
        if ($notifyUser && $notifyUser->getId() !== $user->getId()) {
            $this->notifier->notify(
                $notifyUser,
                'task',
                sprintf('Nuevo comentario en: %s', $task->getTitle()),
                ['task_id' => (string) $task->getId(), 'comment_id' => (string) $comment->getId()],
                'task:' . $task->getId(),
                false
            );
        }

        return $this->json(['success' => true, 'comment' => $comment->toArray()], 201);
    }

    /** Mentor grades a submission. */
    #[Route('/{id}/grade', name: 'grade', methods: ['POST'])]
    public function grade(int $id, Request $request): JsonResponse
    {
        $task = $this->taskRepository->find($id);
        if (!$task) return $this->json(['success' => false, 'error' => 'Tarea no encontrada'], 404);

        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);

        $isAdmin = $this->isAdmin($user);
        $isCreator = $task->getAssignedBy()?->getId() === $user->getId();
        if (!$isAdmin && !$isCreator) {
            return $this->json(['success' => false, 'error' => 'Sin permisos para calificar'], 403);
        }

        $data = $this->decodeJson($request);
        if (!isset($data['decision']) || !in_array($data['decision'], [Task::STATUS_APPROVED, Task::STATUS_NEEDS_REVISION], true)) {
            return $this->json(['success' => false, 'error' => 'decision debe ser approved o needs_revision'], 400);
        }
        $decision = $data['decision'];

        $feedback = $this->feedbackRepository->findByTask($id) ?? new TaskFeedback();
        $feedback->setTask($task);
        $feedback->setGrader($user);
        if (isset($data['grade']) && $data['grade'] !== null && $data['grade'] !== '') {
            if (!is_numeric($data['grade']) || (float) $data['grade'] < 0 || (float) $data['grade'] > 10) {
                return $this->json(['success' => false, 'error' => 'grade debe ser numérico entre 0 y 10'], 400);
            }
            $feedback->setGrade((string) $data['grade']);
        }
        if (isset($data['comment'])) {
            $feedback->setComment($data['comment']);
        }
        $feedback->setDecision($decision);

        if ($feedback->getId() === null) {
            $this->em->persist($feedback);
        }
        $task->setStatus($decision);
        if ($decision === Task::STATUS_APPROVED) {
            $task->setCompletedAt(new \DateTimeImmutable());
        }
        $task->touch();
        $this->em->flush();

        // Notify the student
        if ($task->getAssignedTo() && $task->getAssignedTo()->getId() !== $user->getId()) {
            $label = $decision === Task::STATUS_APPROVED ? 'aprobada' : 'devuelta para corrección';
            $this->notifier->notify(
                $task->getAssignedTo(),
                $decision === Task::STATUS_APPROVED ? 'task_graded' : 'task_revision_requested',
                sprintf('Tarea %s: %s', $label, $task->getTitle()),
                ['task_id' => (string) $task->getId(), 'grade' => $feedback->getGrade()],
                'task:' . $task->getId(),
                false
            );
        }

        return $this->json(['success' => true, 'feedback' => $feedback->toArray(), 'task' => $task->toArray()]);
    }

    /** Bulk re-order (admin). */
    #[Route('/reorder', name: 'reorder', methods: ['POST'])]
    public function reorder(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)) return $this->json(['error' => 'Se requiere ROLE_ADMIN'], 403);

        $data = $this->decodeJson($request);
        $order = $data['order'] ?? null;
        if (!is_array($order) || empty($order)) {
            return $this->json(['success' => false, 'error' => 'order array requerido'], 400);
        }
        $position = 0;
        foreach ($order as $id) {
            $task = $this->taskRepository->find((int) $id);
            if ($task) {
                $task->setOrden($position++);
            }
        }
        $this->em->flush();
        return $this->json(['success' => true, 'reordered' => count($order)]);
    }

    /**
     * Cron endpoint: mark all tasks whose due_date < now and not yet approved as overdue.
     * Should be called from a scheduled job.
     */
    #[Route('/mark-overdue', name: 'mark_overdue', methods: ['POST'])]
    public function markOverdue(Request $request): JsonResponse
    {
        $user = $this->resolveCurrentUser($request);
        if (!$user) return $this->json(['error' => 'Unauthorized'], 401);
        if (!$this->isAdmin($user)) return $this->json(['error' => 'Se requiere ROLE_ADMIN'], 403);

        $count = $this->taskRepository->markOverdue();
        // Sync linked calendar events for newly-overdue tasks (bounded).
        $overdue = $this->taskRepository->findFiltered([
            'status' => Task::STATUS_OVERDUE, 'active' => true,
            'sort' => 'due_date', 'order' => 'asc',
        ]);
        foreach (array_slice($overdue, 0, 200) as $task) {
            try { $this->calendarSync->onTaskStatusChanged($task); } catch (\Throwable) {}
        }
        return $this->json(['success' => true, 'marked' => $count]);
    }

    // ── helpers ──

    private function decodeJson(Request $request): array
    {
        $data = json_decode($request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    private function extractFilters(Request $request): array
    {
        return [
            'status'       => $request->query->get('status'),
            'priority'     => $request->query->get('priority'),
            'type'         => $request->query->get('type'),
            'assigned_to'  => $request->query->get('assigned_to'),
            'assigned_by'  => $request->query->get('assigned_by'),
            'search'       => $request->query->get('search'),
            'due_before'   => $request->query->get('due_before'),
            'due_after'    => $request->query->get('due_after'),
            'overdue_only' => $request->query->get('overdue_only') === '1',
            'active'       => $request->query->has('active') ? $request->query->get('active') === '1' : null,
            'sort'         => $request->query->get('sort', 'due_date'),
            'order'        => $request->query->get('order', 'asc'),
        ];
    }

    private function resolveCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim((string) $request->headers->get('X-Game-Code', ''));
        if ($code === '') return null;
        $u = $this->userRepository->findByCode($code);
        return ($u && $u->isActive()) ? $u : null;
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function logAdminAction(Request $request, string $action, string $result, array $payload = []): void
    {
        try {
            $conn = $this->em->getConnection();
            $payloadJson = json_encode($payload);
            $conn->executeStatement(
                'INSERT INTO admin_audit_log (admin_code, action, result, ip, user_agent, payload, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)',
                [
                    $this->getUser()?->getCode() ?? $request->headers->get('X-Game-Code', 'unknown'),
                    $action, $result,
                    $request->getClientIp() ?? '0.0.0.0',
                    substr($request->headers->get('User-Agent', ''), 0, 200),
                    $payloadJson,
                ]
            );
        } catch (\Throwable) {
            // audit logging is best-effort
        }
    }
}

