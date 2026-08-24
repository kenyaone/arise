<?php
/**
 * Migration 2026-08-23: lesson_progress.updated_at
 *
 * Adds a real updated_at column to lesson_progress. Two things depended on
 * it that were previously either broken or impossible:
 *   1. api_lesson.php's save_progress UPDATE branch referenced updated_at
 *      before this column existed — every resume-position save for a
 *      returning learner crashed with "no such column: updated_at"
 *      (surfaced via ARISE's graceful-degradation page instead of a raw
 *      fatal, but the resume position was never actually saved).
 *   2. The Continue feature (home.php / learner_dashboard.php) needs to
 *      find the learner's most-recently-touched, not-yet-completed lesson —
 *      there was no timestamp to order by.
 *
 * api_lesson.php now sets updated_at on every save_progress write, and
 * additionally sets lesson_progress.completed=1 when a lesson's quiz is
 * submitted (previously nothing in the app ever set completed=1 for real
 * activity, only old seed data had it).
 *
 * Idempotent: safe to re-run — checks the column exists before adding it,
 * and only backfills rows where updated_at is still NULL.
 */

require_once __DIR__ . '/includes/config.php';

$db = db();
$log = function (string $msg): void { echo '[' . date('H:i:s') . "] $msg\n"; };

$hasColumn = false;
$cols = $db->query("PRAGMA table_info(lesson_progress)");
while ($c = $cols->fetchArray(SQLITE3_ASSOC)) {
    if ($c['name'] === 'updated_at') { $hasColumn = true; break; }
}

if ($hasColumn) {
    $log('updated_at already exists on lesson_progress — nothing to do.');
} else {
    $log('Adding lesson_progress.updated_at…');
    $db->exec('ALTER TABLE lesson_progress ADD COLUMN updated_at DATETIME');
    $log('Backfilling from completed_at (or now, for still-in-progress rows)…');
    $db->exec("UPDATE lesson_progress SET updated_at = COALESCE(completed_at, CURRENT_TIMESTAMP) WHERE updated_at IS NULL");
    $log('Done.');
}

$total = $db->querySingle('SELECT COUNT(*) FROM lesson_progress');
$withTs = $db->querySingle('SELECT COUNT(*) FROM lesson_progress WHERE updated_at IS NOT NULL');
$log("lesson_progress rows: $total total, $withTs with updated_at");
