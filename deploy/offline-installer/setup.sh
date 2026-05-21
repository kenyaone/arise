#!/bin/bash
# ARISE Setup — run this on the target machine.
# Usage: sudo bash setup.sh
# No extraction needed — this script handles everything.

if [ "$EUID" -ne 0 ]; then
  echo "ERROR: Please run as root: sudo bash setup.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PACKAGE="$SCRIPT_DIR/arise_package.tar.gz"
WORK_DIR="/tmp/arise_install"

echo "=== ARISE Setup ==="
echo ""

# Check package exists
if [ ! -f "$PACKAGE" ]; then
    echo "ERROR: arise_package.tar.gz not found in $SCRIPT_DIR"
    exit 1
fi

# Extract package to temp folder
echo "Extracting package ..."
rm -rf "$WORK_DIR"
mkdir -p "$WORK_DIR"
tar -xzf "$PACKAGE" -C "$WORK_DIR"
echo "Done."
echo ""

# Run the installer from inside the extracted folder
bash "$WORK_DIR/install.sh"

# If the package bundled a first-boot-fix.sh, preserve it next to setup.sh
# so anyone cloning this machine's image can run it as a one-time fix.
if [ -f "$WORK_DIR/first-boot-fix.sh" ] && [ ! -f "$SCRIPT_DIR/first-boot-fix.sh" ]; then
    cp "$WORK_DIR/first-boot-fix.sh" "$SCRIPT_DIR/first-boot-fix.sh"
    chmod +x "$SCRIPT_DIR/first-boot-fix.sh"
fi

# Cleanup
rm -rf "$WORK_DIR"
