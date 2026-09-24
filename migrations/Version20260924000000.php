<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates all 12 campus_* tables referenced by the Campus* entities.
 *
 * The entities and repositories already exist but no migration ever
 * created these tables, so production (MySQL via git pull, no schema:create)
 * throws 42S02 when StreakService / HeatmapService / AchievementService
 * query `campus_lesson_progress` and `campus_quiz_submissions`, while
 * tests on sqlite pass via `doctrine:schema:create`.
 *
 * This is the same class of bug that was fixed for `notifications` in
 * Version20260915000000 — see that migration's docblock for context.
 *
 * Tables created (in FK-dependency order):
 *   1.  campus_courses
 *   2.  campus_modules           (FK → campus_courses)
 *   3.  campus_lessons           (FK → campus_modules)
 *   4.  campus_materials         (FK → campus_lessons)
 *   5.  campus_assignments       (FK → campus_lessons)
 *   6.  campus_enrollments       (FK → campus_courses)
 *   7.  campus_lesson_progress   (FK → campus_lessons)  ← breaks /api/me/streak & /api/me/heatmap
 *   8.  campus_quizzes           (FK → campus_lessons, OneToOne)
 *   9.  campus_quiz_questions    (FK → campus_quizzes)
 *   10. campus_quiz_submissions  (FK → campus_quizzes) ← breaks AchievementService
 *   11. campus_submissions       (FK → campus_assignments)
 *   12. campus_feedbacks         (FK → campus_submissions, OneToOne unique)
 *
 * Idempotent: if any of the 12 tables already exist, the migration skips
 * (the local sqlite DB already has them via `doctrine:schema:create`).
 */
final class Version20260924000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create campus_courses/modules/lessons/materials/assignments/enrollments/progress/quizzes/questions/submissions/submissions/feedbacks (resolves /api/me/streak, /api/me/heatmap 500s)';
    }

    public function up(Schema $schema): void
    {
        // Idempotent: if any of the campus_* tables already exist, skip entirely.
        // (Local sqlite dev has them via doctrine:schema:create; prod MySQL has none.)
        if ($schema->hasTable('campus_courses')) {
            return;
        }

        $isMysql = str_contains($this->connection->getDatabasePlatform()::class, 'MySQL');

        // 1. campus_courses ─────────────────────────────────────────────
        $t = $schema->createTable('campus_courses');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('description', 'text', ['notnull' => false]);
        $t->addColumn('emoji', 'string', ['length' => 10, 'notnull' => false]);
        $t->addColumn('thumbnail', 'string', ['length' => 500, 'notnull' => false]);
        $t->addColumn('orden', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('is_active', 'boolean', ['default' => true, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);

        // 2. campus_modules (FK → campus_courses) ───────────────────────
        $t = $schema->createTable('campus_modules');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('course_id', 'integer', ['notnull' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('description', 'text', ['notnull' => false]);
        $t->addColumn('orden', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['course_id'], 'idx_campusmod_course');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_courses', ['course_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusmod_course');
        } else {
            $t->addForeignKeyConstraint('campus_courses', ['course_id'], ['id'], [], 'fk_campusmod_course');
        }

        // 3. campus_lessons (FK → campus_modules) ────────────────────────
        $t = $schema->createTable('campus_lessons');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('module_id', 'integer', ['notnull' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('description', 'text', ['notnull' => false]);
        $t->addColumn('video_url', 'string', ['length' => 500, 'notnull' => false]);
        $t->addColumn('orden', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['module_id'], 'idx_campusles_module');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_modules', ['module_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusles_module');
        } else {
            $t->addForeignKeyConstraint('campus_modules', ['module_id'], ['id'], [], 'fk_campusles_module');
        }

        // 4. campus_materials (FK → campus_lessons) ──────────────────────
        $t = $schema->createTable('campus_materials');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('lesson_id', 'integer', ['notnull' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('type', 'string', ['length' => 20, 'notnull' => true]);
        $t->addColumn('url', 'string', ['length' => 500, 'notnull' => true]);
        $t->addColumn('orden', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['lesson_id'], 'idx_campusmat_lesson');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusmat_lesson');
        } else {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], [], 'fk_campusmat_lesson');
        }

        // 5. campus_assignments (FK → campus_lessons) ────────────────────
        $t = $schema->createTable('campus_assignments');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('lesson_id', 'integer', ['notnull' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('description', 'text', ['notnull' => false]);
        $t->addColumn('objective', 'text', ['notnull' => false]);
        $t->addColumn('instructions', 'text', ['notnull' => false]);
        $t->addColumn('estimated_minutes', 'integer', ['notnull' => false]);
        $t->addColumn('due_date', 'datetime_immutable', ['notnull' => false]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['lesson_id'], 'idx_campassign_lesson');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campassign_lesson');
        } else {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], [], 'fk_campassign_lesson');
        }

        // 6. campus_enrollments (FK → campus_courses) ───────────────────
        $t = $schema->createTable('campus_enrollments');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('course_id', 'integer', ['notnull' => true]);
        $t->addColumn('user_code', 'string', ['length' => 50, 'notnull' => true]);
        $t->addColumn('enrolled_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['course_id'], 'idx_campenroll_course');
        $t->addIndex(['user_code'], 'idx_campenroll_user');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_courses', ['course_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campenroll_course');
        } else {
            $t->addForeignKeyConstraint('campus_courses', ['course_id'], ['id'], [], 'fk_campenroll_course');
        }

        // 7. campus_lesson_progress (FK → campus_lessons) — critical for streak/heatmap
        $t = $schema->createTable('campus_lesson_progress');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('lesson_id', 'integer', ['notnull' => true]);
        $t->addColumn('user_code', 'string', ['length' => 50, 'notnull' => true]);
        $t->addColumn('completed', 'boolean', ['default' => false, 'notnull' => true]);
        $t->addColumn('completed_at', 'datetime_immutable', ['notnull' => false]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['lesson_id'], 'idx_campuslp_lesson');
        $t->addIndex(['user_code'], 'idx_campuslp_user');
        // Hot index for streak/heatmap queries: WHERE user_code=? AND completed=1 ORDER BY completed_at
        $t->addIndex(['user_code', 'completed'], 'idx_campuslp_user_done');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campuslp_lesson');
        } else {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], [], 'fk_campuslp_lesson');
        }

        // 8. campus_quizzes (FK → campus_lessons, OneToOne)
        $t = $schema->createTable('campus_quizzes');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('lesson_id', 'integer', ['notnull' => true]);
        $t->addColumn('title', 'string', ['length' => 255, 'notnull' => true]);
        $t->addColumn('description', 'text', ['notnull' => false]);
        $t->addColumn('passing_score', 'integer', ['default' => 70, 'notnull' => true]);
        $t->addColumn('time_limit_minutes', 'integer', ['notnull' => false]);
        $t->addColumn('max_attempts', 'integer', ['default' => 1, 'notnull' => true]);
        $t->addColumn('shuffle_questions', 'boolean', ['default' => false, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['lesson_id'], 'uniq_campusq_lesson');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusq_lesson');
        } else {
            $t->addForeignKeyConstraint('campus_lessons', ['lesson_id'], ['id'], [], 'fk_campusq_lesson');
        }

        // 9. campus_quiz_questions (FK → campus_quizzes)
        $t = $schema->createTable('campus_quiz_questions');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('quiz_id', 'integer', ['notnull' => true]);
        $t->addColumn('kind', 'string', ['length' => 32, 'default' => 'multiple_choice', 'notnull' => true]);
        $t->addColumn('prompt', 'string', ['length' => 500, 'notnull' => true]);
        $t->addColumn('options', 'json', ['notnull' => false]);
        $t->addColumn('correct', 'json', ['notnull' => false]);
        $t->addColumn('explanation', 'string', ['length' => 500, 'notnull' => false]);
        $t->addColumn('points', 'integer', ['default' => 1, 'notnull' => true]);
        $t->addColumn('orden', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['quiz_id'], 'idx_campusqq_quiz');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_quizzes', ['quiz_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusqq_quiz');
        } else {
            $t->addForeignKeyConstraint('campus_quizzes', ['quiz_id'], ['id'], [], 'fk_campusqq_quiz');
        }

        // 10. campus_quiz_submissions (FK → campus_quizzes) — critical for achievements
        $t = $schema->createTable('campus_quiz_submissions');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('quiz_id', 'integer', ['notnull' => true]);
        $t->addColumn('user_code', 'string', ['length' => 50, 'notnull' => true]);
        $t->addColumn('answers', 'json', ['notnull' => false]);
        $t->addColumn('score', 'decimal', ['precision' => 5, 'scale' => 2, 'notnull' => false]);
        $t->addColumn('max_score', 'decimal', ['precision' => 5, 'scale' => 2, 'notnull' => false]);
        $t->addColumn('percent', 'integer', ['notnull' => false]);
        $t->addColumn('passed', 'boolean', ['default' => false, 'notnull' => true]);
        $t->addColumn('duration_seconds', 'integer', ['default' => 0, 'notnull' => true]);
        $t->addColumn('submitted_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['quiz_id'], 'idx_campusqs_quiz');
        $t->addIndex(['user_code'], 'idx_campusqs_user');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_quizzes', ['quiz_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campusqs_quiz');
        } else {
            $t->addForeignKeyConstraint('campus_quizzes', ['quiz_id'], ['id'], [], 'fk_campusqs_quiz');
        }

        // 11. campus_submissions (FK → campus_assignments)
        $t = $schema->createTable('campus_submissions');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('assignment_id', 'integer', ['notnull' => true]);
        $t->addColumn('user_code', 'string', ['length' => 50, 'notnull' => true]);
        $t->addColumn('files', 'json', ['notnull' => false]);
        $t->addColumn('comments', 'text', ['notnull' => false]);
        $t->addColumn('status', 'string', ['length' => 20, 'default' => 'pending', 'notnull' => true]);
        $t->addColumn('submitted_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('updated_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addIndex(['assignment_id'], 'idx_campsub_assignment');
        $t->addIndex(['user_code'], 'idx_campsub_user');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_assignments', ['assignment_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campsub_assignment');
        } else {
            $t->addForeignKeyConstraint('campus_assignments', ['assignment_id'], ['id'], [], 'fk_campsub_assignment');
        }

        // 12. campus_feedbacks (FK → campus_submissions, OneToOne unique)
        $t = $schema->createTable('campus_feedbacks');
        $t->addColumn('id', 'integer', ['autoincrement' => true]);
        $t->addColumn('submission_id', 'integer', ['notnull' => true]);
        $t->addColumn('grade', 'decimal', ['precision' => 3, 'scale' => 1, 'notnull' => false]);
        $t->addColumn('comment', 'text', ['notnull' => false]);
        $t->addColumn('graded_at', 'datetime_immutable', ['notnull' => true]);
        $t->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $t->setPrimaryKey(['id']);
        $t->addUniqueIndex(['submission_id'], 'uniq_campfb_submission');
        if ($isMysql) {
            $t->addForeignKeyConstraint('campus_submissions', ['submission_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_campfb_submission');
        } else {
            $t->addForeignKeyConstraint('campus_submissions', ['submission_id'], ['id'], [], 'fk_campfb_submission');
        }
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse FK-dependency order.
        foreach ([
            'campus_feedbacks',
            'campus_submissions',
            'campus_quiz_submissions',
            'campus_quiz_questions',
            'campus_quizzes',
            'campus_lesson_progress',
            'campus_enrollments',
            'campus_assignments',
            'campus_materials',
            'campus_lessons',
            'campus_modules',
            'campus_courses',
        ] as $t) {
            if ($schema->hasTable($t)) {
                $schema->dropTable($t);
            }
        }
    }
}
