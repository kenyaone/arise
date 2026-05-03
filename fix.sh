#!/bin/bash
# ============================================
# ARISE — Diagnose & Fix Apache
# Run from anywhere: sudo bash fix.sh
# ============================================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m'

if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}Run as root: sudo bash fix.sh${NC}"
    exit 1
fi

echo ""
echo "========================================"
echo " ARISE — Diagnose & Fix"
echo "========================================"
echo ""

# 1. Check if ARISE files exist
echo -e "${YELLOW}[1] Checking ARISE files...${NC}"
if [ -f "/var/www/arise/public/index.php" ]; then
    echo -e "  ${GREEN}✅ ARISE files found at /var/www/arise/${NC}"
else
    echo -e "  ${RED}❌ ARISE files NOT found at /var/www/arise/${NC}"
    echo "  Run the installer first: cd arise && sudo bash setup/install.sh"
    exit 1
fi

# 2. Check Apache installed
echo -e "${YELLOW}[2] Checking Apache...${NC}"
if command -v apache2 &>/dev/null; then
    echo -e "  ${GREEN}✅ Apache installed${NC}"
else
    echo -e "  ${RED}❌ Apache not installed. Installing...${NC}"
    apt update -y && apt install -y apache2 php php-sqlite3 php-json php-zip php-mbstring php-gd libapache2-mod-php
fi

# 3. Disable ALL other sites
echo -e "${YELLOW}[3] Disabling conflicting sites...${NC}"
for site in /etc/apache2/sites-enabled/*.conf; do
    sitename=$(basename "$site")
    if [ "$sitename" != "arise.conf" ]; then
        echo "  Disabling: $sitename"
        a2dissite "$sitename" 2>/dev/null
    fi
done

# 4. Write fresh arise.conf
echo -e "${YELLOW}[4] Writing fresh Apache config...${NC}"
cat > /etc/apache2/sites-available/arise.conf << 'APACHECONF'
<VirtualHost *:80>
    ServerName arise.local
    DocumentRoot /var/www/arise/public

    <Directory /var/www/arise/public>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>

    Alias /datapost /var/www/arise/datapost
    <Directory /var/www/arise/datapost>
        AllowOverride All
        Require all granted
    </Directory>

    Alias /admin /var/www/arise/admin
    <Directory /var/www/arise/admin>
        AllowOverride All
        Require all granted
    </Directory>

    Alias /logos /var/www/arise/data/uploads/logos
    <Directory /var/www/arise/data/uploads/logos>
        Require all granted
        Options -Indexes
    </Directory>

    Alias /uploads /var/www/arise/data/uploads
    <Directory /var/www/arise/data/uploads>
        Require all granted
        Options -Indexes
    </Directory>

    <Directory /var/www/arise/data>
        Require all denied
    </Directory>

    <Directory /var/www/arise/data/uploads>
        Require all granted
    </Directory>

    <Directory /var/www/arise/includes>
        Require all denied
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/arise_error.log
    CustomLog ${APACHE_LOG_DIR}/arise_access.log combined
</VirtualHost>
APACHECONF
echo -e "  ${GREEN}✅ arise.conf written${NC}"

# 5. Enable ARISE site and required modules
echo -e "${YELLOW}[5] Enabling ARISE site...${NC}"
a2enmod rewrite 2>/dev/null
a2enmod headers 2>/dev/null
a2ensite arise.conf 2>/dev/null
echo -e "  ${GREEN}✅ arise.conf enabled${NC}"

# 6. Configure PHP for large uploads
echo -e "${YELLOW}[6] Configuring PHP uploads...${NC}"
PHP_CONF_DIR=$(find /etc/php -type d -name "conf.d" -path "*/apache2/*" 2>/dev/null | head -1)
if [ -n "$PHP_CONF_DIR" ]; then
    cat > "$PHP_CONF_DIR/99-arise.ini" << 'PHPINI'
upload_max_filesize = 500M
post_max_size = 512M
max_execution_time = 300
memory_limit = 256M
PHPINI
    echo -e "  ${GREEN}✅ PHP configured (500MB uploads)${NC}"
else
    echo -e "  ${YELLOW}⚠️  Could not find PHP apache2 conf.d, skipping${NC}"
fi

# 7. Fix permissions
echo -e "${YELLOW}[7] Fixing permissions...${NC}"
chown -R www-data:www-data /var/www/arise
chmod -R 755 /var/www/arise
chmod -R 775 /var/www/arise/data
echo -e "  ${GREEN}✅ Permissions set${NC}"

# 8. Test config
echo -e "${YELLOW}[8] Testing Apache config...${NC}"
CONFIG_TEST=$(apache2ctl configtest 2>&1)
if echo "$CONFIG_TEST" | grep -q "Syntax OK"; then
    echo -e "  ${GREEN}✅ Config syntax OK${NC}"
else
    echo -e "  ${RED}❌ Config error:${NC}"
    echo "  $CONFIG_TEST"
    exit 1
fi

# 9. Restart Apache
echo -e "${YELLOW}[9] Restarting Apache...${NC}"
systemctl restart apache2
systemctl enable apache2

if systemctl is-active --quiet apache2; then
    echo -e "  ${GREEN}✅ Apache running${NC}"
else
    echo -e "  ${RED}❌ Apache failed to start. Check: sudo journalctl -u apache2${NC}"
    exit 1
fi

# 10. Verify
echo -e "${YELLOW}[10] Verifying...${NC}"
SERVER_IP=$(hostname -I | awk '{print $1}')
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://localhost/" 2>/dev/null)
if [ "$HTTP_CODE" = "200" ]; then
    echo -e "  ${GREEN}✅ Site responding (HTTP 200)${NC}"
else
    echo -e "  ${YELLOW}⚠️  HTTP response: $HTTP_CODE (may still work, check browser)${NC}"
fi

echo ""
echo "========================================"
echo -e "${GREEN} ✅ DONE!${NC}"
echo "========================================"
echo ""
echo " 🌐 Student Site:  http://$SERVER_IP/"
echo " ⚙️  Admin Panel:   http://$SERVER_IP/admin/"
echo " 📡 DataPost:      http://$SERVER_IP/datapost/"
echo ""
echo " 🔑 Login: admin / arise2026"
echo ""
echo "========================================"
