#!/usr/bin/env bash
# =============================================================================
# ARISE — one-command USB box onboarding.
#
# For couriers / school champions doing a physical USB visit to a box that
# has NO auto-updater installed and NO internet on the box.
#
# On the USB stick, this script sits next to a `payload/` folder that
# contains the offline field-boxes bundle:
#
#   /media/usb-mount/
#   ├── box-onboarding.sh        ← this file
#   ├── checklist.md             ← printed courier instructions
#   ├── payload/
#   │   ├── arise-field-boxes.tar.gz     (built by deploy/build-usb.sh)
#   │   └── SHA                          (SHA of the tarball snapshot)
#   └── fleet-log/               (created on first use)
#
# Single command the courier runs on the box, as root:
#
#   sudo bash /media/*/box-onboarding.sh
#
# What it does end-to-end:
#   1. Sanity-checks the box (PHP, sqlite3, apache running, arise install
#      found, disk space, etc.). Any failure = red banner, do not proceed.
#   2. Snapshots the current migrate_*.php files (pre-onboard baseline).
#   3. Full sqlite3 .backup of arise.db → /var/backups/arise/.
#   4. Unpacks payload/arise-field-boxes.tar.gz into the arise root
#      (excluding data/ so learner data is untouched).
#   5. Calls deploy/install-updater.sh --offline to lay down cron + config.
#   6. Rewrites /var/lib/arise/applied-migrations.txt to the pre-onboard
#      baseline (so migrations that arrived in the tarball are seen as NEW).
#   7. Runs arise-update --now → applies the persistence migration + any
#      other pending migrations. Auto-rollbacks on failure.
#   8. Reloads Apache and health-checks localhost URLs.
#   9. Writes a per-box log to fleet-log/ on the USB so central ops can
#      review every visit after the courier returns.
#  10. Prints a big green SUCCESS or red FAILURE banner.
# =============================================================================
set -euo pipefail

USB_ROOT="$(cd "$(dirname "$(readlink -f "$0")")" && pwd)"
PAYLOAD_DIR="$USB_ROOT/payload"
TARBALL="$PAYLOAD_DIR/arise-field-boxes.tar.gz"
FLEET_LOG_DIR="$USB_ROOT/fleet-log"

HOSTNAME_S="$(hostname 2>/dev/null || echo unknown-host)"
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$FLEET_LOG_DIR/${HOSTNAME_S}-${STAMP}.log"

# ── Colored banners ─────────────────────────────────────────────────────────
if [[ -t 1 ]] && command -v tput >/dev/null && [[ "$(tput colors 2>/dev/null || echo 0)" -ge 8 ]]; then
    C_GREEN="$(tput setaf 2; tput bold)"
    C_RED="$(tput setaf 1; tput bold)"
    C_YELLOW="$(tput setaf 3; tput bold)"
    C_RESET="$(tput sgr0)"
else
    C_GREEN=""; C_RED=""; C_YELLOW=""; C_RESET=""
fi

step()    { echo; echo "── $* ──"; }
info()    { echo "  $*"; }
warn()    { echo "${C_YELLOW}  WARN: $*${C_RESET}"; }
success() {
    echo
    echo "${C_GREEN}==============================================================================${C_RESET}"
    echo "${C_GREEN}  SUCCESS: box '$HOSTNAME_S' is now on daily auto-updates.${C_RESET}"
    echo "${C_GREEN}  Log:     $LOG_FILE${C_RESET}"
    echo "${C_GREEN}  Next box: eject USB safely, move on.${C_RESET}"
    echo "${C_GREEN}==============================================================================${C_RESET}"
}
failure() {
    echo
    echo "${C_RED}==============================================================================${C_RESET}"
    echo "${C_RED}  FAILED: $*${C_RESET}"
    echo "${C_RED}  Box:     $HOSTNAME_S${C_RESET}"
    echo "${C_RED}  Log:     $LOG_FILE${C_RESET}"
    echo "${C_RED}  ACTION:  take a phone photo of THIS SCREEN and the log file,${C_RESET}"
    echo "${C_RED}           do NOT close this terminal, and flag the box for follow-up.${C_RESET}"
    echo "${C_RED}==============================================================================${C_RESET}"
    exit 1
}

# ── Set up log-to-USB ───────────────────────────────────────────────────────
mkdir -p "$FLEET_LOG_DIR" 2>/dev/null || {
    # USB may be read-only — fall back to /tmp
    FLEET_LOG_DIR="/tmp/arise-fleet-log"
    mkdir -p "$FLEET_LOG_DIR"
    LOG_FILE="$FLEET_LOG_DIR/${HOSTNAME_S}-${STAMP}.log"
    warn "USB not writable — logging to $LOG_FILE (please copy off before leaving)"
}
# tee everything from here on to both stdout and the log file
exec > >(tee -a "$LOG_FILE") 2>&1
echo "=== ARISE box onboarding ==="
echo "hostname:  $HOSTNAME_S"
echo "date:      $(date -Iseconds)"
echo "usb root:  $USB_ROOT"
echo "log:       $LOG_FILE"

# ── 1. Sanity checks ────────────────────────────────────────────────────────
step "1. Sanity checks"
[[ $EUID -eq 0 ]] || failure "must run as root — use: sudo bash $0"

for bin in php sqlite3 tar rsync curl; do
    if ! command -v "$bin" >/dev/null; then
        failure "missing required tool: $bin — install with: apt-get install -y $bin"
    fi
done
info "required tools present"

[[ -f "$TARBALL" ]] || failure "USB payload missing: $TARBALL — this USB is not a valid onboarding stick"
info "payload found: $(du -h "$TARBALL" | cut -f1) tarball"

# Verify tarball is not corrupt
if ! tar -tzf "$TARBALL" >/dev/null 2>&1; then
    failure "USB payload tarball is corrupt — reformat the USB using build-usb.sh"
fi
info "tarball integrity OK"

# Locate ARISE install
ARISE_ROOT=""
for candidate in \
    /var/www/arise /var/www/html/arise /srv/arise \
    /home/arise/public_html /home/*/arise
do
    if [[ -d "$candidate/public" && -d "$candidate/data" && -f "$candidate/data/arise.db" ]]; then
        ARISE_ROOT="$candidate"; break
    fi
done
[[ -n "$ARISE_ROOT" ]] || failure "cannot locate ARISE install (looked in common paths)"
info "arise root: $ARISE_ROOT"

# Disk space — need at least 500MB free for backup + tarball
FREE_MB=$(df -m "$ARISE_ROOT" | awk 'NR==2 {print $4}')
[[ "$FREE_MB" -gt 500 ]] || failure "not enough disk space (${FREE_MB}MB free — need 500MB)"
info "disk space OK (${FREE_MB}MB free)"

# Apache/nginx running?
WEB_SERVICE=""
if systemctl is-active --quiet apache2; then WEB_SERVICE=apache2
elif systemctl is-active --quiet nginx;   then WEB_SERVICE=nginx
else warn "no web server running (apache2/nginx) — will continue but health check may fail"; fi
info "web service: ${WEB_SERVICE:-none}"

# ── 2. Snapshot pre-onboard migration list ──────────────────────────────────
step "2. Snapshot current migrations"
PRE_MIGRATIONS_LIST=$(mktemp)
( cd "$ARISE_ROOT" && ls migrate_*.php 2>/dev/null | sort ) > "$PRE_MIGRATIONS_LIST"
PRE_COUNT=$(wc -l < "$PRE_MIGRATIONS_LIST")
info "found $PRE_COUNT existing migration file(s) on this box"

# ── 3. Backup DB ────────────────────────────────────────────────────────────
step "3. Backup DB"
mkdir -p /var/backups/arise
BACKUP="/var/backups/arise/arise-preonboard-$STAMP.db"
sqlite3 "$ARISE_ROOT/data/arise.db" ".backup '$BACKUP'" \
    || failure "sqlite backup failed — do NOT proceed until this is fixed"
info "DB backed up → $BACKUP ($(du -h "$BACKUP" | cut -f1))"

# ── 4. Unpack payload ───────────────────────────────────────────────────────
step "4. Unpack payload"
TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR" "$PRE_MIGRATIONS_LIST"' EXIT
tar -xzf "$TARBALL" -C "$TMPDIR" \
    || failure "tar extraction failed"

# Tarball root is typically kenyaone-arise-<sha>/. Enter it.
EXTRACTED=$(find "$TMPDIR" -maxdepth 1 -mindepth 1 -type d | head -1)
[[ -n "$EXTRACTED" && -d "$EXTRACTED/public" ]] || failure "tarball structure unexpected — no public/ dir inside"

info "rsync payload → $ARISE_ROOT (excluding data/)"
rsync -a --omit-dir-times \
    --exclude='data/' \
    --exclude='.git/' \
    "$EXTRACTED/" "$ARISE_ROOT/" \
    || failure "rsync failed"

# Fix ownership if apache is www-data
if id www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$ARISE_ROOT" 2>/dev/null || true
fi
info "code updated"

# ── 5. Install cron updater ─────────────────────────────────────────────────
step "5. Install daily updater (cron)"
if [[ ! -x "$ARISE_ROOT/deploy/install-updater.sh" ]]; then
    failure "the new bundle is missing deploy/install-updater.sh — bundle mismatch"
fi
bash "$ARISE_ROOT/deploy/install-updater.sh" \
    --arise-root "$ARISE_ROOT" \
    --offline \
    || failure "install-updater.sh failed"
info "cron updater installed → /usr/local/bin/arise-update + /etc/cron.d/arise-update"

# ── 6. Reset applied-migrations to pre-onboard baseline ─────────────────────
step "6. Prepare migration state"
# install-updater seeded applied-migrations.txt from CURRENT disk state (which
# already contains the new tarball's migrations). Rewrite to the PRE-onboard
# list so that arise-update sees the tarball's migrations as new and applies
# them. Preserves the invariant that never-run migrations always run.
mkdir -p /var/lib/arise
cp "$PRE_MIGRATIONS_LIST" /var/lib/arise/applied-migrations.txt
chmod 0644 /var/lib/arise/applied-migrations.txt
info "applied-migrations.txt reset to pre-onboard baseline ($PRE_COUNT files)"

# ── 7. Apply pending migrations ─────────────────────────────────────────────
step "7. Apply pending migrations"
if ! /usr/local/bin/arise-update --now; then
    failure "arise-update failed — DB should have been auto-rolled back, but verify:
       ls -la /var/backups/arise/ | head -5
       tail -30 /var/log/arise-update.log"
fi
info "migrations applied cleanly"

# ── 8. Reload web + health check ────────────────────────────────────────────
step "8. Reload web + health check"
if [[ -n "$WEB_SERVICE" ]]; then
    systemctl reload "$WEB_SERVICE" 2>/dev/null || systemctl restart "$WEB_SERVICE" 2>/dev/null || warn "web reload failed"
fi

HEALTHY=1
for url in http://localhost/arise/ http://localhost/arise/?p=modules; do
    code=$(curl -sS -o /dev/null -w "%{http_code}" -m 8 "$url" || echo 000)
    if [[ "$code" =~ ^[23] ]]; then
        info "$url → HTTP $code OK"
    else
        warn "$url → HTTP $code (expected 2xx or 3xx)"
        HEALTHY=0
    fi
done
[[ $HEALTHY -eq 1 ]] || warn "one or more health checks failed — box may still work; log to review"

# ── 9. Summary + banner ─────────────────────────────────────────────────────
step "9. Summary"
NEW_SHA=$(cd "$ARISE_ROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)
NEW_MIGRATIONS_COUNT=$(( $(wc -l < /var/lib/arise/applied-migrations.txt) - PRE_COUNT ))
STUDENTS=$(sqlite3 "$ARISE_ROOT/data/arise.db" "SELECT COUNT(*) FROM students" 2>/dev/null || echo ?)
PRETEST_TOTAL=$(sqlite3 "$ARISE_ROOT/data/arise.db" "SELECT COUNT(*) FROM pretest_attempts" 2>/dev/null || echo ?)
PRETEST_LINKED=$(sqlite3 "$ARISE_ROOT/data/arise.db" "SELECT COUNT(*) FROM pretest_attempts WHERE student_id IS NOT NULL" 2>/dev/null || echo ?)

info "code SHA:                $NEW_SHA"
info "migrations applied:      $NEW_MIGRATIONS_COUNT new"
info "students on box:         $STUDENTS"
info "pretest_attempts (all):  $PRETEST_TOTAL"
info "pretest_attempts w/ sid: $PRETEST_LINKED  (should match total after fix)"

success
