#!/usr/bin/env bash
# =============================================================================
# ARISE — one-shot field-tech update script.
#
# For a technician sitting at a box that has internet and needs the latest
# fixes from origin/field-boxes.
#
# What the tech runs (as root):
#
#     sudo bash box-update.sh
#
# Or straight from GitHub if the box has curl:
#
#     curl -fsSL https://raw.githubusercontent.com/kenyaone/arise/field-boxes/deploy/box-update.sh | sudo bash
#
# The script:
#   1. Refuses to run unless root.
#   2. If /usr/local/bin/arise-update exists, hands off to it (safer path).
#   3. Otherwise:
#        a. Finds the ARISE install dir.
#        b. Refuses if the box is on `master` (that branch bricks boxes).
#        c. sqlite3 .backup of arise.db → /var/backups/arise/.
#        d. Stashes any local edits to /var/lib/arise/dirty-*.patch.
#        e. Fetches origin, hard-resets to origin/field-boxes.
#        f. Applies new migrate_*.php (idempotent via applied-migrations.txt).
#           On failure, restores DB + resets code to pre-update SHA.
#        g. Reloads Apache.
#   4. Prints a GREEN success or RED failure banner and exits.
# =============================================================================
set -euo pipefail

BRANCH="field-boxes"
APPLIED_FILE="/var/lib/arise/applied-migrations.txt"
BACKUP_DIR="/var/backups/arise"
DIRTY_DIR="/var/lib/arise"
STAMP="$(date +%Y%m%d-%H%M%S)"
HOSTNAME_S="$(hostname 2>/dev/null || echo unknown-host)"

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
    echo "${C_GREEN}  SUCCESS: box '$HOSTNAME_S' updated.${C_RESET}"
    echo "${C_GREEN}  Now at:  $(cd "$ARISE_ROOT" && git log -1 --oneline)${C_RESET}"
    echo "${C_GREEN}==============================================================================${C_RESET}"
    exit 0
}
failure() {
    echo
    echo "${C_RED}==============================================================================${C_RESET}"
    echo "${C_RED}  FAILED: $*${C_RESET}"
    echo "${C_RED}  Box:    $HOSTNAME_S${C_RESET}"
    echo "${C_RED}  ACTION: take a phone photo of this whole screen and flag for follow-up.${C_RESET}"
    echo "${C_RED}==============================================================================${C_RESET}"
    exit 1
}

# ── Must be root ────────────────────────────────────────────────────────────
[[ $EUID -eq 0 ]] || failure "must run as root — re-run with: sudo bash box-update.sh"

# ── If hardened updater is installed, hand off to it ────────────────────────
if command -v arise-update >/dev/null; then
    step "Hardened updater is installed — handing off to arise-update --now"
    if arise-update --now; then
        info "arise-update completed OK"
        # Try to locate ARISE_ROOT for the success banner
        ARISE_ROOT="$(awk -F= '/^ARISE_ROOT=/ {gsub(/"/,"",$2); print $2}' /etc/arise-update.conf 2>/dev/null || echo /var/www/arise)"
        success
    else
        failure "arise-update exited non-zero — check /var/log/arise-update.log"
    fi
fi

# ── Locate ARISE install ────────────────────────────────────────────────────
step "Locating ARISE install"
ARISE_ROOT=""
for candidate in /var/www/arise /var/www/html/arise /srv/arise /opt/arise /home/*/arise; do
    if [[ -d "$candidate/.git" && -f "$candidate/data/arise.db" ]]; then
        ARISE_ROOT="$candidate"
        break
    fi
done
[[ -n "$ARISE_ROOT" ]] || failure "cannot find ARISE install (looked in /var/www/arise, /var/www/html/arise, /srv/arise, /opt/arise, /home/*/arise)"
info "found: $ARISE_ROOT"

cd "$ARISE_ROOT"

# ── Preconditions ───────────────────────────────────────────────────────────
step "Checking box preconditions"
command -v git     >/dev/null || failure "git not installed on box"
command -v sqlite3 >/dev/null || failure "sqlite3 not installed on box"
command -v php     >/dev/null || failure "php not installed on box"
[[ -f data/arise.db ]] || failure "DB missing: $ARISE_ROOT/data/arise.db"
info "git, sqlite3, php present; DB found"

# ── Branch guard — refuse master ────────────────────────────────────────────
step "Verifying branch"
CUR_BRANCH="$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo unknown)"
info "current branch: $CUR_BRANCH"
if [[ "$CUR_BRANCH" == "master" || "$CUR_BRANCH" == "main" ]]; then
    failure "box is on '$CUR_BRANCH' — that branch is the CLOUD PANEL and will brick the box. Do NOT proceed. Call ops."
fi
if [[ "$CUR_BRANCH" != "$BRANCH" ]]; then
    warn "not on $BRANCH; will switch to it"
    git fetch --prune origin >/dev/null 2>&1 || failure "git fetch failed — check internet on box"
    git checkout "$BRANCH" 2>/dev/null || git checkout -b "$BRANCH" "origin/$BRANCH" || failure "cannot switch to $BRANCH"
fi

PRE_SHA="$(git rev-parse HEAD)"
info "pre-update SHA: ${PRE_SHA:0:12}"

# ── DB backup (safe .backup, not cp) ────────────────────────────────────────
step "Backing up SQLite DB"
mkdir -p "$BACKUP_DIR"
BACKUP="$BACKUP_DIR/arise-$STAMP.db"
sqlite3 data/arise.db ".backup '$BACKUP'" || failure "sqlite backup failed — refusing to touch code"
info "backup: $BACKUP"
# Keep last 14
ls -1t "$BACKUP_DIR"/arise-*.db 2>/dev/null | tail -n +15 | xargs -r rm -f

# ── Stash local edits ───────────────────────────────────────────────────────
step "Checking for local edits"
if [[ -n "$(git status --porcelain)" ]]; then
    mkdir -p "$DIRTY_DIR"
    PATCH="$DIRTY_DIR/dirty-${PRE_SHA:0:12}-$STAMP.patch"
    warn "working tree dirty — saving diff to $PATCH"
    git diff HEAD > "$PATCH" || true
    git reset --hard HEAD >/dev/null
else
    info "clean"
fi

# ── Fetch + reset ───────────────────────────────────────────────────────────
step "Fetching origin/$BRANCH"
git fetch --prune origin || failure "git fetch failed — check internet on box"
NEW_SHA="$(git rev-parse "origin/$BRANCH")"
if [[ "$NEW_SHA" == "$PRE_SHA" ]]; then
    info "already at latest (${NEW_SHA:0:12}) — nothing to do"
    # Still reload Apache in case config drifted
    systemctl reload apache2 2>/dev/null || true
    success
fi
info "updating: ${PRE_SHA:0:12} → ${NEW_SHA:0:12}"
git reset --hard "origin/$BRANCH" || failure "git reset failed"

# ── Migrations ──────────────────────────────────────────────────────────────
step "Applying new migrations"
mkdir -p "$(dirname "$APPLIED_FILE")"
touch "$APPLIED_FILE"
TO_APPLY=()
while IFS= read -r f; do
    base="$(basename "$f")"
    if ! grep -Fxq "$base" "$APPLIED_FILE"; then
        TO_APPLY+=("$base")
    fi
done < <(ls migrate_*.php 2>/dev/null | sort)

if (( ${#TO_APPLY[@]} == 0 )); then
    info "no new migrations"
else
    info "will apply: ${TO_APPLY[*]}"
    for m in "${TO_APPLY[@]}"; do
        info "  → $m"
        if ! php "$m"; then
            warn "migration $m FAILED — rolling back"
            cp "$BACKUP" data/arise.db
            git reset --hard "$PRE_SHA"
            failure "migration $m failed; DB + code restored to pre-update state"
        fi
        echo "$m" >> "$APPLIED_FILE"
    done
fi

# ── Fix ownership so www-data can read new files ────────────────────────────
step "Fixing file ownership"
if id -u www-data >/dev/null 2>&1; then
    chown -R www-data:www-data "$ARISE_ROOT" 2>/dev/null || warn "chown www-data failed (non-fatal)"
    info "chown www-data OK"
else
    warn "www-data user not found — skipping chown"
fi

# ── Reload web server ───────────────────────────────────────────────────────
step "Reloading Apache"
if systemctl reload apache2 2>/dev/null; then
    info "apache2 reloaded"
elif systemctl restart apache2 2>/dev/null; then
    info "apache2 restarted"
else
    warn "could not reload apache2 — check 'systemctl status apache2'"
fi

# ── Health check ────────────────────────────────────────────────────────────
step "Health check"
if command -v curl >/dev/null; then
    HTTP="$(curl -s -o /dev/null -w '%{http_code}' -m 5 http://localhost/arise/ || echo 000)"
    info "http://localhost/arise/ → $HTTP"
    [[ "$HTTP" =~ ^(200|301|302)$ ]] || warn "unexpected status; site may still be OK — verify in a browser"
fi

success
