<?php

namespace App\Controller\Api;

use App\Entity\CampusLesson;
use App\Entity\CampusQuiz;
use App\Entity\CampusQuizQuestion;
use App\Entity\CampusQuizSubmission;
use App\Entity\User;
use App\Service\CampusQuizGrader;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * TNSVT Quiz API — Fase 4.
 *
 *   GET    /api/campus/lessons/{lid}/quiz       get the quiz (student — without correct answers)
 *   POST   /api/campus/lessons/{lid}/quiz/submit  student submits answers, gets auto-graded
 *
 *   GET    /api/campus/admin/quiz/{qid}        admin: full quiz with correct answers
 *   POST   /api/campus/admin/quiz               admin: create quiz (one per lesson)
 *   PUT    /api/campus/admin/quiz/{qid}        admin: update quiz
 *   DELETE /api/campus/admin/quiz/{qid}        admin: delete quiz
 *   POST   /api/campus/admin/quiz/{qid}/questions admin: add question
 *   DELETE /api/campus/admin/questions/{id}    admin: delete question
 *
 *   GET    /api/campus/admin/quiz/{qid}/submissions  admin: list student attempts
 */
#[Route('/api/campus')]
class QuizController extends AbstractController
{
    use \App\Security\AdminAuthTrait;

    public function __construct(
        private EntityManagerInterface $em,
        private CampusQuizGrader $grader,
    ) {}

    // ── STUDENT ──
    #[Route('/lessons/{lessonId}/quiz', name: 'campus_quiz_get', methods: ['GET'])]
    public function getQuiz(int $lessonId): JsonResponse
    {
        $quiz = $this->getQuizByLesson($lessonId);
        if (!$quiz) return $this->json(['error' => 'No hay quiz para esta lección'], 404);
        return $this->json([
            'id' => $quiz->getId(),
            'title' => $quiz->getTitle(),
            'description' => $quiz->getDescription(),
            'passing_score' => $quiz->getPassingScore(),
            'time_limit_minutes' => $quiz->getTimeLimitMinutes(),
            'max_attempts' => $quiz->getMaxAttempts(),
            'questions' => array_map(fn(CampusQuizQuestion $q) => $this->serializeQuestionForStudent($q), $quiz->getQuestions()->toArray()),
        ]);
    }

    #[Route('/lessons/{lessonId}/quiz/submit', name: 'campus_quiz_submit', methods: ['POST'])]
    public function submit(int $lessonId, Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        $quiz = $this->getQuizByLesson($lessonId);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);

        $data = json_decode($request->getContent(), true) ?? [];
        $answers = $data['answers'] ?? [];
        $duration = (int) ($data['duration_seconds'] ?? 0);

        $submission = new CampusQuizSubmission();
        $submission->setQuiz($quiz);
        $submission->setUserCode($user?->getCode() ?? ($data['user_code'] ?? null));
        $submission->setDurationSeconds($duration);

        $this->em->persist($submission);
        $this->em->flush();

        $this->grader->grade($submission, $answers);

        return $this->json([
            'success' => true,
            'submission_id' => $submission->getId(),
            'score' => $submission->getScore(),
            'max_score' => $submission->getMaxScore(),
            'percent' => $submission->getPercent(),
            'passed' => $submission->isPassed(),
            'correct_per_question' => $this->buildFeedback($quiz, $answers),
        ]);
    }

    // ── ADMIN ──
    #[Route('/admin/quiz/lesson/{lessonId}', name: 'campus_admin_quiz_by_lesson', methods: ['GET'])]
    public function adminGetByLesson(int $lessonId, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->getQuizByLesson($lessonId);
        if (!$quiz) return $this->json(null, 404);
        return $this->json($this->serializeQuizFull($quiz));
    }

    #[Route('/admin/quiz/{id}', name: 'campus_admin_quiz_get', methods: ['GET'])]
    public function adminGet(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->em->getRepository(CampusQuiz::class)->find($id);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);
        return $this->json($this->serializeQuizFull($quiz));
    }

    #[Route('/admin/quiz/lesson/{lessonId}', name: 'campus_admin_quiz_create', methods: ['POST'])]
    public function adminCreate(int $lessonId, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $lesson = $this->em->getRepository(CampusLesson::class)->find($lessonId);
        if (!$lesson) return $this->json(['error' => 'Lección no encontrada'], 404);
        $existing = $this->em->getRepository(CampusQuiz::class)->findOneBy(['lesson' => $lesson]);
        if ($existing) return $this->json(['error' => 'Esta lección ya tiene quiz'], 409);

        $data = json_decode($request->getContent(), true) ?? [];
        $quiz = new CampusQuiz();
        $quiz->setLesson($lesson);
        $quiz->setTitle($data['title'] ?? 'Quiz de la lección');
        $quiz->setDescription($data['description'] ?? null);
        $quiz->setPassingScore((int) ($data['passing_score'] ?? 70));
        $quiz->setTimeLimitMinutes(isset($data['time_limit_minutes']) ? (int) $data['time_limit_minutes'] : null);
        $quiz->setMaxAttempts((int) ($data['max_attempts'] ?? 1));
        $quiz->setShuffleQuestions((bool) ($data['shuffle_questions'] ?? false));

        $this->em->persist($quiz);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $quiz->getId(), 'quiz' => $this->serializeQuizFull($quiz)], 201);
    }

    #[Route('/admin/quiz/{id}', name: 'campus_admin_quiz_update', methods: ['PUT'])]
    public function adminUpdate(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->em->getRepository(CampusQuiz::class)->find($id);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);

        $data = json_decode($request->getContent(), true) ?? [];
        if (isset($data['title'])) $quiz->setTitle((string) $data['title']);
        if (isset($data['description'])) $quiz->setDescription($data['description']);
        if (isset($data['passing_score'])) $quiz->setPassingScore((int) $data['passing_score']);
        if (isset($data['time_limit_minutes'])) $quiz->setTimeLimitMinutes((int) $data['time_limit_minutes']);
        if (isset($data['max_attempts'])) $quiz->setMaxAttempts((int) $data['max_attempts']);
        if (isset($data['shuffle_questions'])) $quiz->setShuffleQuestions((bool) $data['shuffle_questions']);
        $quiz->touch();

        $this->em->flush();
        return $this->json(['success' => true, 'quiz' => $this->serializeQuizFull($quiz)]);
    }

    #[Route('/admin/quiz/{id}', name: 'campus_admin_quiz_delete', methods: ['DELETE'])]
    public function adminDelete(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->em->getRepository(CampusQuiz::class)->find($id);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);
        $this->em->remove($quiz);
        $this->em->flush();
        return $this->json(['success' => true, 'deleted' => $id]);
    }

    #[Route('/admin/quiz/{id}/questions', name: 'campus_admin_quiz_question_create', methods: ['POST'])]
    public function adminAddQuestion(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->em->getRepository(CampusQuiz::class)->find($id);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);

        $data = json_decode($request->getContent(), true) ?? [];
        $kind = $data['kind'] ?? CampusQuizQuestion::KIND_MULTIPLE_CHOICE;
        if (!in_array($kind, [
            CampusQuizQuestion::KIND_MULTIPLE_CHOICE,
            CampusQuizQuestion::KIND_TRUE_FALSE,
            CampusQuizQuestion::KIND_SHORT_ANSWER,
        ], true)) {
            return $this->json(['error' => 'kind inválido'], 400);
        }

        $q = new CampusQuizQuestion();
        $q->setQuiz($quiz);
        $q->setKind($kind);
        $q->setPrompt((string) ($data['prompt'] ?? ''));
        $q->setOptions(is_array($data['options'] ?? null) ? array_values($data['options']) : null);
        $q->setCorrect($data['correct'] ?? null);
        $q->setExplanation($data['explanation'] ?? null);
        $q->setPoints((int) ($data['points'] ?? 1));
        $q->setOrden((int) ($data['orden'] ?? $quiz->getQuestions()->count()));

        $this->em->persist($q);
        $this->em->flush();

        return $this->json(['success' => true, 'id' => $q->getId(), 'question' => $this->serializeQuestionFull($q)], 201);
    }

    #[Route('/admin/questions/{id}', name: 'campus_admin_question_delete', methods: ['DELETE'])]
    public function adminDeleteQuestion(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $q = $this->em->getRepository(CampusQuizQuestion::class)->find($id);
        if (!$q) return $this->json(['error' => 'Pregunta no encontrada'], 404);
        $this->em->remove($q);
        $this->em->flush();
        return $this->json(['success' => true, 'deleted' => $id]);
    }

    #[Route('/admin/quiz/{id}/submissions', name: 'campus_admin_quiz_submissions', methods: ['GET'])]
    public function adminSubmissions(int $id, Request $request): JsonResponse
    {
        $this->requireAdmin($request);
        $quiz = $this->em->getRepository(CampusQuiz::class)->find($id);
        if (!$quiz) return $this->json(['error' => 'Quiz no encontrado'], 404);

        $subs = $this->em->getRepository(CampusQuizSubmission::class)->findBy(
            ['quiz' => $quiz], ['submittedAt' => 'DESC']);

        return $this->json(array_map(fn(CampusQuizSubmission $s) => [
            'id' => $s->getId(),
            'user_code' => $s->getUserCode(),
            'score' => $s->getScore(),
            'max_score' => $s->getMaxScore(),
            'percent' => $s->getPercent(),
            'passed' => $s->isPassed(),
            'duration_seconds' => $s->getDurationSeconds(),
            'submitted_at' => $s->getSubmittedAt()->format('c'),
        ], $subs));
    }

    // ── Helpers ──
    private function getQuizByLesson(int $lessonId): ?CampusQuiz
    {
        $lesson = $this->em->getRepository(CampusLesson::class)->find($lessonId);
        if (!$lesson) return null;
        return $this->em->getRepository(CampusQuiz::class)->findOneBy(['lesson' => $lesson]);
    }

    private function serializeQuestionForStudent(CampusQuizQuestion $q): array
    {
        return [
            'id' => $q->getId(),
            'kind' => $q->getKind(),
            'prompt' => $q->getPrompt(),
            'options' => $q->getOptions(),
            'points' => $q->getPoints(),
        ];
    }

    private function serializeQuestionFull(CampusQuizQuestion $q): array
    {
        return array_merge($this->serializeQuestionForStudent($q), [
            'correct' => $q->getCorrect(),
            'explanation' => $q->getExplanation(),
            'orden' => $q->getOrden(),
        ]);
    }

    private function serializeQuizFull(CampusQuiz $quiz): array
    {
        return [
            'id' => $quiz->getId(),
            'lesson_id' => $quiz->getLesson()?->getId(),
            'title' => $quiz->getTitle(),
            'description' => $quiz->getDescription(),
            'passing_score' => $quiz->getPassingScore(),
            'time_limit_minutes' => $quiz->getTimeLimitMinutes(),
            'max_attempts' => $quiz->getMaxAttempts(),
            'shuffle_questions' => $quiz->getShuffleQuestions(),
            'questions' => array_map(fn(CampusQuizQuestion $q) => $this->serializeQuestionFull($q), $quiz->getQuestions()->toArray()),
        ];
    }

    private function buildFeedback(CampusQuiz $quiz, array $answers): array
    {
        $feedback = [];
        foreach ($quiz->getQuestions() as $q) {
            $given = $answers[$q->getId()] ?? null;
            $correct = match ($q->getKind()) {
                CampusQuizQuestion::KIND_MULTIPLE_CHOICE, CampusQuizQuestion::KIND_TRUE_FALSE => $q->getCorrect(),
                CampusQuizQuestion::KIND_SHORT_ANSWER => $q->getCorrectString(),
                default => null,
            };
            $feedback[$q->getId()] = [
                'given' => $given,
                'correct' => $correct,
                'is_correct' => $given !== null && $this->matchAnswer($q, $given),
                'explanation' => $q->getExplanation(),
            ];
        }
        return $feedback;
    }

    private function matchAnswer(CampusQuizQuestion $q, mixed $given): bool
    {
        return match ($q->getKind()) {
            CampusQuizQuestion::KIND_MULTIPLE_CHOICE, CampusQuizQuestion::KIND_TRUE_FALSE => (int) $given === (int) $q->getCorrect(),
            CampusQuizQuestion::KIND_SHORT_ANSWER => mb_strtolower(trim((string) $given)) === mb_strtolower(trim($q->getCorrectString())),
            default => false,
        };
    }

    private function resolveUser(Request $request): ?User
    {
        $code = trim((string) $request->headers->get('X-Game-Code', ''));
        if ($code === '') return null;
        return $this->em->getRepository(User::class)->findOneBy(['code' => $code, 'active' => true]);
    }
}
