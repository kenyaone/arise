#!/usr/bin/env bash
# =============================================================================
# ARISE — daily updater. Called by /etc/cron.d/arise-update at 03:00.
#
# What it does:
#   1. Reads /etc/arise-update.conf.
#   2. Refuses to run if another instance is already running (flock).
#   3. Backs up the SQLite DB with the safe .backup command (NOT `cp`).
#   4. Fetches origin/<branch> and hard-resets the working tree.
#      Any local dirty changes are stashed to /var/lib/arise/dirty-<sha>.patch.
#   5. Runs any migrate_*.php files that are in the repo but NOT yet listed in
#      the applied-migrations state file. On success, records them.
#      On failure, rolls the DB and code back to the pre-update state.
#   6. Reloads Apache (or nginx) gracefully.
#   7. Optionally POSTs a heartbeat (git SHA + row counts + hostname).
#
# Usage:
#   arise-update            # normal run (called by cron)
#   arise-update --now      # same, but prints to terminal too
#   arise-update --status   # print current state and exit
#   arise-update --dry-run  # show what would happen, change nothing
# =============================================================================
set -euo pipefail

# Handle --help before anything else so it works pre-install too.
for arg in "$@"; do
    case "$arg" in
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    esac
done

CONF="/etc/arise-update.conf"
[[ -r "$CONF" ]] || { echo "ERROR: $CONF missing — did you run install-updater.sh?" >&2; exit 1; }
# shellcheck disable=SC1090
source "$CONF"

: "${ARISE_ROOT:?}"
: "${ARISE_BRANCH:?}"
: "${ARISE_DB:?}"
: "${ARISE_BACKUP_DIR:=/var/backups/arise}"
: "${ARISE_APPLIED_FILE:=/var/lib/arise/applied-migrations.txt}"
: "${ARISE_LOG:=/var/log/arise-update.log}"
: "${ARISE_HEARTBEAT_URL:=}"

LOCK="/var/lock/arise-update.lock"
NOW_ARG=0
DRY_RUN=0
STATUS_ONLY=0

for arg in "$@"; do
    case "$arg" in
        --now)     NOW_ARG=1 ;;
        --dry-run) DRY_RUN=1 ;;
        --status)  STATUS_ONLY=1 ;;
        -h|--help) sed -n '2,25p' "$0"; exit 0 ;;
    esac
done

# Log function: writes to log file always, and to stderr if --now
log() {
    local line
    printf -v line '[%(%F %T)T] %s' -1 "$*"
    echo "$line" >> "$ARISE_LOG"
    (( NOW_ARG )) && echo "$line" >&2
}
run() {
    if (( DRY_RUN )); then log "DRY: $*"; return 0; fi
    log "\$ $*"
    eval "$@"
}
fail() { log "FATAL: $*"; exit 1; }

# Defined here so it is callable from the "already at latest" fast-path below.
heartbeat_post() {
    [[ -n "$ARISE_HEARTBEAT_URL" ]] || return 0
    command -v curl >/dev/null || return 0
    local sha rows_students rows_pretest rows_certs disk hostname payload
    sha="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
    rows_students=$(sqlite3 "$ARISE_DB" "SELECT COUNT(*) FROM students" 2>/dev/null || echo 0)
    rows_pretest=$(sqlite3 "$ARISE_DB" "SELECT COUNT(*) FROM pretest_attempts" 2>/dev/null || echo 0)
    rows_certs=$(sqlite3 "$ARISE_DB" "SELECT COUNT(*) FROM certificates" 2>/dev/null || echo 0)
    disk=$(df -h "$ARISE_ROOT" | awk 'NR==2 {print $5}')
    hostname="$(hostname)"
    payload=$(printf '{"host":"%s","sha":"%s","branch":"%s","students":%s,"pretest_attempts":%s,"certificates":%s,"disk_used":"%s","at":"%s"}' \
        "$hostname" "$sha" "$ARISE_BRANCH" "$rows_students" "$rows_pretest" "$rows_certs" "$disk" "$(date -Iseconds)")
    if curl -sS -m 10 -X POST -H 'Content-Type: application/json' -d "$payload" "$ARISE_HEARTBEAT_URL" >/dev/null; then
        log "heartbeat OK"
    else
        log "heartbeat FAILED (non-fatal)"
    fi
}

# ── Status mode: just print and exit ────────────────────────────────────────
if (( STATUS_ONLY )); then
    cd "$ARISE_ROOT" 2>/dev/null || fail "ARISE_ROOT missing: $ARISE_ROOT"
    echo "arise root:      $ARISE_ROOT"
    echo "branch:          $ARISE_BRANCH"
    echo "current SHA:     $(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
    echo "applied count:   $(wc -l < "$ARISE_APPLIED_FILE" 2>/dev/null || echo 0)"
    echo "last update:     $(stat -c %y "$ARISE_APPLIED_FILE" 2>/dev/null || echo never)"
    echo "recent log:"
    tail -n 10 "$ARISE_LOG" 2>/dev/null | sed 's/^/  /' || echo "  (empty)"
    exit 0
fi

# ── Serialize runs ──────────────────────────────────────────────────────────
exec 9>"$LOCK"
if ! flock -n 9; then
    log "another arise-update is already running — exiting"
    exit 0
fi

log "=== arise-update start ==="
cd "$ARISE_ROOT" || fail "cannot cd to ARISE_ROOT: $ARISE_ROOT"

# ── Health preconditions ────────────────────────────────────────────────────
command -v git     >/dev/null || fail "git not installed"
command -v sqlite3 >/dev/null || fail "sqlite3 not installed"
command -v php     >/dev/null || fail "php not installed"
[[ -f "$ARISE_DB" ]] || fail "DB missing: $ARISE_DB"

# ── Save current SHA for rollback ───────────────────────────────────────────
PRE_SHA="$(git rev-parse HEAD 2>/dev/null)"
log "pre-update SHA: $PRE_SHA"

# ── DB backup ───────────────────────────────────────────────────────────────
mkdir -p "$ARISE_BACKUP_DIR"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="$ARISE_BACKUP_DIR/arise-$STAMP.db"
if (( DRY_RUN )); then
    log "DRY: would sqlite3 backup → $BACKUP"
else
    log "backing up DB → $BACKUP"
    sqlite3 "$ARISE_DB" ".backup '$BACKUP'" || fail "sqlite backup failed"
    # Keep last 14 backups
    ls -1t "$ARISE_BACKUP_DIR"/arise-*.db 2>/dev/null | tail -n +15 | xargs -r rm -f
fi

# ── Stash any local edits ───────────────────────────────────────────────────
if [[ -n "$(git status --porcelain)" ]]; then
    PATCH="/var/lib/arise/dirty-$PRE_SHA-$STAMP.patch"
    log "working tree dirty — saving diff → $PATCH"
    run "git diff HEAD > '$PATCH'"
    run "git reset --hard HEAD"
fi

# ── Fetch + reset to origin/<branch> ────────────────────────────────────────
run "git fetch --prune origin"
NEW_SHA="$(git rev-parse "origin/$ARISE_BRANCH")"
if [[ "$NEW_SHA" == "$PRE_SHA" ]]; then
    log "already at latest ($NEW_SHA) — nothing to do"
    heartbeat_post
    log "=== arise-update done (no change) ==="
    exit 0
fi

log "updating: $PRE_SHA → $NEW_SHA"
run "git reset --hard 'origin/$ARISE_BRANCH'"

# ── Apply new migrations ────────────────────────────────────────────────────
touch "$ARISE_APPLIED_FILE"
MIGRATIONS_TO_APPLY=()
while IFS= read -r f; do
    base="$(basename "$f")"
    if ! grep -Fxq "$base" "$ARISE_APPLIED_FILE"; then
        MIGRATIONS_TO_APPLY+=("$base")
    fi
done < <(ls migrate_*.php 2>/dev/null | sort)

if (( ${#MIGRATIONS_TO_APPLY[@]} == 0 )); then
    log "no new migrations to apply"
else
    log "applying ${#MIGRATIONS_TO_APPLY[@]} migration(s): ${MIGRATIONS_TO_APPLY[*]}"
    for m in "${MIGRATIONS_TO_APPLY[@]}"; do
        log "  → $m"
        if (( DRY_RUN )); then
            log "    DRY: skipping php $m"
            continue
        fi
        if ! php "$m" >>"$ARISE_LOG" 2>&1; then
            log "MIGRATION FAILED: $m — rolling back"
            log "  restoring DB from $BACKUP"
            cp "$BACKUP" "$ARISE_DB"
            log "  resetting code to $PRE_SHA"
            git reset --hard "$PRE_SHA"
            fail "aborted due to migration failure"
        fi
        echo "$m" >> "$ARISE_APPLIED_FILE"
    done
fi

# ── Reload web server ───────────────────────────────────────────────────────
if systemctl list-units --type=service --state=running | grep -q apache2; then
    run "systemctl reload apache2 || systemctl restart apache2"
elif systemctl list-units --type=service --state=running | grep -q nginx; then
    run "systemctl reload nginx"
else
    log "no known web server running — skipping reload"
fi

heartbeat_post

log "=== arise-update done: now at $(git rev-parse --short HEAD) ==="
