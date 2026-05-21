#!/bin/bash
# ARISE — first-boot fix for cloned/imaged machines.
# Run ONCE on a freshly-cloned machine before the first session.
# Usage:  sudo bash first-boot-fix.sh

set -e

if [ "$EUID" -ne 0 ]; then
    echo "ERROR: Please run as root: sudo bash first-boot-fix.sh"
    exit 1
fi

echo "=== ARISE First-Boot Fix ==="
echo ""

# ── 1. Regenerate machine-id and SSH host keys ───────────────────────────────
echo "[1/5] Regenerating machine identity (machine-id + SSH host keys) ..."
rm -f /etc/machine-id /var/lib/dbus/machine-id
systemd-machine-id-setup > /dev/null 2>&1
ln -sf /etc/machine-id /var/lib/dbus/machine-id 2>/dev/null || true

rm -f /etc/ssh/ssh_host_*
DEBIAN_FRONTEND=noninteractive dpkg-reconfigure openssh-server > /dev/null 2>&1 || \
    ssh-keygen -A > /dev/null 2>&1 || true
systemctl restart ssh 2>/dev/null || true

# ── 2. Set a unique hostname (use last 4 hex of primary MAC) ─────────────────
echo "[2/5] Setting a unique hostname ..."
PRIMARY_IFACE=$(ip -o -4 route show to default 2>/dev/null | awk '{print $5}' | head -1)
if [ -z "$PRIMARY_IFACE" ]; then
    PRIMARY_IFACE=$(ip -o link show | awk -F': ' '!/lo:/{print $2; exit}')
fi
MAC_SUFFIX=$(cat "/sys/class/net/$PRIMARY_IFACE/address" 2>/dev/null | tr -d ':' | tail -c 5 | tr -d '\n')
NEW_HOST="arise-${MAC_SUFFIX:-$(date +%s | tail -c 5)}"
hostnamectl set-hostname "$NEW_HOST"
echo "    Hostname → $NEW_HOST"

# ── 3. Rebind the WiFi hotspot to whatever WiFi interface exists ─────────────
echo "[3/5] Rebinding ARISE-Hotspot to current WiFi interface ..."
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
echo "[4/5] Resetting cloud sync device_id ..."
DB=/var/www/arise/data/arise.db
if [ -f "$DB" ] && command -v php > /dev/null 2>&1; then
    NEW_ID="ARISE-DEV-$(tr -dc 'A-Z0-9' </dev/urandom | head -c 8)"
    php -r "
        \$db = new SQLite3('$DB');
        \$ok = \$db->exec(\"UPDATE datapost_config SET school_id='$NEW_ID' WHERE id=(SELECT MIN(id) FROM datapost_config)\");
        echo \$ok ? \"    device_id → $NEW_ID\n\" : \"    WARN: could not update datapost_config\n\";
    "
else
    echo "    (skipped — DB or PHP not found)"
fi

# ── 5. Restart Apache so any per-host paths re-resolve ────────────────────────
echo "[5/5] Restarting Apache ..."
systemctl restart apache2

# ── Summary ───────────────────────────────────────────────────────────────────
IP=$(ip -4 addr show "$PRIMARY_IFACE" 2>/dev/null | grep -oE 'inet [0-9.]+' | awk '{print $2}' | head -1)
IP=${IP:-$(hostname -I | awk '{print $1}')}

echo ""
echo "════════════════════════════════════════════"
echo "  ARISE First-Boot Fix Complete"
echo "════════════════════════════════════════════"
echo "  Hostname     : $NEW_HOST"
echo "  Primary IP   : $IP"
echo "  ARISE URL    : http://$IP/arise/"
echo "  Admin panel  : http://$IP/arise/admin/"
echo ""
echo "  If you want a WiFi hotspot, re-run:  sudo bash setup.sh"
echo "  (or set it up manually via NetworkManager / nmcli)"
echo "════════════════════════════════════════════"
