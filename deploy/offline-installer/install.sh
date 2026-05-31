#!/bin/bash
# ARISE Platform Installer — fully offline, no internet required.
# Usage: sudo bash install.sh

set -e

echo "=== ARISE Platform Installer (Offline) ==="
echo ""

# ── 1. Check root ─────────────────────────────────────────────────────────────
if [ "$EUID" -ne 0 ]; then
  echo "ERROR: Please run as root: sudo bash install.sh"
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DEBS_DIR="$SCRIPT_DIR/debs"

# ── 2. Install packages from bundled .deb files ───────────────────────────────
echo "[1/7] Installing Apache, PHP 8, SQLite3 from bundled packages ..."

if [ ! -d "$DEBS_DIR" ] || [ -z "$(ls "$DEBS_DIR"/*.deb 2>/dev/null)" ]; then
    echo "ERROR: No .deb files found in $DEBS_DIR"
    echo "       This package may be corrupted. Re-run make_package.sh on the source machine."
    exit 1
fi

dpkg -i "$DEBS_DIR"/*.deb 2>/dev/null || dpkg -i "$DEBS_DIR"/*.deb || true
dpkg --configure -a 2>/dev/null || true

# curl + php-curl are required by the cloud-sync code path. Older packages
# omitted them; install from the bundle if present, otherwise fall back to apt.
if ! command -v curl > /dev/null 2>&1 || ! php -m 2>/dev/null | grep -qi '^curl$'; then
    echo "    Installing curl + php-curl ..."
    apt-get install -y curl php-curl > /dev/null 2>&1 || \
        echo "    WARN: could not install curl/php-curl — cloud sync will fail until installed."
fi

a2enmod rewrite headers ssl > /dev/null 2>&1 || true

# ── 3. Restore app files ──────────────────────────────────────────────────────
echo "[2/7] Restoring app files to /var/www/arise/ ..."
mkdir -p /var/www/arise

# Preserve an existing DB so re-running setup.sh doesn't wipe user data.
EXISTING_DB=/var/www/arise/data/arise.db
DB_BACKUP=""
if [ -f "$EXISTING_DB" ]; then
    DB_BACKUP="/var/www/arise/data/arise.db.preinstall-$(date +%Y%m%d-%H%M%S)"
    cp -a "$EXISTING_DB" "$DB_BACKUP"
    echo "    Existing DB backed up → $DB_BACKUP"
fi

tar -xzf "$SCRIPT_DIR/arise_files.tar.gz" -C /var/www/

# Restore the live DB if the tarball overwrote it with an older one.
if [ -n "$DB_BACKUP" ] && [ -f "$DB_BACKUP" ]; then
    cp -a "$DB_BACKUP" "$EXISTING_DB"
    chown www-data:www-data "$EXISTING_DB"
    echo "    Live DB restored from backup."
fi

# ── 4. Fix ownership & permissions ───────────────────────────────────────────
echo "[3/7] Setting file ownership ..."
chown -R www-data:www-data /var/www/arise/
chmod -R 755 /var/www/arise/
chmod -R 775 /var/www/arise/data/
chmod 664    /var/www/arise/data/arise.db

# ── 5. Install Apache config ──────────────────────────────────────────────────
echo "[4/7] Installing Apache virtual host config ..."
cp "$SCRIPT_DIR/mtti-lms.conf" /etc/apache2/sites-available/mtti-lms.conf
a2dissite 000-default > /dev/null 2>&1 || true
a2ensite mtti-lms > /dev/null

# ── 6. Generate self-signed SSL cert ─────────────────────────────────────────
echo "[5/7] Generating self-signed SSL certificate ..."
if [ ! -f /etc/ssl/certs/arise-selfsigned.crt ]; then
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout /etc/ssl/private/arise-selfsigned.key \
        -out    /etc/ssl/certs/arise-selfsigned.crt \
        -subj "/CN=arise.local/O=ARISE/C=KE" > /dev/null 2>&1
fi

# ── 7. Restart Apache ─────────────────────────────────────────────────────────
echo "[6/8] Starting Apache ..."
systemctl enable apache2 > /dev/null 2>&1
systemctl restart apache2

# ── 8. Cloud sync cron (the offline → ariseci.org channel) ───────────────────
echo "[7/8] Configuring cloud sync cron ..."
# Canonical source for cloud_push.php is /var/www/arise/cloud_push.php (laid
# down by the rsync in step [2]). The cron however runs as the arise user
# against /home/arise/cloud_push.php so it can write the log file without
# escalating privileges. Mirror the canonical copy → /home/arise/ on every
# install. Same mirror happens inside the admin Updates page so future
# upgrades stay in sync automatically.
if [ -f /var/www/arise/cloud_push.php ]; then
    install -o arise -g arise -m 0755 /var/www/arise/cloud_push.php /home/arise/cloud_push.php
    touch /home/arise/cloud_sync.log
    chown arise:arise /home/arise/cloud_sync.log
    chmod 0644       /home/arise/cloud_sync.log

    # Register the cron line for the arise user if not already present.
    CRON_LINE="* * * * * php /home/arise/cloud_push.php"
    EXISTING=$(crontab -u arise -l 2>/dev/null || echo "")
    if ! echo "$EXISTING" | grep -qF "$CRON_LINE"; then
        printf '%s\n%s\n' "$EXISTING" "$CRON_LINE" | crontab -u arise -
        echo "    cron registered: $CRON_LINE"
    else
        echo "    cron already present."
    fi
else
    echo "    WARN: /var/www/arise/cloud_push.php missing — cloud sync disabled until present."
fi

# ── 9. Network setup — hotspot + static IP ───────────────────────────────────
echo ""
echo "[8/8] Network Setup"
echo "────────────────────────────────────────────"

# Check if nmcli (NetworkManager) is available
if ! command -v nmcli &>/dev/null; then
    echo "WARNING: NetworkManager (nmcli) not found. Skipping network setup."
    echo "         Set up the WiFi hotspot manually after installation."
    IP=$(hostname -I | awk '{print $1}')
    echo "         Current IP: $IP — students open http://$IP/arise/"
    SKIP_NETWORK=1
fi

if [ -z "$SKIP_NETWORK" ]; then
    # Detect WiFi interface
    WIFI_IFACE=$(nmcli -t -f DEVICE,TYPE dev 2>/dev/null | grep ":wifi" | grep -v "p2p" | cut -d: -f1 | head -1)

    if [ -z "$WIFI_IFACE" ]; then
        echo "WARNING: No WiFi adapter detected on this machine."
        echo "         Connect it to a router — students use: http://$(hostname -I | awk '{print $1}')/arise/"
    else
        echo "WiFi adapter: $WIFI_IFACE"
        echo ""
        echo "Enter hotspot settings (press Enter to accept defaults):"
        echo ""

        read -p "  Hotspot WiFi name (SSID)   [ARISE-Network]: " SSID
        SSID=${SSID:-ARISE-Network}

        read -p "  Hotspot password (8+ chars) [arise1234]:    " HOTSPOT_PASS
        HOTSPOT_PASS=${HOTSPOT_PASS:-arise1234}

        read -p "  IP address for this machine [10.42.0.1]:    " HOTSPOT_IP
        HOTSPOT_IP=${HOTSPOT_IP:-10.42.0.1}

        echo ""
        echo "Setting up hotspot '$SSID' on $WIFI_IFACE ..."

        # Remove any existing ARISE hotspot connection
        nmcli connection delete "ARISE-Hotspot" 2>/dev/null || true

        # Create hotspot connection
        nmcli connection add \
            type wifi \
            ifname "$WIFI_IFACE" \
            con-name "ARISE-Hotspot" \
            autoconnect yes \
            ssid "$SSID" > /dev/null

        # Configure: access point mode, static IP, WPA2 password
        nmcli connection modify "ARISE-Hotspot" \
            802-11-wireless.mode ap \
            802-11-wireless.band bg \
            ipv4.method shared \
            ipv4.addresses "$HOTSPOT_IP/24" \
            wifi-sec.key-mgmt wpa-psk \
            wifi-sec.psk "$HOTSPOT_PASS" > /dev/null

        # Start the hotspot
        nmcli connection up "ARISE-Hotspot" > /dev/null

        echo "Hotspot is live."

        # Store IP for summary
        FINAL_IP="$HOTSPOT_IP"
    fi
fi

# ── Done ──────────────────────────────────────────────────────────────────────
FINAL_IP=${FINAL_IP:-$(hostname -I | awk '{print $1}')}

echo ""
echo "════════════════════════════════════════════"
echo "  ARISE Installation Complete"
echo "════════════════════════════════════════════"
echo ""
if [ -n "$SSID" ]; then
echo "  WiFi name  : $SSID"
echo "  Password   : $HOTSPOT_PASS"
fi
echo "  ARISE URL  : http://$FINAL_IP/arise/"
echo "  Admin panel: http://$FINAL_IP/arise/admin/"
echo ""
echo "  Students: connect to WiFi '$SSID', then"
echo "  open browser → http://$FINAL_IP/arise/"
echo "════════════════════════════════════════════"
echo ""
echo "  If this machine was cloned from a disk image of another"
echo "  ARISE machine, also run:"
echo "    sudo bash first-boot-fix.sh"
echo "  to regenerate identity (machine-id, hostname, SSH host keys,"
echo "  cloud sync device_id) and rebind the hotspot to this machine's"
echo "  WiFi adapter."
echo ""
