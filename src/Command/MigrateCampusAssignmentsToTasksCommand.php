<?php

namespace App\Command;

use App\Entity\CampusAssignment;
use App\Entity\CampusSubmission;
use App\Entity\Task;
use App\Entity\TaskFeedback;
use App\Entity\TaskSubmission;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * TNSVT Phase 3 — Migrate CampusAssignment data into the new Task system.
 *
 * The CampusAssignment / CampusSubmission / CampusFeedback entities remain
 * intact (legacy routes + the lesson-scoped assignment flow continue to
 * work). This command creates parallel Task rows for each existing
 * CampusAssignment so that the unified /api/tasks endpoint surfaces both
 * legacy academic tasks AND ad-hoc tasks in a single feed.
 *
 * Safe to run multiple times — existing Task rows (matched by legacy_id
 * stored in instructions link) are skipped.
 *
 * Usage:
 *   php bin/console app:migrate-campus-assignments-to-tasks
 *   php bin/console app:migrate-campus-assignments-to-tasks --dry-run
 */
#[AsCommand(
    name: 'app:migrate-campus-assignments-to-tasks',
    description: 'Backfill legacy CampusAssignment rows into the new Task system',
)]
class MigrateCampusAssignmentsToTasksCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Don\'t persist anything, just count');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');

        $conn = $this->em->getConnection();
        $rows = $conn->fetchAllAssociative(<<<'SQL'
            SELECT ca.id, ca.title, ca.description, ca.objective, ca.instructions,
                   ca.estimated_minutes, ca.due_date, ca.created_at, ca.updated_at,
                   le.id AS lesson_id, le.module_id, m.course_id,
                   (SELECT u.id FROM users u
                    JOIN campus_submissions cs ON cs.user_code = u.code
                    WHERE cs.assignment_id = ca.id
                    ORDER BY cs.id ASC LIMIT 1) AS student_user_id
            FROM campus_assignments ca
            JOIN campus_lessons le ON le.id = ca.lesson_id
            JOIN campus_modules m ON m.id = le.module_id
            ORDER BY ca.id ASC
        SQL);

        $io->writeln(sprintf('Found <info>%d</info> CampusAssignment rows to migrate.', count($rows)));

        if ($dryRun) {
            $io->writeln('<comment>DRY RUN — nothing will be persisted.</comment>');
            return Command::SUCCESS;
        }

        $created = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $caId = (int) $row['id'];

            // Skip if already migrated (check by legacy marker in instructions)
            $existing = $conn->fetchOne(
                'SELECT id FROM tasks WHERE title LIKE ? LIMIT 1',
                ['[CampusAssignment #' . $caId . '] %']
            );
            if ($existing) {
                $skipped++;
                continue;
            }

            // Determine status from latest submission
            $latestSub = $conn->fetchAssociative(
                'SELECT status FROM campus_submissions WHERE assignment_id = ? ORDER BY id DESC LIMIT 1',
                [$caId]
            );
            $status = match ($latestSub['status'] ?? null) {
                CampusSubmission::STATUS_COMPLETED, CampusSubmission::STATUS_CORRECTED => Task::STATUS_APPROVED,
                CampusSubmission::STATUS_REVISION => Task::STATUS_NEEDS_REVISION,
                CampusSubmission::STATUS_SUBMITTED, CampusSubmission::STATUS_PENDING => Task::STATUS_SUBMITTED,
                default => Task::STATUS_PENDING,
            };

            $task = new Task();
            $task->setTitle('[CampusAssignment #' . $caId . '] ' . ($row['title'] ?? ''));
            $task->setDescription($row['description'] ?? null);
            $task->setStatus($status);
            $task->setPriority(Task::PRIORITY_NORMAL);
            $task->setType(Task::TYPE_ACADEMIC);

            if ($row['student_user_id']) {
                $student = $this->userRepository->find((int) $row['student_user_id']);
                if ($student) {
                    $task->setAssignedTo($student);
                }
            }

            $task->setCourse($this->em->getReference(\App\Entity\CampusCourse::class, (int) $row['course_id']));
            $task->setLesson($this->em->getReference(\App\Entity\CampusLesson::class, (int) $row['lesson_id']));
            if ($row['due_date']) {
                $task->setDueDate(new \DateTimeImmutable($row['due_date']));
            }
            if ($row['estimated_minutes']) {
                $task->setEstimatedMinutes((int) $row['estimated_minutes']);
            }
            // Store the full instructions + objective in instructions field
            $combined = trim(($row['objective'] ?? '') . "\n\n" . ($row['instructions'] ?? ''));
            $task->setInstructions($combined !== '' ? $combined : null);

            $this->em->persist($task);
            $this->em->flush();

            $created++;
        }

        $io->success(sprintf('Created %d task rows; skipped %d already-migrated.', $created, $skipped));
        return Command::SUCCESS;
    }
}
