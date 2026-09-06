<?php

namespace App\Controller\Admin;

use App\Entity\CampusCourse;
use App\Entity\CampusLesson;
use App\Entity\CampusModule;
use App\Repository\CampusLessonProgressRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTML routes for the Campus admin UI (Fase 4).
 *
 * The actual CRUD operations hit the JSON API at /api/campus/admin/* —
 * these routes only render the templates that drive those calls.
 *
 *   GET /sanctum/campus/admin/courses            list + drag-drop reorder
 *   GET /sanctum/campus/admin/courses/new        create form
 *   GET /sanctum/campus/admin/courses/{id}       edit + manage modules/lessons
 *   GET /sanctum/campus/admin/courses/{id}/lessons/{lid}  edit single lesson
 *   GET /sanctum/campus/admin/users              student progress overview
 *   GET /sanctum/campus/admin/submissions        grading queue
 */
#[Route('/sanctum/campus/admin')]
class CampusAdminPageController extends AbstractController
{
    public function __construct(
        private CampusLessonProgressRepository $progressRepository,
        private UserRepository $userRepository,
    ) {}

    #[Route('/courses', name: 'sanctum_campus_admin_courses', methods: ['GET'])]
    public function courses(): Response
    {
        return $this->render('sanctum/campus_admin/courses.html.twig');
    }

    #[Route('/courses/new', name: 'sanctum_campus_admin_course_new', methods: ['GET'])]
    public function newCourse(): Response
    {
        return $this->render('sanctum/campus_admin/course_edit.html.twig', [
            'is_edit' => false,
            'course_id' => 0,
        ]);
    }

    #[Route('/courses/{id}', name: 'sanctum_campus_admin_course_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editCourse(int $id): Response
    {
        return $this->render('sanctum/campus_admin/course_edit.html.twig', [
            'is_edit' => true,
            'course_id' => $id,
        ]);
    }

    #[Route('/courses/{courseId}/lessons/{lessonId}', name: 'sanctum_campus_admin_lesson_edit',
        methods: ['GET'], requirements: ['courseId' => '\d+', 'lessonId' => '\d+'])]
    public function editLesson(int $courseId, int $lessonId): Response
    {
        return $this->render('sanctum/campus_admin/lesson_edit.html.twig', [
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
        ]);
    }

    #[Route('/submissions', name: 'sanctum_campus_admin_submissions', methods: ['GET'])]
    public function submissions(): Response
    {
        return $this->render('sanctum/campus_admin/submissions.html.twig');
    }

    #[Route('/users', name: 'sanctum_campus_admin_users', methods: ['GET'])]
    public function users(): Response
    {
        $students = $this->userRepository->findBy(['active' => true], ['name' => 'ASC']);
        return $this->render('sanctum/campus_admin/users.html.twig', [
            'students' => $students,
        ]);
    }
}
