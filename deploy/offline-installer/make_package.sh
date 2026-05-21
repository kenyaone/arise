#!/bin/bash
# Run this on the SOURCE machine (with internet) to create a fully offline ARISE package.
# Usage: sudo bash make_package.sh

set -e

PACKAGE_DIR="/home/tele/arise_deploy"
DEBS_DIR="$PACKAGE_DIR/debs"
OUTPUT="$PACKAGE_DIR/arise_package.tar.gz"

echo "=== ARISE Packager (offline-capable) ==="
echo ""

# ── 1. Download all required .deb packages with dependencies ─────────────────
echo "[1/4] Downloading .deb packages (requires internet on THIS machine) ..."
mkdir -p "$DEBS_DIR"

# Refresh apt cache silently
apt-get update -qq

# Download packages + all dependencies into debs/
# --download-only fetches to /var/cache/apt/archives/ without installing
apt-get install -y --download-only \
    apache2 \
    php \
    php-sqlite3 \
    php-curl \
    libapache2-mod-php \
    php-mbstring \
    php-xml \
    php-zip \
    curl \
    openssl \
    > /dev/null 2>&1

# Copy downloaded .deb files to our debs/ folder
cp /var/cache/apt/archives/*.deb "$DEBS_DIR/" 2>/dev/null || true
DEB_COUNT=$(ls "$DEBS_DIR"/*.deb 2>/dev/null | wc -l)
echo "    Downloaded $DEB_COUNT .deb packages."

# ── 2. Archive the entire ARISE app (files + SQLite DB) ──────────────────────
echo "[2/4] Archiving /var/www/arise/ ..."
tar --exclude='/var/www/arise/data/uploads/videos/*.mp4' \
    -czf "$PACKAGE_DIR/arise_files.tar.gz" \
    -C /var/www arise/
# To include videos, remove the --exclude line above.

# ── 3. Copy Apache config ─────────────────────────────────────────────────────
echo "[3/4] Copying Apache config ..."
cp /etc/apache2/sites-available/mtti-lms.conf "$PACKAGE_DIR/mtti-lms.conf"

# ── 4. Bundle everything into one deployable archive ─────────────────────────
echo "[4/4] Creating final package ..."

# Pull in first-boot-fix.sh if it lives next to make_package.sh, so cloned
# machines have it without needing a separate download.
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
if [ -f "$SRC_DIR/first-boot-fix.sh" ]; then
    cp "$SRC_DIR/first-boot-fix.sh" "$PACKAGE_DIR/first-boot-fix.sh"
    EXTRA_FILES="first-boot-fix.sh"
else
    EXTRA_FILES=""
fi

tar -czf "$OUTPUT" \
    -C "$PACKAGE_DIR" \
    debs/ \
    arise_files.tar.gz \
    mtti-lms.conf \
    install.sh \
    $EXTRA_FILES

# Cleanup intermediates
rm -rf "$DEBS_DIR"
rm -f  "$PACKAGE_DIR/arise_files.tar.gz"

echo ""
echo "=== Done ==="
SIZE=$(du -sh "$OUTPUT" | cut -f1)
echo "Package created: $OUTPUT  ($SIZE)"
echo ""
echo "Copy this file to any target machine (no internet needed), then run:"
echo "  tar -xzf arise_package.tar.gz && sudo bash install.sh"
echo ""
echo "NOTE: The .deb packages are for $(dpkg --print-architecture) architecture."
echo "      Target machines must be the same architecture (e.g. all amd64 or all arm64)."
