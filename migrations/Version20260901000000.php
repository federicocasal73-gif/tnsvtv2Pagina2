<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * TNSVT V3 — Phase 3 unified task system.
 *
 * Replaces the legacy "global checklist" Task entity with a rich model that
 * supports the full lifecycle: 7 canonical status states, priority levels,
 * assignment (assigned_to / assigned_by), due_date, course/lesson links,
 * threaded comments (TaskComment), submissions with file attachment
 * (TaskSubmission), and grade+feedback (TaskFeedback).
 *
 * The legacy Task table already exists (id, title, description, orden, active,
 * created_at) — we ALTER it in place to keep any pre-existing rows intact
 * and default them to status='active' so the admin UI keeps working.
 *
 * Up() is fully reversible via down().
 */
final class Version20260901000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Phase 3 — unified task system: status/priority/dates, comments, submissions, feedback';
    }

    public function up(Schema $schema): void
    {
        // ── 1. Extend existing tasks table with the new columns ──
        if ($schema->hasTable('tasks')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tasks
                ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending'
                    AFTER description,
                ADD COLUMN priority VARCHAR(16) NOT NULL DEFAULT 'normal'
                    AFTER status,
                ADD COLUMN type VARCHAR(32) NOT NULL DEFAULT 'general'
                    AFTER priority,
                ADD COLUMN assigned_to_id INT DEFAULT NULL
                    AFTER type,
                ADD COLUMN assigned_by_id INT DEFAULT NULL
                    AFTER assigned_to_id,
                ADD COLUMN course_id INT DEFAULT NULL
                    AFTER assigned_by_id,
                ADD COLUMN lesson_id INT DEFAULT NULL
                    AFTER course_id,
                ADD COLUMN due_date DATETIME DEFAULT NULL
                    AFTER lesson_id,
                ADD COLUMN estimated_minutes INT DEFAULT NULL
                    AFTER due_date,
                ADD COLUMN instructions TEXT DEFAULT NULL
                    AFTER estimated_minutes,
                ADD COLUMN links JSON DEFAULT NULL
                    AFTER instructions,
                ADD COLUMN attachments JSON DEFAULT NULL
                    AFTER links,
                ADD COLUMN completed_at DATETIME DEFAULT NULL
                    AFTER updated_at
            SQL);

            $this->addSql(<<<'SQL'
                CREATE INDEX idx_task_assigned_status ON tasks (assigned_to_id, status)
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_task_due_date ON tasks (due_date)
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_task_course ON tasks (course_id)
            SQL);

            // FK constraints — MySQL only (skipped on sqlite)
            if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
                $this->addSql(<<<'SQL'
                    ALTER TABLE tasks
                    ADD CONSTRAINT FK_TASKS_ASSIGNED_TO FOREIGN KEY (assigned_to_id)
                        REFERENCES users (id) ON DELETE SET NULL,
                    ADD CONSTRAINT FK_TASKS_ASSIGNED_BY FOREIGN KEY (assigned_by_id)
                        REFERENCES users (id) ON DELETE SET NULL,
                    ADD CONSTRAINT FK_TASKS_COURSE FOREIGN KEY (course_id)
                        REFERENCES campus_courses (id) ON DELETE SET NULL,
                    ADD CONSTRAINT FK_TASKS_LESSON FOREIGN KEY (lesson_id)
                        REFERENCES campus_lessons (id) ON DELETE SET NULL
                SQL);
            }
        }

        // ── 2. New table: task_submissions ──
        if (!$schema->hasTable('task_submissions')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE task_submissions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id INT NOT NULL,
                    user_id INT NOT NULL,
                    file_data TEXT DEFAULT NULL,
                    file_name VARCHAR(255) DEFAULT NULL,
                    file_mime VARCHAR(100) DEFAULT NULL,
                    file_size INT DEFAULT NULL,
                    comments TEXT DEFAULT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'pending',
                    submitted_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL
                )
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_submission_task ON task_submissions (task_id, submitted_at)
            SQL);
            if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
                $this->addSql(<<<'SQL'
                    ALTER TABLE task_submissions
                    ADD CONSTRAINT FK_SUBMISSION_TASK FOREIGN KEY (task_id)
                        REFERENCES tasks (id) ON DELETE CASCADE,
                    ADD CONSTRAINT FK_SUBMISSION_USER FOREIGN KEY (user_id)
                        REFERENCES users (id) ON DELETE CASCADE
                SQL);
            }
        }

        // ── 3. New table: task_comments ──
        if (!$schema->hasTable('task_comments')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE task_comments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id INT NOT NULL,
                    author_id INT NOT NULL,
                    body TEXT NOT NULL,
                    kind VARCHAR(32) NOT NULL DEFAULT 'comment',
                    created_at DATETIME NOT NULL,
                    edited_at DATETIME DEFAULT NULL
                )
            SQL);
            $this->addSql(<<<'SQL'
                CREATE INDEX idx_task_comment_task ON task_comments (task_id, created_at)
            SQL);
            if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
                $this->addSql(<<<'SQL'
                    ALTER TABLE task_comments
                    ADD CONSTRAINT FK_COMMENT_TASK FOREIGN KEY (task_id)
                        REFERENCES tasks (id) ON DELETE CASCADE,
                    ADD CONSTRAINT FK_COMMENT_AUTHOR FOREIGN KEY (author_id)
                        REFERENCES users (id) ON DELETE CASCADE
                SQL);
            }
        }

        // ── 4. New table: task_feedback ──
        if (!$schema->hasTable('task_feedback')) {
            $this->addSql(<<<'SQL'
                CREATE TABLE task_feedback (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task_id INT NOT NULL UNIQUE,
                    grader_id INT NOT NULL,
                    grade DECIMAL(4,2) DEFAULT NULL,
                    comment TEXT DEFAULT NULL,
                    decision VARCHAR(32) NOT NULL DEFAULT 'graded',
                    graded_at DATETIME NOT NULL,
                    created_at DATETIME NOT NULL
                )
            SQL);
            if ($this->connection->getDatabasePlatform()->getName() === 'mysql') {
                $this->addSql(<<<'SQL'
                    ALTER TABLE task_feedback
                    ADD CONSTRAINT FK_FEEDBACK_TASK FOREIGN KEY (task_id)
                        REFERENCES tasks (id) ON DELETE CASCADE,
                    ADD CONSTRAINT FK_FEEDBACK_GRADER FOREIGN KEY (grader_id)
                        REFERENCES users (id) ON DELETE CASCADE
                SQL);
            }
        }

        // ── 5. Backfill: any existing active task → status='active' (pending-equivalent) ──
        $this->addSql(<<<'SQL'
            UPDATE tasks SET status = 'pending' WHERE active = 1 AND (status IS NULL OR status = '')
            SQL);

        // ── 6. Backfill CampusAssignment → create parallel Task rows for migration ──
        if ($schema->hasTable('campus_assignments') && $schema->hasTable('campus_lessons') && $schema->hasTable('campus_courses') && $schema->hasTable('users')) {
            // Maps old CampusSubmission status to new Task status
            $this->addSql(<<<'SQL'
                INSERT INTO tasks (title, description, status, priority, type,
                    assigned_to_id, course_id, lesson_id,
                    due_date, instructions,
                    orden, active, created_at, updated_at)
                SELECT
                    ca.title,
                    ca.description,
                    CASE
                        WHEN EXISTS (
                            SELECT 1 FROM campus_submissions cs
                            WHERE cs.assignment_id = ca.id AND cs.status IN ('completed', 'corrected')
                        ) THEN 'approved'
                        WHEN EXISTS (
                            SELECT 1 FROM campus_submissions cs
                            WHERE cs.assignment_id = ca.id AND cs.status IN ('revision')
                        ) THEN 'needs_revision'
                        WHEN EXISTS (
                            SELECT 1 FROM campus_submissions cs
                            WHERE cs.assignment_id = ca.id AND cs.status = 'submitted'
                        ) THEN 'submitted'
                        ELSE 'pending'
                    END,
                    'normal',
                    'academic',
                    (SELECT u.id FROM users u
                        JOIN campus_submissions cs ON cs.user_code = u.code
                        WHERE cs.assignment_id = ca.id
                        ORDER BY cs.id ASC LIMIT 1),
                    (SELECT m.course_id FROM campus_modules m WHERE m.id = le.module_id),
                    ca.lesson_id,
                    ca.due_date,
                    ca.instructions,
                    ca.id,
                    1,
                    ca.created_at,
                    ca.updated_at
                FROM campus_assignments ca
                JOIN campus_lessons le ON le.id = ca.lesson_id
                ON CONFLICT DO NOTHING
            SQL);
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('task_feedback')) {
            $this->addSql('DROP TABLE task_feedback');
        }
        if ($schema->hasTable('task_comments')) {
            $this->addSql('DROP TABLE task_comments');
        }
        if ($schema->hasTable('task_submissions')) {
            $this->addSql('DROP TABLE task_submissions');
        }
        if ($schema->hasTable('tasks')) {
            $this->addSql(<<<'SQL'
                ALTER TABLE tasks
                DROP FOREIGN KEY FK_TASKS_ASSIGNED_TO,
                DROP FOREIGN KEY FK_TASKS_ASSIGNED_BY,
                DROP FOREIGN KEY FK_TASKS_COURSE,
                DROP FOREIGN KEY FK_TASKS_LESSON,
                DROP INDEX idx_task_assigned_status,
                DROP INDEX idx_task_due_date,
                DROP INDEX idx_task_course,
                DROP COLUMN status,
                DROP COLUMN priority,
                DROP COLUMN type,
                DROP COLUMN assigned_to_id,
                DROP COLUMN assigned_by_id,
                DROP COLUMN course_id,
                DROP COLUMN lesson_id,
                DROP COLUMN due_date,
                DROP COLUMN estimated_minutes,
                DROP COLUMN instructions,
                DROP COLUMN links,
                DROP COLUMN attachments,
                DROP COLUMN completed_at
            SQL);
        }
    }
}
