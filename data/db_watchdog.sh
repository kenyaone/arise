#!/bin/bash
# ARISE database corruption watchdog.
#
# Runs on cron (every 5 minutes). Checks the live SQLite database's
# integrity; if it has gone bad, salvages it with `.recover` (the same
# procedure used for the manual recoveries on 2026-08-09 and 2026-08-21/22)
# and swaps the recovered copy in automatically, so a corruption event
# causes a few minutes of downtime instead of an outage that sits there
# until a human notices.
#
# Every action is logged to db-watchdog.log next to the database, with a
# clear timestamp, so what happened is auditable even though no one had
# to intervene in the moment.
#
# This does not address WHY corruption happens -- it only guarantees that
# IF it happens, it is invisible.
set -u

DATA_DIR="$(cd "$(dirname "$0")" && pwd)"
DB="$DATA_DIR/arise.db"
LOG="$DATA_DIR/db-watchdog.log"
LOCK="$DATA_DIR/.db-watchdog.lock"

exec 200>"$LOCK"
flock -n 200 || exit 0   # a previous run is still going -- don't overlap

log() { echo "[$(date -u '+%Y-%m-%d %H:%M:%S') UTC] $1" >> "$LOG"; }

[ -f "$DB" ] || { log "SKIP: $DB does not exist"; exit 0; }

touch "$DATA_DIR/.db-watchdog-heartbeat"

CHECK=$(sqlite3 "$DB" "PRAGMA integrity_check;" 2>&1)
if [ "$CHECK" = "ok" ]; then
    exit 0   # healthy -- say nothing, this runs every 5 minutes
fi

log "CORRUPTION DETECTED — beginning automatic recovery (integrity_check: $(echo "$CHECK" | head -1))"

TS=$(date -u +%Y%m%d_%H%M%S)
MALFORMED="$DATA_DIR/arise.db.MALFORMED-$TS"
SQL_DUMP="$DATA_DIR/arise_recovered_$TS.sql"
NEW_DB="$DATA_DIR/arise_recovered_$TS.db"

cp "$DB" "$MALFORMED"
log "preserved corrupted file as $MALFORMED"

sqlite3 "$DB" ".recover" > "$SQL_DUMP" 2>"$DATA_DIR/.recover_stderr_$TS.log"
if [ ! -s "$SQL_DUMP" ]; then
    log "RECOVERY FAILED at .recover step — leaving corrupted db in place, manual intervention needed"
    exit 1
fi

rm -f "$NEW_DB"
sqlite3 "$NEW_DB" < "$SQL_DUMP" 2>>"$DATA_DIR/.recover_stderr_$TS.log"
if [ ! -s "$NEW_DB" ]; then
    log "RECOVERY FAILED building new db from dump — leaving corrupted db in place, manual intervention needed"
    exit 1
fi

CHECK2=$(sqlite3 "$NEW_DB" "PRAGMA integrity_check;" 2>&1)
if [ "$CHECK2" != "ok" ]; then
    log "RECOVERY FAILED — recovered file itself is not clean ($CHECK2) — leaving corrupted db in place, manual intervention needed"
    exit 1
fi

# Swap in, preserving original ownership/permissions.
PERMS=$(stat -c '%a' "$DB")
OWNER=$(stat -c '%u:%g' "$DB")
chmod "$PERMS" "$NEW_DB"
chown "$OWNER" "$NEW_DB" 2>/dev/null

mv "$DB" "$DATA_DIR/arise.db.pre-recover-$TS"
mv "$NEW_DB" "$DB"
rm -f "$DATA_DIR/arise.db-wal" "$DATA_DIR/arise.db-shm"

log "RECOVERY SUCCEEDED — live db replaced with recovered copy, site should be back up"

# Retention: don't let watchdog artifacts accumulate forever.
find "$DATA_DIR" -maxdepth 1 -name 'arise.db.MALFORMED-*' -mtime +30 -delete
find "$DATA_DIR" -maxdepth 1 -name 'arise.db.pre-recover-*' -mtime +30 -delete
find "$DATA_DIR" -maxdepth 1 -name 'arise_recovered_*.sql' -mtime +30 -delete
find "$DATA_DIR" -maxdepth 1 -name '.recover_stderr_*.log' -mtime +7 -delete
