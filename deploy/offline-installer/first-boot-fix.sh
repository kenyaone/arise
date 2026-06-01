#!/bin/bash
# ARISE — first-boot fix for cloned/imaged machines.
# Run ONCE on a freshly-cloned machine before the first session.
# Usage:  sudo bash first-boot-fix.sh

set -e

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root: sudo bash first-boot-fix.sh"
    exit 1
fi

# Snapshot master-flag state. install.sh creates /etc/arise_cluster_master on a
# fresh master install; the pre-clone sanitizer (and step 7 below) clear it on
# clones. We capture this BEFORE doing anything so the auto-wipe at the end
# only fires on clones, never on masters.
WAS_MASTER=0
[ -e /etc/arise_cluster_master ] && WAS_MASTER=1

echo "=== ARISE First-Boot Fix ==="
echo ""

# ── 1. Regenerate machine-id and SSH host keys ───────────────────────────────
echo "[1/8] Regenerating machine identity (machine-id + SSH host keys) ..."
rm -f /etc/machine-id /var/lib/dbus/machine-id
systemd-machine-id-setup > /dev/null 2>&1
ln -sf /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true

rm -f /etc/ssh/ssh_host_*
DEBIAN_FRONTEND=noninteractive dpkg-reconfigure openssh-server > /dev/null 2>&1 || \
    ssh-keygen -A > /dev/null 2>&1 || true
systemctl restart ssh 2>/dev/null || true

# ── 2. Set a unique hostname (use last 4 hex of primary MAC) ─────────────────
echo "[2/8] Setting a unique hostname ..."
PRIMARY_IFACE=$(ip -o -4 route show to default 2>/dev/null | awk '{print $5}' | head -1)
if [ -z "$PRIMARY_IFACE" ]; then
    PRIMARY_IFACE=$(ip -o link show | awk -F': ' '!/lo:/{print $2; exit}')
fi
MAC_SUFFIX=$(cat "/sys/class/net/$PRIMARY_IFACE/address" 2>/dev/null | tr -d ':' | tail -c 5 | tr -d '\n')
NEW_HOST="arise-${MAC_SUFFIX:-$(date +%s | tail -c 5)}"
hostnamectl set-hostname "$NEW_HOST"
echo "    Hostname → $NEW_HOST"

# ── 3. Rebind the WiFi hotspot to whatever WiFi interface exists ─────────────
echo "[3/8] Rebinding ARISE-Hotspot to current WiFi interface ..."
if command -v nmcli > /dev/null 2>&1; then
    WIFI_IFACE=$(nmcli -t -f DEVICE,TYPE dev 2>/dev/null | grep ":wifi" | grep -v "p2p" | cut -d: -f1 | head -1)
    if nmcli -t -f NAME connection show 2>/dev/null | grep -q "^ARISE-Hotspot$"; then
        if [ -n "$WIFI_IFACE" ]; then
            nmcli connection modify ARISE-Hotspot connection.interface-name "$WIFI_IFACE" 2>/dev/null \
                && echo "    Hotspot bound to $WIFI_IFACE" \
                || echo "    WARN: could not modify ARISE-Hotspot — re-create it via setup.sh if needed."
        else
            echo "    WARN: no WiFi adapter detected — hotspot won't run. Use ethernet."
        fi
    else
        echo "    ARISE-Hotspot connection not present; skipping. Run setup.sh to create it."
    fi
fi

# ── 4. Reset the device_id in datapost_config so cloud-sync stats don't collide
echo "[4/8] Resetting datapost device_id ..."
DB=/var/www/arise/data/arise.db
if [ -f "$DB" ] && command -v php > /dev/null 2>&1; then
    NEW_ID="ARISE-DEV-$(tr -dc 'A-Z0-9' </dev/urandom | head -c 8)"
    php -r "
        \$db = new SQLite3('$DB');
        \$ok = \$db->exec(\"UPDATE datapost_config SET school_id='$NEW_ID' WHERE id=(SELECT MIN(id) FROM datapost_config)\");
        echo \$ok ? \"    datapost device_id → $NEW_ID\n\" : \"    WARN: could not update datapost_config\n\";
    "
else
    echo "    (skipped — DB or PHP not found)"
fi

# ── 5. Write MAC-derived device_id for cloud sync (cloud_push.php) ───────────
# Extract the first non-loopback MAC from `link/ether <mac>` (NOT the interface
# name — that's `eno1`/`enp3s0`/etc. and collides across clones).
echo "[5/8] Writing cloud sync device_id ..."
SYNC_MAC=$(ip -o link show 2>/dev/null \
    | awk '!/lo:/ && /link\/ether/ { for (i=1;i<=NF;i++) if ($i=="link/ether") { print $(i+1); exit } }' \
    | tr -d ':')
if [ -z "$SYNC_MAC" ]; then
    SYNC_MAC=$(tr -dc 'A-F0-9' </dev/urandom | head -c 12)
fi
CLOUD_DEVICE_ID="ARISE-$(echo "$SYNC_MAC" | tr 'a-z' 'A-Z')"
echo "$CLOUD_DEVICE_ID" > /etc/arise_device_id
chown arise:arise /etc/arise_device_id 2>/dev/null || true
chmod 0644       /etc/arise_device_id 2>/dev/null || true
echo "    cloud device_id → $CLOUD_DEVICE_ID"

# ── 6. Self-heal cloud-sync cron (locations.php depends on this) ─────────────
echo "[6/8] Verifying cloud-sync cron ..."

# (a) cloud_push.php exists at /home/arise/. Recover from /var/www/arise/ copy
#     if missing (e.g. someone deleted the home file by mistake).
if [ ! -f /home/arise/cloud_push.php ] && [ -f /var/www/arise/cloud_push.php ]; then
    install -o arise -g arise -m 0755 /var/www/arise/cloud_push.php /home/arise/cloud_push.php
    echo "    Restored /home/arise/cloud_push.php from /var/www/arise/"
fi

# (b) log file exists with arise ownership so PHP can append.
if [ ! -f /home/arise/cloud_sync.log ]; then
    touch /home/arise/cloud_sync.log
    chown arise:arise /home/arise/cloud_sync.log
    chmod 0644       /home/arise/cloud_sync.log
fi

# (c) cron entry for the arise user. Idempotent — appends only if missing.
CRON_LINE="* * * * * php /home/arise/cloud_push.php"
EXISTING=$(crontab -u arise -l 2>/dev/null || echo "")
if ! echo "$EXISTING" | grep -qF "$CRON_LINE"; then
    printf '%s\n%s\n' "$EXISTING" "$CRON_LINE" | crontab -u arise -
    echo "    Cron entry restored: $CRON_LINE"
else
    echo "    Cron entry already present."
fi

# (d) cron daemon enabled. Ubuntu ships it that way but be explicit.
systemctl enable cron > /dev/null 2>&1 || true
systemctl start  cron > /dev/null 2>&1 || true

# (e) Kick off a single sync now so the cloud sees this clone within seconds
#     of the welcome screen appearing, not at the next-minute boundary.
sudo -u arise php /home/arise/cloud_push.php > /dev/null 2>&1 || true
echo "    First sync attempt fired."

# ── 7. Restart Apache so any per-host paths re-resolve ────────────────────────
echo "[7/8] Restarting Apache ..."
systemctl restart apache2

# ── 8. Clone-specific cleanup: wipe learner data + revoke GitHub push key ────
echo "[8/8] Clone cleanup (learner data + push credentials) ..."
if [ "$WAS_MASTER" = "1" ]; then
    echo "    Master flag was present at start — KEEPING learner data and SSH keys."
    # Defensive: leave the flag in place so cloud_push still treats this as master.
else
    # Make sure the master flag really is gone on clones, so cloud_push.php
    # never accidentally pushes cluster topology from a clone.
    rm -f /etc/arise_cluster_master

    # Remove the GitHub push key. Clones must be pull-only so a misclick in
    # the admin UI (or a compromised field box) can't rewrite the canonical
    # codebase. To restore push from this clone later, generate a new key
    # and add it manually to GitHub.
    for u in arise root; do
        h=$(getent passwd "$u" | cut -d: -f6)
        [ -z "$h" ] && continue
        rm -f "$h/.ssh/id_ed25519" "$h/.ssh/id_ed25519.pub" \
              "$h/.ssh/id_rsa"     "$h/.ssh/id_rsa.pub" \
              "$h/.ssh/id_ecdsa"   "$h/.ssh/id_ecdsa.pub" 2>/dev/null
    done
    echo "    Cleared SSH push keys — clone can pull updates but not push."

    FRESH=/var/www/arise/deploy/offline-installer/clone-fresh-start.sh
    if [ -x "$FRESH" ]; then
        echo "    Clone detected — running $(basename "$FRESH") --yes"
        # Don't let a wipe failure block the firstboot service from marking itself done.
        bash "$FRESH" --yes || echo "    WARN: wipe failed; run manually with sudo bash $FRESH"
    else
        echo "    clone-fresh-start.sh not found at $FRESH — skipping wipe (run it manually)."
    fi
fi

# ── Summary ───────────────────────────────────────────────────────────────────
IP=$(ip -4 addr show "$PRIMARY_IFACE" 2>/dev/null | grep -oE 'inet [0-9.]+' | awk '{print $2}' | head -1)
IP=${IP:-$(hostname -I | awk '{print $1}')}

echo ""
echo "════════════════════════════════════════════"
echo "  ARISE First-Boot Fix Complete"
echo "════════════════════════════════════════════"
echo "  Hostname      : $NEW_HOST"
echo "  Primary IP    : $IP"
echo "  Cloud sync ID : $CLOUD_DEVICE_ID"
echo "  ARISE URL     : http://$IP/arise/"
echo "  Admin panel   : http://$IP/arise/admin/"
echo ""
echo "  If you want a WiFi hotspot, re-run:  sudo bash setup.sh"
echo "  (or set it up manually via NetworkManager / nmcli)"
echo "════════════════════════════════════════════"
