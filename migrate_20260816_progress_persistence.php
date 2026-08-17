<?php
/**
 * Migration 2026-08-16: Progress persistence across logins.
 *
 * Fixes the field bug where learners had to re-take the pre-test on their
 * second login. Two problems:
 *   1. pretest_attempts + behavioral_surveys have student_id populated on
 *      write, but the "did they do it?" reads in module_detail.php and
 *      pre_test.php key on session_hash — which changes every session.
 *   2. No UNIQUE constraint means a learner who does re-take piles up
 *      duplicate rows.
 *
 * This migration:
 *   - Backfills pretest_attempts.student_id and behavioral_surveys.student_id
 *     from the sessions table (and students.session_hash as fallback).
 *   - Deduplicates rows keyed on (student_id, module_id, test_type),
 *     keeping the LATEST attempt (matches current app semantics of
 *     ORDER BY id DESC LIMIT 1). Dropped rows are moved to _archive tables
 *     so nothing is lost.
 *   - Adds a partial UNIQUE index (only where student_id IS NOT NULL) so
 *     anonymous learners without accounts are unaffected.
 *   - Adds hot-path indexes on (student_id, module_id).
 *
 * Idempotent: safe to re-run — each step is guarded.
 */

require_once __DIR__ . '/includes/config.php';

$db = db();
$log = function (string $msg): void { echo '[' . date('H:i:s') . "] $msg\n"; };

$db->exec('BEGIN');

try {
    // ── 1. Archive tables ────────────────────────────────────────────────
    $log('Creating archive tables (if missing)…');
    $db->exec("CREATE TABLE IF NOT EXISTS pretest_attempts_archive AS
               SELECT * FROM pretest_attempts WHERE 0");
    $db->exec("CREATE TABLE IF NOT EXISTS behavioral_surveys_archive AS
               SELECT * FROM behavioral_surveys WHERE 0");

    // ── 2. Backfill student_id from sessions.session_hash ───────────────
    $log('Backfilling pretest_attempts.student_id from sessions…');
    $db->exec("UPDATE pretest_attempts
               SET student_id = (
                   SELECT s.student_id FROM sessions s
                   WHERE s.session_hash = pretest_attempts.session_hash
                     AND s.student_id IS NOT NULL
                   LIMIT 1
               )
               WHERE student_id IS NULL
                 AND EXISTS (
                     SELECT 1 FROM sessions s
                     WHERE s.session_hash = pretest_attempts.session_hash
                       AND s.student_id IS NOT NULL
                 )");

    $log('Backfilling behavioral_surveys.student_id from sessions…');
    // Note: existing code writes student_id=0 for anonymous rows; normalise
    // to NULL so the partial index doesn't collide.
    $db->exec("UPDATE behavioral_surveys SET student_id = NULL WHERE student_id = 0");
    $db->exec("UPDATE behavioral_surveys
               SET student_id = (
                   SELECT s.student_id FROM sessions s
                   WHERE s.session_hash = behavioral_surveys.session_hash
                     AND s.student_id IS NOT NULL
                   LIMIT 1
               )
               WHERE student_id IS NULL
                 AND EXISTS (
                     SELECT 1 FROM sessions s
                     WHERE s.session_hash = behavioral_surveys.session_hash
                       AND s.student_id IS NOT NULL
                 )");

    // ── 3. Fallback backfill: students.session_hash ─────────────────────
    // getStudentBySession() rewrites students.session_hash on every login,
    // so this catches recent-session survivors that never got a sessions row.
    $log('Fallback backfill from students.session_hash…');
    $db->exec("UPDATE pretest_attempts
               SET student_id = (
                   SELECT st.id FROM students st
                   WHERE st.session_hash = pretest_attempts.session_hash
                   LIMIT 1
               )
               WHERE student_id IS NULL
                 AND EXISTS (
                     SELECT 1 FROM students st
                     WHERE st.session_hash = pretest_attempts.session_hash
                 )");
    $db->exec("UPDATE behavioral_surveys
               SET student_id = (
                   SELECT st.id FROM students st
                   WHERE st.session_hash = behavioral_surveys.session_hash
                   LIMIT 1
               )
               WHERE student_id IS NULL
                 AND EXISTS (
                     SELECT 1 FROM students st
                     WHERE st.session_hash = behavioral_surveys.session_hash
                 )");

    // ── 4. Deduplicate — keep LATEST row per key, archive the rest ──────
    $log('Deduplicating pretest_attempts (keeping latest per student/module/type)…');
    $db->exec("INSERT INTO pretest_attempts_archive
               SELECT * FROM pretest_attempts
               WHERE student_id IS NOT NULL
                 AND id NOT IN (
                     SELECT MAX(id) FROM pretest_attempts
                     WHERE student_id IS NOT NULL
                     GROUP BY student_id, module_id, test_type
                 )");
    $db->exec("DELETE FROM pretest_attempts
               WHERE student_id IS NOT NULL
                 AND id NOT IN (
                     SELECT MAX(id) FROM pretest_attempts
                     WHERE student_id IS NOT NULL
                     GROUP BY student_id, module_id, test_type
                 )");

    $log('Deduplicating behavioral_surveys (keeping latest per student/module)…');
    $db->exec("INSERT INTO behavioral_surveys_archive
               SELECT * FROM behavioral_surveys
               WHERE student_id IS NOT NULL
                 AND id NOT IN (
                     SELECT MAX(id) FROM behavioral_surveys
                     WHERE student_id IS NOT NULL
                     GROUP BY student_id, module_id
                 )");
    $db->exec("DELETE FROM behavioral_surveys
               WHERE student_id IS NOT NULL
                 AND id NOT IN (
                     SELECT MAX(id) FROM behavioral_surveys
                     WHERE student_id IS NOT NULL
                     GROUP BY student_id, module_id
                 )");

    // ── 5. UNIQUE indexes (partial — only where student_id is known) ────
    $log('Adding UNIQUE indexes on (student_id, module_id, …)…');
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_pretest_attempts_student_module_type
               ON pretest_attempts (student_id, module_id, test_type)
               WHERE student_id IS NOT NULL");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_behavioral_surveys_student_module
               ON behavioral_surveys (student_id, module_id)
               WHERE student_id IS NOT NULL");

    // ── 6. Hot-path read indexes ────────────────────────────────────────
    $log('Adding hot-path indexes for dashboard reads…');
    $db->exec("CREATE INDEX IF NOT EXISTS ix_pretest_attempts_student_module
               ON pretest_attempts (student_id, module_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_quiz_attempts_student_module
               ON quiz_attempts (student_id, module_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_lesson_scores_student_slug
               ON lesson_scores (student_id, module_slug, lesson_slug)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_sessions_student
               ON sessions (student_id)");
    $db->exec("CREATE INDEX IF NOT EXISTS ix_students_session_hash
               ON students (session_hash)");

    $db->exec('COMMIT');
    $log('Migration complete.');

    // ── Summary ──────────────────────────────────────────────────────────
    $stats = [
        'pretest_attempts total'    => $db->querySingle("SELECT COUNT(*) FROM pretest_attempts"),
        'pretest_attempts w/ sid'   => $db->querySingle("SELECT COUNT(*) FROM pretest_attempts WHERE student_id IS NOT NULL"),
        'pretest archived'          => $db->querySingle("SELECT COUNT(*) FROM pretest_attempts_archive"),
        'behavioral_surveys total'  => $db->querySingle("SELECT COUNT(*) FROM behavioral_surveys"),
        'behavioral_surveys w/ sid' => $db->querySingle("SELECT COUNT(*) FROM behavioral_surveys WHERE student_id IS NOT NULL"),
        'surveys archived'          => $db->querySingle("SELECT COUNT(*) FROM behavioral_surveys_archive"),
    ];
    foreach ($stats as $k => $v) $log(sprintf('  %-28s %s', $k, $v));
} catch (\Throwable $e) {
    $db->exec('ROLLBACK');
    fwrite(STDERR, "MIGRATION FAILED: {$e->getMessage()}\n");
    exit(1);
}
