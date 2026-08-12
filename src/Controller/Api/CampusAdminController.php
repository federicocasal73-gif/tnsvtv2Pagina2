<?php

namespace App\Controller\Api;

use App\Entity\CampusAssignment;
use App\Entity\CampusCourse;
use App\Entity\CampusEnrollment;
use App\Entity\CampusFeedback;
use App\Entity\CampusLesson;
use App\Entity\CampusMaterial;
use App\Entity\CampusModule;
use App\Entity\CampusSubmission;
use App\Entity\User;
use App\Repository\CampusEnrollmentRepository;
use App\Repository\CampusLessonProgressRepository;
use App\Repository\CampusSubmissionRepository;
use App\Repository\UserRepository;
use App\Security\AdminAuthTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/campus/admin')]
class CampusAdminController extends AbstractController
{
    use AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
    ) {}

    private function getCourseRepo()
    {
        return $this->em->getRepository(CampusCourse::class);
    }

    private function getModuleRepo()
    {
        return $this->em->getRepository(CampusModule::class);
    }

    private function getLessonRepo()
    {
        return $this->em->getRepository(CampusLesson::class);
    }

    private function getAssignmentRepo()
    {
        return $this->em->getRepository(CampusAssignment::class);
    }

    private function getMaterialRepo()
    {
        return $this->em->getRepository(CampusMaterial::class);
    }

    private function getSubmissionRepo(): CampusSubmissionRepository
    {
        return $this->em->getRepository(CampusSubmission::class);
    }

    private function getEnrollmentRepo(): CampusEnrollmentRepository
    {
        return $this->em->getRepository(CampusEnrollment::class);
    }

    private function getProgressRepo(): CampusLessonProgressRepository
    {
        return $this->em->getRepository(CampusLessonProgress::class);
    }

    private function getUserRepo(): UserRepository
    {
        return $this->em->getRepository(User::class);
    }

    // === COURSES ===

    #[Route('/courses', name: 'campus_admin_courses', methods: ['GET'])]
    public function listCourses(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $courses = $this->getCourseRepo()->findAllForAdmin();

        $data = array_map(function (CampusCourse $c) {
            $modules = $this->getModuleRepo()->findByCourse($c->getId());
            $lessonCount = 0;
            foreach ($modules as $m) {
                $lessonCount += count($this->getLessonRepo()->findByModule($m->getId()));
            }

            return [
                'id' => $c->getId(),
                'title' => $c->getTitle(),
                'emoji' => $c->getEmoji(),
                'description' => $c->getDescription(),
                'thumbnail' => $c->getThumbnail(),
                'orden' => $c->getOrden(),
                'is_active' => $c->isActive(),
                'modules_count' => count($modules),
                'lessons_count' => $lessonCount,
                'created_at' => $c->getCreatedAt()->format('c'),
            ];
        }, $courses);

        return $this->json($data);
    }

    #[Route('/courses', name: 'campus_admin_create_course', methods: ['POST'])]
    public function createCourse(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $course = new CampusCourse();
        $course->setTitle($data['title'] ?? 'Nuevo Curso');
        $course->setEmoji($data['emoji'] ?? '📚');
        $course->setDescription($data['description'] ?? null);
        $course->setThumbnail($data['thumbnail'] ?? null);
        $course->setOrden((int) ($data['orden'] ?? 99));
        $course->setActive($data['is_active'] ?? true);

        $this->em->persist($course);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $course->getId()], Response::HTTP_CREATED);
    }

    #[Route('/courses/{id}', name: 'campus_admin_update_course', methods: ['PUT'])]
    public function updateCourse(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $course = $this->getCourseRepo()->find($id);
        if (!$course) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) $course->setTitle($data['title']);
        if (isset($data['emoji'])) $course->setEmoji($data['emoji']);
        if (isset($data['description'])) $course->setDescription($data['description']);
        if (isset($data['thumbnail'])) $course->setThumbnail($data['thumbnail']);
        if (isset($data['orden'])) $course->setOrden((int) $data['orden']);
        if (isset($data['is_active'])) $course->setActive($data['is_active']);

        $course->touch();
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/courses/{id}', name: 'campus_admin_delete_course', methods: ['DELETE'])]
    public function deleteCourse(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $course = $this->getCourseRepo()->find($id);
        if (!$course) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        $this->em->remove($course);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // === MODULES ===

    #[Route('/modules', name: 'campus_admin_modules', methods: ['GET'])]
    public function listModules(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $courseId = $request->query->get('course_id');

        if ($courseId) {
            $modules = $this->getModuleRepo()->findByCourse((int) $courseId);
        } else {
            $courses = $this->getCourseRepo()->findAllForAdmin();
            $modules = [];
            foreach ($courses as $course) {
                $modules = array_merge($modules, $this->getModuleRepo()->findByCourse($course->getId()));
            }
        }

        $data = array_map(function (CampusModule $m) {
            $lessons = $this->getLessonRepo()->findByModule($m->getId());
            return [
                'id' => $m->getId(),
                'course_id' => $m->getCourse()->getId(),
                'course_title' => $m->getCourse()->getTitle(),
                'title' => $m->getTitle(),
                'description' => $m->getDescription(),
                'orden' => $m->getOrden(),
                'lessons_count' => count($lessons),
            ];
        }, $modules);

        return $this->json($data);
    }

    #[Route('/modules', name: 'campus_admin_create_module', methods: ['POST'])]
    public function createModule(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $course = $this->getCourseRepo()->find($data['course_id'] ?? 0);
        if (!$course) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        $module = new CampusModule();
        $module->setCourse($course);
        $module->setTitle($data['title'] ?? 'Nuevo Módulo');
        $module->setDescription($data['description'] ?? null);
        $module->setOrden((int) ($data['orden'] ?? 99));

        $this->em->persist($module);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $module->getId()], Response::HTTP_CREATED);
    }

    #[Route('/modules/{id}', name: 'campus_admin_update_module', methods: ['PUT'])]
    public function updateModule(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $module = $this->getModuleRepo()->find($id);
        if (!$module) {
            return $this->json(['error' => 'Módulo no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) $module->setTitle($data['title']);
        if (isset($data['description'])) $module->setDescription($data['description']);
        if (isset($data['orden'])) $module->setOrden((int) $data['orden']);
        if (isset($data['course_id'])) {
            $course = $this->getCourseRepo()->find($data['course_id']);
            if ($course) $module->setCourse($course);
        }

        $module->touch();
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/modules/{id}', name: 'campus_admin_delete_module', methods: ['DELETE'])]
    public function deleteModule(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $module = $this->getModuleRepo()->find($id);
        if (!$module) {
            return $this->json(['error' => 'Módulo no encontrado'], 404);
        }

        $this->em->remove($module);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // === LESSONS ===

    #[Route('/lessons', name: 'campus_admin_lessons', methods: ['GET'])]
    public function listLessons(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $moduleId = $request->query->get('module_id');

        if ($moduleId) {
            $lessons = $this->getLessonRepo()->findByModule((int) $moduleId);
        } else {
            $courses = $this->getCourseRepo()->findAllForAdmin();
            $lessons = [];
            foreach ($courses as $course) {
                $modules = $this->getModuleRepo()->findByCourse($course->getId());
                foreach ($modules as $module) {
                    $lessons = array_merge($lessons, $this->getLessonRepo()->findByModule($module->getId()));
                }
            }
        }

        $data = array_map(function (CampusLesson $l) {
            $assignments = $this->getAssignmentRepo()->findByLesson($l->getId());
            $materials = $this->getMaterialRepo()->findByLesson($l->getId());
            return [
                'id' => $l->getId(),
                'module_id' => $l->getModule()->getId(),
                'module_title' => $l->getModule()->getTitle(),
                'course_title' => $l->getModule()->getCourse()->getTitle(),
                'title' => $l->getTitle(),
                'description' => $l->getDescription(),
                'video_url' => $l->getVideoUrl(),
                'orden' => $l->getOrden(),
                'assignments_count' => count($assignments),
                'materials_count' => count($materials),
            ];
        }, $lessons);

        return $this->json($data);
    }

    #[Route('/lessons', name: 'campus_admin_create_lesson', methods: ['POST'])]
    public function createLesson(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $module = $this->getModuleRepo()->find($data['module_id'] ?? 0);
        if (!$module) {
            return $this->json(['error' => 'Módulo no encontrado'], 404);
        }

        $lesson = new CampusLesson();
        $lesson->setModule($module);
        $lesson->setTitle($data['title'] ?? 'Nueva Lección');
        $lesson->setDescription($data['description'] ?? null);
        $lesson->setVideoUrl($data['video_url'] ?? null);
        $lesson->setOrden((int) ($data['orden'] ?? 99));

        $this->em->persist($lesson);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $lesson->getId()], Response::HTTP_CREATED);
    }

    #[Route('/lessons/{id}', name: 'campus_admin_update_lesson', methods: ['PUT'])]
    public function updateLesson(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $lesson = $this->getLessonRepo()->find($id);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) $lesson->setTitle($data['title']);
        if (isset($data['description'])) $lesson->setDescription($data['description']);
        if (isset($data['video_url'])) $lesson->setVideoUrl($data['video_url']);
        if (isset($data['orden'])) $lesson->setOrden((int) $data['orden']);
        if (isset($data['module_id'])) {
            $module = $this->getModuleRepo()->find($data['module_id']);
            if ($module) $lesson->setModule($module);
        }

        $lesson->touch();
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/lessons/{id}', name: 'campus_admin_delete_lesson', methods: ['DELETE'])]
    public function deleteLesson(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $lesson = $this->getLessonRepo()->find($id);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $this->em->remove($lesson);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // === ASSIGNMENTS ===

    #[Route('/assignments', name: 'campus_admin_assignments', methods: ['GET'])]
    public function listAssignments(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $lessonId = $request->query->get('lesson_id');

        if ($lessonId) {
            $assignments = $this->getAssignmentRepo()->findByLesson((int) $lessonId);
        } else {
            $assignments = $this->getAssignmentRepo()->findAll();
        }

        $data = array_map(function (CampusAssignment $a) {
            $submissions = $this->getSubmissionRepo()->findByAssignment($a->getId());
            return [
                'id' => $a->getId(),
                'lesson_id' => $a->getLesson()->getId(),
                'lesson_title' => $a->getLesson()->getTitle(),
                'course_title' => $a->getLesson()->getModule()->getCourse()->getTitle(),
                'title' => $a->getTitle(),
                'description' => $a->getDescription(),
                'objective' => $a->getObjective(),
                'instructions' => $a->getInstructions(),
                'estimated_minutes' => $a->getEstimatedMinutes(),
                'due_date' => $a->getDueDate()?->format('c'),
                'submissions_count' => count($submissions),
            ];
        }, $assignments);

        return $this->json($data);
    }

    #[Route('/assignments', name: 'campus_admin_create_assignment', methods: ['POST'])]
    public function createAssignment(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $lesson = $this->getLessonRepo()->find($data['lesson_id'] ?? 0);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $assignment = new CampusAssignment();
        $assignment->setLesson($lesson);
        $assignment->setTitle($data['title'] ?? 'Nueva Tarea');
        $assignment->setDescription($data['description'] ?? null);
        $assignment->setObjective($data['objective'] ?? null);
        $assignment->setInstructions($data['instructions'] ?? null);
        $assignment->setEstimatedMinutes($data['estimated_minutes'] ?? null);
        if (!empty($data['due_date'])) {
            $assignment->setDueDate(new \DateTimeImmutable($data['due_date']));
        }

        $this->em->persist($assignment);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $assignment->getId()], Response::HTTP_CREATED);
    }

    #[Route('/assignments/{id}', name: 'campus_admin_update_assignment', methods: ['PUT'])]
    public function updateAssignment(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $assignment = $this->getAssignmentRepo()->find($id);
        if (!$assignment) {
            return $this->json(['error' => 'Tarea no encontrada'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) $assignment->setTitle($data['title']);
        if (isset($data['description'])) $assignment->setDescription($data['description']);
        if (isset($data['objective'])) $assignment->setObjective($data['objective']);
        if (isset($data['instructions'])) $assignment->setInstructions($data['instructions']);
        if (isset($data['estimated_minutes'])) $assignment->setEstimatedMinutes($data['estimated_minutes']);
        if (isset($data['due_date'])) {
            $assignment->setDueDate($data['due_date'] ? new \DateTimeImmutable($data['due_date']) : null);
        }
        if (isset($data['lesson_id'])) {
            $lesson = $this->getLessonRepo()->find($data['lesson_id']);
            if ($lesson) $assignment->setLesson($lesson);
        }

        $assignment->touch();
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/assignments/{id}', name: 'campus_admin_delete_assignment', methods: ['DELETE'])]
    public function deleteAssignment(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $assignment = $this->getAssignmentRepo()->find($id);
        if (!$assignment) {
            return $this->json(['error' => 'Tarea no encontrada'], 404);
        }

        $this->em->remove($assignment);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // === MATERIALS ===

    #[Route('/materials', name: 'campus_admin_materials', methods: ['GET'])]
    public function listMaterials(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $lessonId = $request->query->get('lesson_id');

        if ($lessonId) {
            $materials = $this->getMaterialRepo()->findByLesson((int) $lessonId);
        } else {
            $materials = $this->getMaterialRepo()->findAll();
        }

        $data = array_map(function (CampusMaterial $m) {
            return [
                'id' => $m->getId(),
                'lesson_id' => $m->getLesson()->getId(),
                'lesson_title' => $m->getLesson()->getTitle(),
                'title' => $m->getTitle(),
                'type' => $m->getType(),
                'url' => $m->getUrl(),
                'orden' => $m->getOrden(),
            ];
        }, $materials);

        return $this->json($data);
    }

    #[Route('/materials', name: 'campus_admin_create_material', methods: ['POST'])]
    public function createMaterial(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $lesson = $this->getLessonRepo()->find($data['lesson_id'] ?? 0);
        if (!$lesson) {
            return $this->json(['error' => 'Lección no encontrada'], 404);
        }

        $material = new CampusMaterial();
        $material->setLesson($lesson);
        $material->setTitle($data['title'] ?? 'Nuevo Material');
        $material->setType($data['type'] ?? CampusMaterial::TYPE_LINK);
        $material->setUrl($data['url'] ?? '');
        $material->setOrden((int) ($data['orden'] ?? 99));

        $this->em->persist($material);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $material->getId()], Response::HTTP_CREATED);
    }

    #[Route('/materials/{id}', name: 'campus_admin_update_material', methods: ['PUT'])]
    public function updateMaterial(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $material = $this->getMaterialRepo()->find($id);
        if (!$material) {
            return $this->json(['error' => 'Material no encontrado'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) $material->setTitle($data['title']);
        if (isset($data['type'])) $material->setType($data['type']);
        if (isset($data['url'])) $material->setUrl($data['url']);
        if (isset($data['orden'])) $material->setOrden((int) $data['orden']);
        if (isset($data['lesson_id'])) {
            $lesson = $this->getLessonRepo()->find($data['lesson_id']);
            if ($lesson) $material->setLesson($lesson);
        }

        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/materials/{id}', name: 'campus_admin_delete_material', methods: ['DELETE'])]
    public function deleteMaterial(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $material = $this->getMaterialRepo()->find($id);
        if (!$material) {
            return $this->json(['error' => 'Material no encontrado'], 404);
        }

        $this->em->remove($material);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    // === SUBMISSIONS (GRADING) ===

    #[Route('/submissions', name: 'campus_admin_submissions', methods: ['GET'])]
    public function listSubmissions(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $assignmentId = $request->query->has('assignment_id') ? (int) $request->query->get('assignment_id') : null;
        $userCode = $request->query->get('user_code') ?: null;
        $status = $request->query->get('status') ?: null;
        $page = max(1, (int) ($request->query->get('page', 1)));
        $limit = min(100, max(1, (int) ($request->query->get('limit', 25))));
        $offset = ($page - 1) * $limit;

        $submissions = $this->getSubmissionRepo()->findAllFiltered($assignmentId, $userCode, $status, $offset, $limit);
        $total = $this->getSubmissionRepo()->countFiltered($assignmentId, $userCode, $status);

        $data = array_map(function (CampusSubmission $s) {
            $assignment = $s->getAssignment();
            return [
                'id' => $s->getId(),
                'assignment_id' => $assignment->getId(),
                'assignment_title' => $assignment->getTitle(),
                'user_code' => $s->getUserCode(),
                'status' => $s->getStatus(),
                'files' => $s->getFiles(),
                'comments' => $s->getComments(),
                'submitted_at' => $s->getSubmittedAt()->format('c'),
                'grade' => $s->getFeedback()?->getGrade(),
                'feedback_comment' => $s->getFeedback()?->getComment(),
            ];
        }, $submissions);

        return $this->json([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    #[Route('/submissions/{id}/grade', name: 'campus_admin_grade', methods: ['POST'])]
    public function gradeSubmission(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $submission = $this->getSubmissionRepo()->find($id);
        if (!$submission) {
            return $this->json(['error' => 'Entrega no encontrada'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $grade = $data['grade'] ?? null;
        $comment = $data['comment'] ?? null;
        $action = $data['action'] ?? 'corrected';

        $feedback = $submission->getFeedback();
        if (!$feedback) {
            $feedback = new CampusFeedback();
            $feedback->setSubmission($submission);
            $this->em->persist($feedback);
        }

        if ($grade !== null) {
            $feedback->setGrade((string) $grade);
        }
        if ($comment !== null) {
            $feedback->setComment($comment);
        }

        $submission->setStatus($action);
        $this->em->flush();

        return $this->json(['success' => true, 'status' => $submission->getStatus()]);
    }

    // === USER PROGRESS ===

    #[Route('/user-progress', name: 'campus_admin_user_progress', methods: ['GET'])]
    public function userProgress(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $userCode = $request->query->get('user_code');
        if (!$userCode) {
            return $this->json(['error' => 'Se requiere user_code'], 400);
        }

        $user = $this->getUserRepo()->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        // Lessons progress grouped by course
        $progressByCourse = $this->getProgressRepo()->countCompletedByCourse($userCode);

        // Build a map course_id => {total, completed}
        $courseProgressMap = [];
        foreach ($progressByCourse as $row) {
            $cid = (int) $row['course_id'];
            $courseProgressMap[$cid] = [
                'total' => (int) ($row['total'] ?? 0),
                'completed' => (int) ($row['completed'] ?? 0),
            ];
        }

        // User submissions
        $submissions = $this->getSubmissionRepo()->findByUser($userCode);

        // Group submissions by course_id via assignment->lesson->module->course
        $submissionsByCourse = [];
        foreach ($submissions as $s) {
            $assignment = $s->getAssignment();
            if (!$assignment) continue;
            $lesson = $assignment->getLesson();
            if (!$lesson) continue;
            $module = $lesson->getModule();
            if (!$module) continue;
            $course = $module->getCourse();
            if (!$course) continue;

            $cid = $course->getId();
            if (!isset($submissionsByCourse[$cid])) {
                $submissionsByCourse[$cid] = [];
            }
            $submissionsByCourse[$cid][] = [
                'id' => $s->getId(),
                'assignment_title' => $assignment->getTitle(),
                'status' => $s->getStatus(),
                'submitted_at' => $s->getSubmittedAt()->format('c'),
                'grade' => $s->getFeedback()?->getGrade(),
                'files_count' => count($s->getFiles() ?? []),
            ];
        }

        // Fetch all courses and build the response
        $allCourses = $this->getCourseRepo()->findAllForAdmin();
        $coursesData = [];
        $totals = ['total_lessons' => 0, 'completed_lessons' => 0, 'total_submissions' => 0, 'graded_submissions' => 0, 'sum_grades' => 0];

        foreach ($allCourses as $course) {
            $cid = $course->getId();
            $modules = $this->getModuleRepo()->findByCourse($cid);
            $totalLessons = 0;
            foreach ($modules as $m) {
                $totalLessons += count($this->getLessonRepo()->findByModule($m->getId()));
            }

            $prog = $courseProgressMap[$cid] ?? ['total' => 0, 'completed' => 0];
            $completed = $prog['completed'];
            $progressPercent = $totalLessons > 0 ? (int) round(($completed / $totalLessons) * 100) : 0;

            $courseSubmissions = $submissionsByCourse[$cid] ?? [];
            $totals['total_lessons'] += $totalLessons;
            $totals['completed_lessons'] += $completed;
            $totals['total_submissions'] += count($courseSubmissions);

            foreach ($courseSubmissions as $cs) {
                if ($cs['grade'] !== null) {
                    $totals['graded_submissions']++;
                    $totals['sum_grades'] += (float) $cs['grade'];
                }
            }

            $coursesData[] = [
                'course_id' => $cid,
                'course_title' => $course->getTitle(),
                'course_emoji' => $course->getEmoji(),
                'total_lessons' => $totalLessons,
                'completed_lessons' => $completed,
                'progress_percent' => $progressPercent,
                'submissions' => $courseSubmissions,
            ];
        }

        $avgGrade = $totals['graded_submissions'] > 0
            ? round($totals['sum_grades'] / $totals['graded_submissions'], 1)
            : null;

        return $this->json([
            'user' => [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'is_admin' => $user->isAdmin(),
                'avatar_url' => $user->getAvatarUrl(),
                'avatar_color' => $user->getAvatarColor(),
            ],
            'courses' => $coursesData,
            'totals' => [
                'total_lessons' => $totals['total_lessons'],
                'completed_lessons' => $totals['completed_lessons'],
                'progress_percent' => $totals['total_lessons'] > 0 ? (int) round(($totals['completed_lessons'] / $totals['total_lessons']) * 100) : 0,
                'total_submissions' => $totals['total_submissions'],
                'graded_submissions' => $totals['graded_submissions'],
                'average_grade' => $avgGrade,
            ],
        ]);
    }

    // === USERS WITH ACTIVITY ===

    #[Route('/users-with-activity', name: 'campus_admin_users_with_activity', methods: ['GET'])]
    public function usersWithActivity(Request $request): JsonResponse
    {
        $this->requireAdmin($request);

        $submissionUsers = $this->getSubmissionRepo()->findDistinctUserCodesWithActivity();
        $progressUsers = $this->getProgressRepo()->findDistinctUserCodes();

        $allCodes = array_unique(array_merge($submissionUsers, $progressUsers));
        sort($allCodes);

        $users = [];
        foreach ($allCodes as $code) {
            $user = $this->getUserRepo()->findByCode($code);
            if (!$user) continue;
            $subCount = $this->getSubmissionRepo()->countByUser($code);
            $completed = $this->getProgressRepo()->countCompletedByUser($code);
            $total = $this->getProgressRepo()->countTotalByUser($code);

            $users[] = [
                'code' => $user->getCode(),
                'name' => $user->getName(),
                'is_admin' => $user->isAdmin(),
                'avatar_url' => $user->getAvatarUrl(),
                'avatar_color' => $user->getAvatarColor(),
                'submissions_count' => $subCount,
                'completed_lessons' => $completed,
                'total_lessons' => $total,
            ];
        }

        return $this->json(['users' => $users]);
    }

    // === ENROLLMENTS ===

    #[Route('/enrollments', name: 'campus_admin_create_enrollment', methods: ['POST'])]
    public function createEnrollment(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $data = json_decode($request->getContent(), true);

        $courseId = (int) ($data['course_id'] ?? 0);
        $userCode = $data['user_code'] ?? '';

        if (!$courseId || !$userCode) {
            return $this->json(['error' => 'Se requiere course_id y user_code'], 400);
        }

        $course = $this->getCourseRepo()->find($courseId);
        if (!$course) {
            return $this->json(['error' => 'Curso no encontrado'], 404);
        }

        $user = $this->getUserRepo()->findByCode($userCode);
        if (!$user) {
            return $this->json(['error' => 'Usuario no encontrado'], 404);
        }

        if ($this->getEnrollmentRepo()->isEnrolled($courseId, $userCode)) {
            return $this->json(['error' => 'El usuario ya está matriculado en este curso'], 409);
        }

        $enrollment = new CampusEnrollment();
        $enrollment->setCourse($course);
        $enrollment->setUserCode($userCode);

        $this->em->persist($enrollment);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $enrollment->getId()]);
    }

    #[Route('/enrollments/{id}', name: 'campus_admin_delete_enrollment', methods: ['DELETE'])]
    public function deleteEnrollment(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $enrollment = $this->getEnrollmentRepo()->find($id);
        if (!$enrollment) {
            return $this->json(['error' => 'Matrícula no encontrada'], 404);
        }

        $this->em->remove($enrollment);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    #[Route('/enrollments', name: 'campus_admin_list_enrollments', methods: ['GET'])]
    public function listEnrollments(Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $courseId = $request->query->get('course_id');
        $userCode = $request->query->get('user_code');

        if ($courseId) {
            $enrollments = $this->getEnrollmentRepo()->findByCourse((int) $courseId);
        } elseif ($userCode) {
            $enrollments = $this->getEnrollmentRepo()->findByUser($userCode);
        } else {
            return $this->json(['error' => 'Filtrá por course_id o user_code'], 400);
        }

        $data = array_map(function (CampusEnrollment $e) {
            return [
                'id' => $e->getId(),
                'course_id' => $e->getCourse()->getId(),
                'course_title' => $e->getCourse()->getTitle(),
                'user_code' => $e->getUserCode(),
                'enrolled_at' => $e->getEnrolledAt()->format('c'),
            ];
        }, $enrollments);

        return $this->json($data);
    }
}
