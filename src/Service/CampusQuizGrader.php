<?php

namespace App\Service;

use App\Entity\CampusQuiz;
use App\Entity\CampusQuizQuestion;
use App\Entity\CampusQuizSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Auto-grader for CampusQuiz.
 *
 * Compares each student answer against the canonical `correct` value:
 *   - multiple_choice: student answer is the index of the selected option
 *   - true_false:     student answer is 0 (true) or 1 (false)
 *   - short_answer:   case-insensitive trimmed string compare
 *
 * Returns the persisted submission with score, maxScore, percent, passed.
 */
class CampusQuizGrader
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    /**
     * Grade the submission in-place. $answers is [question_id => answer].
     *
     * Returns the persisted CampusQuizSubmission with score, maxScore,
     * percent, and passed fields populated.
     */
    public function grade(CampusQuizSubmission $submission, array $answers): CampusQuizSubmission
    {
        $quiz = $submission->getQuiz();
        $questions = $quiz->getQuestions();
        $maxScore = 0.0;
        $earned = 0.0;

        foreach ($questions as $question) {
            $maxScore += $question->getPoints();
            $given = $this->extractAnswer($answers, $question);
            if ($given === null) continue; // unanswered → 0 points
            if ($this->isCorrect($question, $given)) {
                $earned += $question->getPoints();
            }
        }

        $submission->setAnswers($this->normaliseAnswers($answers));
        $submission->setMaxScore((string) round($maxScore, 2));
        $submission->setScore((string) round($earned, 2));
        $percent = $maxScore > 0 ? (int) round(($earned / $maxScore) * 100) : 0;
        $submission->setPercent($percent);
        $submission->setPassed($percent >= $quiz->getPassingScore());

        $this->em->flush();
        return $submission;
    }

    private function extractAnswer(array $answers, CampusQuizQuestion $q): mixed
    {
        if (!array_key_exists($q->getId(), $answers)) return null;
        $val = $answers[$q->getId()];
        return $val === null || $val === '' ? null : $val;
    }

    private function isCorrect(CampusQuizQuestion $q, mixed $given): bool
    {
        return match ($q->getKind()) {
            CampusQuizQuestion::KIND_MULTIPLE_CHOICE => (int) $given === (int) $q->getCorrect(),
            CampusQuizQuestion::KIND_TRUE_FALSE => (int) $given === (int) $q->getCorrect(),
            CampusQuizQuestion::KIND_SHORT_ANSWER => $this->normaliseText((string) $given) === $this->normaliseText($q->getCorrectString()),
            default => false,
        };
    }

    private function normaliseText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        // collapse whitespace
        return preg_replace('/\s+/', ' ', $s) ?? $s;
    }

    private function normaliseAnswers(array $raw): array
    {
        $out = [];
        foreach ($raw as $qid => $val) {
            $out[(int) $qid] = is_scalar($val) ? $val : (string) ($val['value'] ?? '');
        }
        return $out;
    }
}
