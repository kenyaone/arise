#!/bin/bash
# ARISE pre-clone sanitizer
#
# Run this ON THE MASTER BOX before imaging the disk for clone deployments.
# Wipes anything that's specific to this box and would be wrong on every clone:
#
#   - learner records (students, quiz_attempts, pretest_attempts, certificates,
#                       lesson_progress, lesson_scores, sessions, student_xp,
#                       student_badges, xp_log, essay_responses, forum_posts,
#                       anonymous_questions, datapost_pickups, datapost_deliveries)
#   - cron sync logs
#   - daily_stats counters
#   - facilitator_sessions (if present)
#
# Leaves alone:
#   - schools, clusters, modules, lessons, quiz_questions (your content + topology)
#   - admin_users (admin login persists into the clone)
#   - your code under /var/www/arise/  (apply updates via the admin UI instead)
#
# Usage:
#   sudo bash pre-clone-sanitizer.sh --dry-run     # show what would happen
#   sudo bash pre-clone-sanitizer.sh               # actually wipe (with confirm)
#   sudo bash pre-clone-sanitizer.sh --yes         # actually wipe (no confirm)

set -euo pipefail

DB=/var/www/arise/data/arise.db
SYNC_LOG=/home/arise/cloud_sync.log
DRY=0
YES=0

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY=1 ;;
        --yes)     YES=1 ;;
        -h|--help)
            sed -n '2,25p' "$0"
            exit 0 ;;
        *) echo "Unknown flag: $arg"; exit 2 ;;
    esac
done

if [ ! -f "$DB" ]; then
    echo "ERROR: $DB not found"; exit 1
fi

# Tables to wipe — must exist in the DB to be touched; missing tables are skipped.
TABLES=(
    students quiz_attempts pretest_attempts certificates lesson_progress
    lesson_scores sessions student_xp student_badges xp_log
    essay_responses forum_posts anonymous_questions
    datapost_pickups datapost_deliveries facilitator_sessions
    daily_stats
)

table_exists() {
    sqlite3 "$DB" "SELECT 1 FROM sqlite_master WHERE type='table' AND name='$1'" 2>/dev/null | grep -q 1
}
row_count() {
    sqlite3 "$DB" "SELECT COUNT(*) FROM \"$1\"" 2>/dev/null || echo 0
}

echo "═══════════════════════════════════════════════════"
echo "  ARISE Pre-Clone Sanitizer"
echo "  DB:  $DB"
echo "  Log: $SYNC_LOG"
[ "$DRY" = "1" ] && echo "  MODE: DRY RUN (no changes will be made)"
echo "═══════════════════════════════════════════════════"
echo ""
echo "Current row counts (will be reset to 0):"
TOTAL=0
for t in "${TABLES[@]}"; do
    if table_exists "$t"; then
        n=$(row_count "$t")
        printf "  %-25s %s\n" "$t" "$n"
        TOTAL=$((TOTAL + n))
    fi
done
echo "  ─────────────────────────────"
printf "  %-25s %s\n" "TOTAL ROWS TO WIPE" "$TOTAL"
echo ""

if [ -f "$SYNC_LOG" ]; then
    LOG_LINES=$(wc -l < "$SYNC_LOG" 2>/dev/null || echo 0)
    LOG_SIZE=$(du -h "$SYNC_LOG" 2>/dev/null | cut -f1)
    echo "Cloud sync log: $LOG_LINES lines ($LOG_SIZE) → will be truncated"
else
    echo "Cloud sync log: not present, nothing to do"
fi

echo ""
echo "Identity files that first-boot-fix.sh will regenerate on clones:"
echo "  /etc/arise_device_id         → $([ -f /etc/arise_device_id ] && cat /etc/arise_device_id || echo '(not set)')"
echo "  /etc/arise_cluster_master    → $([ -e /etc/arise_cluster_master ] && echo present || echo absent)"
echo "  /etc/arise_firstboot_done    → $([ -e /etc/arise_firstboot_done ] && echo PRESENT \(will be removed so clones re-bootstrap\) || echo absent)"
echo ""

if [ "$DRY" = "1" ]; then
    echo "Dry run only — re-run without --dry-run (or with --yes) to actually wipe."
    exit 0
fi

if [ "$YES" != "1" ]; then
    echo -n "Proceed and wipe the data above? [y/N] "
    read -r ans
    case "$ans" in y|Y|yes|YES) ;; *) echo "Aborted."; exit 0 ;; esac
fi

echo ""
echo "==> Wiping learner / operational tables"
SQL="BEGIN;"
for t in "${TABLES[@]}"; do
    if table_exists "$t"; then SQL+="DELETE FROM \"$t\";"; fi
done
SQL+="COMMIT;"
sqlite3 "$DB" "$SQL"

if [ -f "$SYNC_LOG" ]; then
    echo "==> Truncating $SYNC_LOG"
    : > "$SYNC_LOG"
fi

echo "==> Removing first-boot sentinel so clones re-run arise-firstboot.service"
rm -f /etc/arise_firstboot_done

# Refresh ownership so www-data can keep writing after the clone boots.
chown www-data:www-data "$DB" 2>/dev/null || true
rm -f "${DB}-wal" "${DB}-shm" 2>/dev/null || true

echo ""
echo "✅ Done. Next steps to make the master image:"
echo "  1. Power off the box."
echo "  2. Take a disk image (Clonezilla, dd, etc.)."
echo "  3. Restore the image on each field box."
echo "  4. On first boot of every clone, arise-firstboot.service runs first-boot-fix.sh"
echo "     which strips the SSH push key, regenerates device_id + machine-id + hostname,"
echo "     and runs clone-fresh-start.sh."
