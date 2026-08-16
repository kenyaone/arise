#!/usr/bin/env bash
# =============================================================================
# ARISE — build the USB image for courier box-onboarding visits.
#
# Runs on ops's laptop (NOT on a box). Produces a staging directory that
# ops copies onto a USB stick (or `rsync -a` to /media/*/ once mounted).
#
# What it produces:
#
#   $OUT_DIR/
#   ├── box-onboarding.sh        (copied from this repo)
#   ├── checklist.md             (courier one-pager)
#   ├── README.txt               (safety notes)
#   └── payload/
#       ├── arise-field-boxes.tar.gz    (latest field-boxes snapshot from GitHub)
#       └── SHA                          (git SHA of the snapshot for verification)
#
# Usage:
#   bash deploy/build-usb.sh                    # → /tmp/arise-usb-staging/
#   bash deploy/build-usb.sh --out /path/to/dir
#   bash deploy/build-usb.sh --ref field-boxes  # default already
#   bash deploy/build-usb.sh --local            # build from the current
#                                                 working tree instead of pulling
#                                                 from GitHub (useful for testing)
#
# Then, with a USB stick mounted at /media/ops/USB:
#   sudo rsync -a --delete /tmp/arise-usb-staging/ /media/ops/USB/
#
# You can prepare one staging dir and mirror it to as many USB sticks as
# you have boxes to visit.
# =============================================================================
set -euo pipefail

REF="field-boxes"
OUT_DIR="/tmp/arise-usb-staging"
USE_LOCAL=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --out)   OUT_DIR="$2"; shift 2 ;;
        --ref)   REF="$2";     shift 2 ;;
        --local) USE_LOCAL=1;  shift   ;;
        -h|--help)
            sed -n '2,30p' "$0"; exit 0 ;;
        *) echo "Unknown arg: $1" >&2; exit 2 ;;
    esac
done

REPO_ROOT="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
log() { printf '[build-usb %(%F %T)T] %s\n' -1 "$*"; }
fail() { echo "ERROR: $*" >&2; exit 1; }

log "output staging dir: $OUT_DIR"
log "field-boxes ref:    $REF"
log "source:             $([[ $USE_LOCAL -eq 1 ]] && echo 'local working tree' || echo 'GitHub codeload')"

# Clean out any previous staging
rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR/payload" "$OUT_DIR/fleet-log"

# ── 1. Assemble the tarball ─────────────────────────────────────────────────
TARBALL="$OUT_DIR/payload/arise-field-boxes.tar.gz"

if (( USE_LOCAL )); then
    log "building tarball from local working tree at $REPO_ROOT"
    ( cd "$REPO_ROOT" && \
      tar --exclude='./.git' \
          --exclude='./data' \
          --exclude='./node_modules' \
          --exclude='./.venv' \
          -czf "$TARBALL" \
          --transform "s,^\./,kenyaone-arise-local/," \
          . ) \
      || fail "local tarball build failed"
    echo "local-$(cd "$REPO_ROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)" > "$OUT_DIR/payload/SHA"
else
    log "downloading tarball from github.com/kenyaone/arise@$REF"
    URL="https://codeload.github.com/kenyaone/arise/tar.gz/$REF"
    curl -fsSL -o "$TARBALL" "$URL" \
        || fail "download failed from $URL — check network / ref name"

    log "resolving SHA for $REF"
    SHA=$(curl -fsSL -H 'User-Agent: arise-build-usb' \
        "https://api.github.com/repos/kenyaone/arise/commits/$REF" \
        | grep -m1 '"sha"' | head -1 | cut -d'"' -f4)
    echo "${SHA:-unknown}" > "$OUT_DIR/payload/SHA"
fi

TARBALL_SIZE=$(du -h "$TARBALL" | cut -f1)
log "tarball ready: $TARBALL ($TARBALL_SIZE)"

# ── 2. Copy the courier files from this repo ────────────────────────────────
cp "$REPO_ROOT/deploy/box-onboarding.sh" "$OUT_DIR/box-onboarding.sh"
chmod +x "$OUT_DIR/box-onboarding.sh"

if [[ -f "$REPO_ROOT/deploy/box-onboarding-checklist.md" ]]; then
    cp "$REPO_ROOT/deploy/box-onboarding-checklist.md" "$OUT_DIR/checklist.md"
fi

# ── 3. Human-readable README so someone finding the USB knows what it is ────
cat > "$OUT_DIR/README.txt" <<EOF
ARISE box-onboarding USB
========================

Built:       $(date -Iseconds)
Field ref:   $REF
SHA:         $(cat "$OUT_DIR/payload/SHA")
Tarball:     payload/arise-field-boxes.tar.gz ($TARBALL_SIZE)

This USB contains a one-command installer that upgrades an ARISE school
box to the latest field-boxes code and enables daily auto-updates.

To use, on a box with root access:
    sudo bash /media/*/box-onboarding.sh

Or read checklist.md for the step-by-step version.

DO NOT modify files on this USB unless you know what you are doing.
The 'fleet-log/' folder will accumulate one log file per visited box —
please return these logs to the ops team.
EOF

# ── 4. Sanity check the output ──────────────────────────────────────────────
log "verifying output layout:"
find "$OUT_DIR" -maxdepth 2 -printf '  %p (%s bytes)\n' | sort

log "DONE."
echo
echo "Next step: copy $OUT_DIR/ to a USB stick, e.g.:"
echo "    sudo rsync -a --delete $OUT_DIR/ /media/\$USER/YOUR_USB/"
echo
echo "Then hand the USB to a courier along with checklist.md printed on paper."
