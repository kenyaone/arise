#!/bin/bash
# ARISE — fresh-start for cloned/imaged machines.
# Wipes all per-student, per-session, and per-device data while keeping
# seed content (modules, lessons, schools, clusters, admin users).
#
# Companion to:  first-boot-fix.sh
# Run AFTER first-boot-fix.sh has set the new device_id. The next cron
# sync (within ~60s) tells the cloud "this clone has zero students" so
# stale rows tagged with this clone's device_id are cleared on the cloud.
#
# Usage:
#   sudo bash clone-fresh-start.sh           # confirms first
#   sudo bash clone-fresh-start.sh --yes     # non-interactive (e.g. from a script)

set -e

DB=/var/www/arise/data/arise.db
ASSUME_YES=0
[ "$1" = "--yes" ] || [ "$1" = "-y" ] && ASSUME_YES=1

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: run as root: sudo bash $0"
    exit 1
fi

if [ ! -f "$DB" ]; then
    echo "ERROR: DB not found at $DB"
    exit 1
fi

echo "═══════════════════════════════════════════════════════"
echo "  ARISE Clone Fresh-Start"
echo "═══════════════════════════════════════════════════════"
echo
echo "  This will permanently DELETE on this machine:"
echo "    • All learner records, sessions, and badges"
echo "    • All quiz attempts, answers, and retry logs"
echo "    • All pre/post tests and essay responses"
echo "    • All certificates, page views, behavioral surveys"
echo "    • All forum posts, anonymous questions, daily stats"
echo "    • All datapost delivery/pickup/sync history"
echo "    • All audit logs and facilitator session records"
echo
echo "  KEPT (seed content + admin):"
echo "    • Modules, lessons, quiz questions"
echo "    • Schools, classes, clusters"
echo "    • Admin users + permissions"
echo "    • Badge definitions, datapost_config"
echo

if [ "$ASSUME_YES" -ne 1 ]; then
    read -p "  Type 'WIPE' to confirm: " confirm
    if [ "$confirm" != "WIPE" ]; then
        echo "  Aborted."
        exit 0
    fi
fi

echo
echo "[1/3] Backing up current DB ..."
BACKUP="$DB.preclone-$(date +%Y%m%d-%H%M%S)"
cp -a "$DB" "$BACKUP"
echo "    → $BACKUP"

echo "[2/3] Wiping per-student / per-session tables ..."
php <<'PHP'
<?php
$db = new SQLite3('/var/www/arise/data/arise.db');
$db->busyTimeout(5000);

$tables = [
    'students','student_badges','student_xp','xp_log',
    'quiz_attempts','quiz_answers','quiz_retry_log',
    'certificates','pretest_attempts','essay_responses',
    'anonymous_questions','page_views','behavioral_surveys',
    'challenge_responses','forum_posts','forum_upvotes',
    'lesson_interactions','lesson_progress','lesson_scores',
    'module_feedback','retention_tests','sessions','daily_stats',
    'datapost_deliveries','datapost_pickups','datapost_sync_log',
    'facilitator_sessions','arise_audit_log','backup_log',
];

// Discover which of these actually exist on this DB.
$existing = [];
$r = $db->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($row = $r->fetchArray(SQLITE3_ASSOC)) $existing[$row['name']] = true;

$db->exec('BEGIN');
$totalDeleted = 0;
foreach ($tables as $t) {
    if (!isset($existing[$t])) {
        echo "    (skip) $t — not present\n";
        continue;
    }
    $before = (int)$db->querySingle("SELECT COUNT(*) FROM $t");
    $db->exec("DELETE FROM $t");
    $totalDeleted += $before;
    printf("    %-25s %6d rows deleted\n", $t, $before);
}
// Reset AUTOINCREMENT counters so new rows start at 1.
$db->exec("DELETE FROM sqlite_sequence WHERE name IN ('".implode("','", $tables)."')");
$db->exec('COMMIT');
echo "    Total: $totalDeleted rows removed\n";

echo "[3/3] VACUUM to reclaim space ...\n";
$db->exec('VACUUM');
PHP

# Restore ownership (php CLI created lock files as root)
chown www-data:www-data "$DB"
[ -f "$DB-wal" ] && chown www-data:www-data "$DB-wal"
[ -f "$DB-shm" ] && chown www-data:www-data "$DB-shm"

echo
echo "═══════════════════════════════════════════════════════"
echo "  Done. This machine now has a fresh learner DB."
echo "  Backup saved: $BACKUP"
echo
echo "  The next cloud sync (cron, within ~60s) will tell"
echo "  ariseci.org to remove this device's stale rows."
echo "  To trigger it immediately:"
echo "    sudo -u arise php /home/arise/cloud_push.php"
echo "═══════════════════════════════════════════════════════"
