#!/bin/bash
# ARISE courier — pulls a tagged commit from GitHub, packages it into
# arise-update-<version>.zip with a manifest, ready to ship to an offline
# field box via the DataPost /deliver page.
#
# Usage:  ./arise-courier.sh [git-ref]
#   git-ref defaults to "main"; can be a branch, tag, or commit SHA.

set -e

GIT_REF="${1:-main}"
REPO_URL="https://github.com/kenyaone/arise.git"
OUTPUT_DIR="${OUTPUT_DIR:-$(pwd)}"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

echo "==> Fetching $REPO_URL @ $GIT_REF"
git clone --depth 1 --branch "$GIT_REF" "$REPO_URL" "$WORK/arise" 2>&1 | sed 's/^/   /'

cd "$WORK/arise"
SHA=$(git rev-parse HEAD)
SHORT=$(git rev-parse --short HEAD)
VERSION="$(date +%Y%m%d-%H%M%S)-$SHORT"
FILES_COUNT=$(find . -type f -not -path "./.git/*" | wc -l)
TOTAL_BYTES=$(du -sb . 2>/dev/null | awk '{print $1}')

echo "==> Building bundle"
echo "    version : $VERSION"
echo "    git ref : $GIT_REF"
echo "    git sha : $SHA"
echo "    files   : $FILES_COUNT"
echo "    size    : $((TOTAL_BYTES / 1024)) KiB"

# Strip .git and any local-only artifacts that shouldn't ship
rm -rf .git .github .gitignore

# Write manifest the offline apply UI will read
cat > manifest.json <<JSON
{
  "version":   "$VERSION",
  "git_ref":   "$GIT_REF",
  "git_sha":   "$SHA",
  "built_at":  "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
  "files":     $FILES_COUNT,
  "bytes":     $TOTAL_BYTES,
  "courier":   "$(whoami)@$(hostname)",
  "skip_dirs": ["data", "data/backups", "data/datapost", "data/content/updates"]
}
JSON

OUT="$OUTPUT_DIR/arise-update-$VERSION.zip"
zip -rq "$OUT" . -x ".git/*" ".github/*"
echo "==> Wrote $OUT  ($(du -h "$OUT" | cut -f1))"
echo
echo "Next steps:"
echo "  1. Copy $OUT onto a USB drive."
echo "  2. Take it to an offline ARISE box and upload via:"
echo "     http://<box-ip>/arise/admin/?p=datapost  -> Deliver tab"
echo "  3. In the admin, open ?p=updates -> click Apply on the new bundle."
