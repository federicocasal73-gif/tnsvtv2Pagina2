<?php

namespace App\Controller\Api;

use App\Entity\CampusAssignment;
use App\Entity\CampusCourse;
use App\Entity\CampusLesson;
use App\Entity\CampusLessonProgress;
use App\Entity\CampusMaterial;
use App\Entity\CampusModule;
use App\Entity\CampusSubmission;
use App\Entity\User;
use App\Repository\CampusAssignmentRepository;
use App\Repository\CampusCourseRepository;
use App\Repository\CampusLessonProgressRepository;
use App\Repository\CampusLessonRepository;
use App\Repository\CampusMaterialRepository;
use App\Repository\CampusModuleRepository;
use App\Repository\CampusSubmissionRepository;
use App\Repository\UserRepository;
use App\Security\AdminAuthTrait;
use App\Security\RateLimiterTrait;
use App\Service\CampusStorage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/campus')]
class CampusController extends AbstractController
{
    use RateLimiterTrait;
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private CampusCourseRepository $courseRepo,
        private CampusModuleRepository $moduleRepo,
        private CampusLessonRepository $lessonRepo,
        private CampusAssignmentRepository $assignmentRepo,
        private CampusMaterialRepository $materialRepo,
        private CampusSubmissionRepository $submissionRepo,
        private CampusLessonProgressRepository $progressRepo,
        private UserRepository $userRepository,
        private CampusStorage $storage,
    ) {}

    /**
     * Identifica al usuario autenticado.
     * Solo se confía en X-Game-Code + verificación en DB (usuario activo).
     * NUNCA se acepta user_code desde query/body como identidad autoritativa.
     */
    private function getCurrentUser(Request $request): ?User
    {
        $user = $this->getUser();
        if ($user instanceof User) return $user;
        $code = trim((string) $request->headers->get('X-Game-Code', ''));
        if ($code === '') return null;
        $u = $this->userRepository->findByCode($code);
        return ($u && $u->isActive()) ? $u : null;
    }

    #[Route('', name: 'campus_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $courses = $this->courseRepo->findAllOrdered();

        $courseData = array_map(function (CampusCourse $c) use ($userCode) {
            $modules = $this->moduleRepo->findByCourse($c->getId());
            $totalLessons = 0;
            $completedLessons = 0;

            foreach ($modules as $m) {
                $lessons = $this->lessonRepo->findByModule($m->getId());
                $totalLessons += count($lessons);
                foreach ($lessons as $l) {
                    $progress = $this->progressRepo->findByLesson($l->getId(), $userCode);
                    if ($progress && $progress->isCompleted()) {
                        $completedLessons++;
                    }
                }
            }

            $progressPercent = $totalLessons > 0 ? round(($completedLessons / $totalLessons) * 100) : 0;

            return [
                'id' => $c->getId(),
                'title' => $c->getTitle(),
                'emoji' => $c->getEmoji(),
                'description' => $c->getDescription(),
                'thumbnail' => $c->getThumbnail(),
                'orden' => $c->getOrden(),
                'modules_count' => count($modules),
                'lessons_count' => $totalLessons,
                'progress_percent' => $progressPercent,
            ];
        }, $courses);

        return $this->json([
            'courses' => $courseData,
        ]);
    }

    #[Route('/courses', name: 'campus_courses', methods: ['GET'])]
    public function courses(Request $request): JsonResponse
    {
        $courses = $this->courseRepo->findAllOrdered();

        $data = array_map(function (CampusCourse $c) {
            return [
                'id' => $c->getId(),
                'title' => $c->getTitle(),
                'emoji' => $c->getEmoji(),
                'description' => $c->getDescription(),
                'thumbnail' => $c->getThumbnail(),
                'orden' => $c->getOrden(),
            ];
        }, $courses);

        return $this->json($data);
    }

    #[Route('/courses/{id}', name: 'campus_course_detail', methods: ['GET'])]
    public function courseDetail(int $id, Request $request): JsonResponse
    {
        $course = $this->courseRepo->find($id);
        if (!$course) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        $modules = $this->moduleRepo->findByCourse($id);
        $me = $this->getCurrentUser($request);
        $userCode = $me?->getCode();

        $moduleData = array_map(function (CampusModule $m) use ($userCode) {
            $lessons = $this->lessonRepo->findByModule($m->getId());

            $lessonData = array_map(function (CampusLesson $l) use ($userCode) {
                $progress = $userCode ? $this->progressRepo->findByLesson($l->getId(), $userCode) : null;
                $assignment = $this->assignmentRepo->findByLesson($l->getId());
                $materials = $this->materialRepo->findByLesson($l->getId());

                return [
                    'id' => $l->getId(),
                    'title' => $l->getTitle(),
                    'description' => $l->getDescription(),
                    'video_url' => $l->getVideoUrl(),
                    'orden' => $l->getOrden(),
                    'completed' => $progress?->isCompleted() ?? false,
                    'has_assignment' => count($assignment) > 0,
                    'materials' => array_map(fn(CampusMaterial $mat) => [
                        'id' => $mat->getId(),
                        'title' => $mat->getTitle(),
                        'type' => $mat->getType(),
                        'url' => $mat->getUrl(),
                    ], $materials),
                ];
            }, $lessons);

            return [
                'id' => $m->getId(),
                'title' => $m->getTitle(),
                'description' => $m->getDescription(),
                'orden' => $m->getOrden(),
                'lessons' => $lessonData,
            ];
        }, $modules);

        return $this->json([
            'id' => $course->getId(),
            'title' => $course->getTitle(),
            'emoji' => $course->getEmoji(),
            'description' => $course->getDescription(),
            'thumbnail' => $course->getThumbnail(),
            'modules' => $moduleData,
        ]);
    }

    #[Route('/lessons/{id}', name: 'campus_lesson_detail', methods: ['GET'])]
    public function lessonDetail(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $lesson = $this->lessonRepo->find($id);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $progress = $this->progressRepo->findByLesson($id, $userCode);
        $materials = $this->materialRepo->findByLesson($id);
        $assignments = $this->assignmentRepo->findByLesson($id);

        $submission = null;
        if (count($assignments) > 0) {
            $assignment = $assignments[0];
            $submissions = $this->submissionRepo->findBy([
                'assignment' => $assignment,
                'userCode' => $userCode,
            ]);
            if (count($submissions) > 0) {
                $s = $submissions[0];
                $submission = [
                    'id' => $s->getId(),
                    'status' => $s->getStatus(),
                    'files' => $this->storage->serializeFilesForClient($s->getFiles()),
                    'comments' => $s->getComments(),
                    'submitted_at' => $s->getSubmittedAt()->format('c'),
                ];
            }
        }

        return $this->json([
            'id' => $lesson->getId(),
            'title' => $lesson->getTitle(),
            'description' => $lesson->getDescription(),
            'video_url' => $lesson->getVideoUrl(),
            'completed' => $progress?->isCompleted() ?? false,
            'materials' => array_map(fn(CampusMaterial $m) => [
                'id' => $m->getId(),
                'title' => $m->getTitle(),
                'type' => $m->getType(),
                'url' => $m->getUrl(),
            ], $materials),
            'assignments' => array_map(fn(CampusAssignment $a) => [
                'id' => $a->getId(),
                'title' => $a->getTitle(),
                'description' => $a->getDescription(),
                'objective' => $a->getObjective(),
                'instructions' => $a->getInstructions(),
                'estimated_minutes' => $a->getEstimatedMinutes(),
                'due_date' => $a->getDueDate()?->format('c'),
            ], $assignments),
            'my_submission' => $submission,
        ]);
    }

    #[Route('/lessons/{id}/complete', name: 'campus_lesson_complete', methods: ['POST'])]
    public function completeLesson(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);

        $rateLimit = $this->checkRateLimit($request, 'campus_complete_' . $me->getCode(), 20, 60);
        if ($rateLimit) return $rateLimit;

        $lesson = $this->lessonRepo->find($id);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $progress = $this->progressRepo->findByLesson($id, $me->getCode());
        if (!$progress) {
            $progress = new CampusLessonProgress();
            $progress->setLesson($lesson);
            $progress->setUserCode($me->getCode());
            $this->em->persist($progress);
        }

        $progress->markCompleted();
        $this->em->flush();

        return $this->json(['success' => true, 'completed' => true]);
    }

    /**
     * Lista entregas (status submitted/graded/etc) del usuario.
     */
    #[Route('/assignments', name: 'campus_assignments', methods: ['GET'])]
    public function myAssignments(Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $submissions = $this->submissionRepo->findByUser($userCode);

        $data = [];
        foreach ($submissions as $s) {
            $assignment = $s->getAssignment();
            $lesson = $assignment->getLesson();
            $module = $lesson->getModule();
            $course = $module->getCourse();

            $data[] = [
                'id' => $s->getId(),
                'assignment_id' => $assignment->getId(),
                'title' => $assignment->getTitle(),
                'description' => $assignment->getDescription(),
                'due_date' => $assignment->getDueDate()?->format('c'),
                'status' => $s->getStatus(),
                'submitted_at' => $s->getSubmittedAt()->format('c'),
                'grade' => $s->getFeedback()?->getGrade(),
                'course' => [
                    'id' => $course->getId(),
                    'title' => $course->getTitle(),
                ],
                'module' => [
                    'id' => $module->getId(),
                    'title' => $module->getTitle(),
                ],
                'lesson' => [
                    'id' => $lesson->getId(),
                    'title' => $lesson->getTitle(),
                ],
            ];
        }

        return $this->json($data);
    }

    /**
     * Lista tareas asignadas pero SIN submission aún (pendientes).
     */
    #[Route('/assignments/pending', name: 'campus_assignments_pending', methods: ['GET'])]
    public function pendingAssignments(Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $allAssignments = $this->assignmentRepo->findAll();
        $pending = [];
        foreach ($allAssignments as $a) {
            $existing = $this->submissionRepo->findBy([
                'assignment' => $a,
                'userCode' => $userCode,
            ]);
            if (count($existing) === 0) {
                $lesson = $a->getLesson();
                $module = $lesson->getModule();
                $course = $module->getCourse();
                $pending[] = [
                    'assignment_id' => $a->getId(),
                    'title' => $a->getTitle(),
                    'description' => $a->getDescription(),
                    'objective' => $a->getObjective(),
                    'instructions' => $a->getInstructions(),
                    'estimated_minutes' => $a->getEstimatedMinutes(),
                    'due_date' => $a->getDueDate()?->format('c'),
                    'lesson_id' => $lesson->getId(),
                    'lesson_title' => $lesson->getTitle(),
                    'module_id' => $module->getId(),
                    'module_title' => $module->getTitle(),
                    'course_id' => $course->getId(),
                    'course_title' => $course->getTitle(),
                ];
            }
        }

        return $this->json($pending);
    }

    #[Route('/assignments/{id}/submit', name: 'campus_submit', methods: ['POST'])]
    public function submitAssignment(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);

        $rateLimit = $this->checkRateLimit($request, 'campus_submit_' . $me->getCode(), 10, 60);
        if ($rateLimit) return $rateLimit;

        $assignment = $this->assignmentRepo->find($id);
        if (!$assignment) {
            return $this->json(['error' => 'Tarea no encontrada'], 404);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], 400);
        }

        $files = $this->storage->validateClientFiles($data['files'] ?? [], $me);

        try {
            $existingSubmissions = $this->submissionRepo->findBy([
                'assignment' => $assignment,
                'userCode' => $me->getCode(),
            ]);

            if (count($existingSubmissions) > 0) {
                $submission = $existingSubmissions[0];
                $this->storage->cleanupFiles($submission->getFiles());
                $submission->setFiles($files);
                $submission->setComments($data['comments'] ?? null);
                $submission->setStatus(CampusSubmission::STATUS_SUBMITTED);
                $submission->setSubmittedAt(new \DateTimeImmutable());
                $submission->touch();
            } else {
                $submission = new CampusSubmission();
                $submission->setAssignment($assignment);
                $submission->setUserCode($me->getCode());
                $submission->setFiles($files);
                $submission->setComments($data['comments'] ?? null);
                $submission->setStatus(CampusSubmission::STATUS_SUBMITTED);
                $this->em->persist($submission);
            }

            $this->em->flush();

            return $this->json([
                'success' => true,
                'submission_id' => $submission->getId(),
                'status' => $submission->getStatus(),
                'files' => $this->storage->serializeFilesForClient($submission->getFiles()),
            ], Response::HTTP_CREATED);
        } catch (\Throwable $e) {
            $this->storage->cleanupFiles($files);
            throw $e;
        }
    }

    #[Route('/submissions/{id}', name: 'campus_submission_detail', methods: ['GET'])]
    public function submissionDetail(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $isAdmin = $me->getIsAdmin();

        $submission = $this->submissionRepo->find($id);
        if (!$submission) {
            return $this->json(['error' => 'Entrega no encontrada'], 404);
        }
        if (!$isAdmin && $submission->getUserCode() !== $me->getCode()) {
            return $this->json(['error' => 'Entrega no encontrada'], 404);
        }

        return $this->json($this->serializeSubmission($submission));
    }

    /**
     * Reemplazar archivos/comentarios de una entrega existente (PUT).
     */
    #[Route('/submissions/{id}', name: 'campus_submission_update', methods: ['PUT', 'PATCH'])]
    public function updateSubmission(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);

        $submission = $this->submissionRepo->find($id);
        if (!$submission || $submission->getUserCode() !== $me->getCode()) {
            return $this->json(['error' => 'Entrega no encontrada'], 404);
        }

        $feedback = $submission->getFeedback();
        if ($feedback && $feedback->getGradedAt()) {
            return $this->json(['error' => 'No puedes modificar una entrega ya corregida'], 409);
        }

        $rateLimit = $this->checkRateLimit($request, 'campus_submit_' . $me->getCode(), 10, 60);
        if ($rateLimit) return $rateLimit;

        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return $this->json(['error' => 'JSON inválido'], 400);
        }

        $newFiles = null;
        $hasNewFiles = array_key_exists('files', $data);
        if ($hasNewFiles) {
            $newFiles = $this->storage->validateClientFiles($data['files'], $me);
        }

        try {
            $oldFiles = $hasNewFiles ? $submission->getFiles() : null;
            if ($hasNewFiles) {
                $submission->setFiles($newFiles);
            }
            if (array_key_exists('comments', $data)) {
                $submission->setComments($data['comments']);
            }
            $submission->setStatus(CampusSubmission::STATUS_SUBMITTED);
            $submission->setSubmittedAt(new \DateTimeImmutable());
            $submission->touch();

            $this->em->flush();

            if ($hasNewFiles && $oldFiles !== null) {
                $this->storage->cleanupFiles($oldFiles);
            }

            return $this->json([
                'success' => true,
                'submission_id' => $submission->getId(),
                'status' => $submission->getStatus(),
                'files' => $this->storage->serializeFilesForClient($submission->getFiles()),
            ]);
        } catch (\Throwable $e) {
            if ($newFiles !== null) {
                $this->storage->cleanupFiles($newFiles);
            }
            throw $e;
        }
    }

    /**
     * Borrar una entrega del usuario autenticado.
     */
    #[Route('/submissions/{id}', name: 'campus_submission_delete', methods: ['DELETE'])]
    public function deleteSubmission(int $id, Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);

        $submission = $this->submissionRepo->find($id);
        if (!$submission || $submission->getUserCode() !== $me->getCode()) {
            return $this->json(['error' => 'Entrega no encontrada'], 404);
        }

        $files = $submission->getFiles();
        $this->em->remove($submission);
        $this->em->flush();

        $this->storage->cleanupFiles($files);

        return $this->json(['success' => true]);
    }

    /**
     * Descarga autenticada de un archivo entregado.
     * Token es el `storage_name` del archivo (opaco).
     * El ownership se infiere del path (carpeta user_code) o de la sidecar JSON si existe.
     * Admins solo pueden descargar si presentan X-Admin-Password válido
     * (no usamos getIsAdmin() porque por defecto cualquier admin entraría sin pass).
     */
    #[Route('/files/{token}', name: 'campus_file_download', methods: ['GET'])]
    public function downloadFile(string $token, Request $request): Response
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return new Response('Unauthorized', 401);

        $info = $this->storage->resolveDownload($token);
        if (!$info) return new Response('No encontrado', 404);

        $ownerCode = $info['user_code'] ?: $this->storage->extractUserCodeFromPath($info['storage_name']);
        if (!$ownerCode) {
            return new Response('No encontrado', 404);
        }
        $isOwner = strtoupper($ownerCode) === $me->getCode();
        if (!$isOwner) {
            // Admin solo puede acceder con X-Admin-Password válido.
            try {
                $this->requireAdmin($request);
            } catch (\Throwable $e) {
                return new Response('No encontrado', 404);
            }
        }

        $path = $this->storage->getAbsolutePath($info['storage_name']);
        if (!is_file($path)) return new Response('Archivo no encontrado', 404);

        return new Response(
            file_get_contents($path),
            200,
            [
                'Content-Type' => $info['mime'] ?: 'application/octet-stream',
                'Content-Disposition' => 'attachment; filename="' . rawurlencode($info['original_name'] ?: $info['storage_name']) . '"',
                'Content-Length' => (string) filesize($path),
                'Cache-Control' => 'private, no-store',
            ]
        );
    }

    #[Route('/history', name: 'campus_history', methods: ['GET'])]
    public function history(Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $submissions = $this->submissionRepo->findByUser($userCode);

        $data = [];
        $totalGrades = 0;
        $countGrades = 0;

        foreach ($submissions as $s) {
            $assignment = $s->getAssignment();
            $lesson = $assignment->getLesson();
            $module = $lesson->getModule();
            $course = $module->getCourse();
            $feedback = $s->getFeedback();

            if ($feedback && $feedback->getGrade()) {
                $totalGrades += (float) $feedback->getGrade();
                $countGrades++;
            }

            $data[] = [
                'id' => $s->getId(),
                'assignment_title' => $assignment->getTitle(),
                'course_title' => $course->getTitle(),
                'module_title' => $module->getTitle(),
                'lesson_title' => $lesson->getTitle(),
                'status' => $s->getStatus(),
                'grade' => $feedback?->getGrade(),
                'submitted_at' => $s->getSubmittedAt()->format('c'),
            ];
        }

        $average = $countGrades > 0 ? round($totalGrades / $countGrades, 2) : null;

        return $this->json([
            'submissions' => $data,
            'total_count' => count($submissions),
            'graded_count' => $countGrades,
            'average_grade' => $average,
        ]);
    }

    #[Route('/progress', name: 'campus_progress', methods: ['GET'])]
    public function progress(Request $request): JsonResponse
    {
        $me = $this->getCurrentUser($request);
        if (!$me) return $this->json(['error' => 'Unauthorized'], 401);
        $userCode = $me->getCode();

        $courses = $this->courseRepo->findAllOrdered();

        $courseProgress = [];
        $totalLessons = 0;
        $totalCompleted = 0;

        foreach ($courses as $course) {
            $modules = $this->moduleRepo->findByCourse($course->getId());
            $courseLessons = 0;
            $courseCompleted = 0;

            foreach ($modules as $module) {
                $lessons = $this->lessonRepo->findByModule($module->getId());
                $courseLessons += count($lessons);
                $totalLessons += count($lessons);

                foreach ($lessons as $lesson) {
                    $progress = $this->progressRepo->findByLesson($lesson->getId(), $userCode);
                    if ($progress && $progress->isCompleted()) {
                        $courseCompleted++;
                        $totalCompleted++;
                    }
                }
            }

            $courseProgress[] = [
                'course_id' => $course->getId(),
                'course_title' => $course->getTitle(),
                'course_emoji' => $course->getEmoji(),
                'total_lessons' => $courseLessons,
                'completed_lessons' => $courseCompleted,
                'progress_percent' => $courseLessons > 0 ? round(($courseCompleted / $courseLessons) * 100) : 0,
            ];
        }

        $submissions = $this->submissionRepo->findByUser($userCode);
        $pendingCount = 0;
        $totalGrades = 0;
        $gradedCount = 0;

        foreach ($submissions as $s) {
            if ($s->getStatus() === CampusSubmission::STATUS_SUBMITTED || $s->getStatus() === CampusSubmission::STATUS_PENDING) {
                $pendingCount++;
            }
            $feedback = $s->getFeedback();
            if ($feedback && $feedback->getGrade()) {
                $totalGrades += (float) $feedback->getGrade();
                $gradedCount++;
            }
        }

        return $this->json([
            'courses' => $courseProgress,
            'global' => [
                'total_lessons' => $totalLessons,
                'completed_lessons' => $totalCompleted,
                'progress_percent' => $totalLessons > 0 ? round(($totalCompleted / $totalLessons) * 100) : 0,
            ],
            'assignments' => [
                'total' => count($submissions),
                'pending' => $pendingCount,
                'graded' => $gradedCount,
                'average_grade' => $gradedCount > 0 ? round($totalGrades / $gradedCount, 2) : null,
            ],
        ]);
    }

    private function serializeSubmission(CampusSubmission $submission): array
    {
        $assignment = $submission->getAssignment();
        $feedback = $submission->getFeedback();
        return [
            'id' => $submission->getId(),
            'status' => $submission->getStatus(),
            'files' => $this->storage->serializeFilesForClient($submission->getFiles()),
            'comments' => $submission->getComments(),
            'submitted_at' => $submission->getSubmittedAt()->format('c'),
            'assignment' => [
                'id' => $assignment->getId(),
                'title' => $assignment->getTitle(),
                'description' => $assignment->getDescription(),
                'objective' => $assignment->getObjective(),
                'instructions' => $assignment->getInstructions(),
                'due_date' => $assignment->getDueDate()?->format('c'),
            ],
            'feedback' => $feedback ? [
                'grade' => $feedback->getGrade(),
                'comment' => $feedback->getComment(),
                'graded_at' => $feedback->getGradedAt()->format('c'),
            ] : null,
        ];
    }
}
