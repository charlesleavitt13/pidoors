#!/bin/bash
#
# PiDoors Access Control System - Uninstallation Script
# Removes server, door controller, or both with optional database wipe
#
# Usage: sudo ./uninstall.sh
#

set -euo pipefail

# ──────────────────────────────────────────────
# Colors and helpers
# ──────────────────────────────────────────────

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

INSTALL_DIR="/opt/pidoors"
WEB_ROOT="/var/www/pidoors"
WEB_UI_ROOT="/var/www/pidoors-ui"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
fail() { echo -e "  ${RED}✗${NC} $1"; }
warn() { echo -e "  ${YELLOW}!${NC} $1"; }
info() { echo -e "  ${BLUE}→${NC} $1"; }

step() {
    echo
    echo -e "${BLUE}─── $1 ───${NC}"
    echo
}

# ──────────────────────────────────────────────
# Banner
# ──────────────────────────────────────────────

clear
echo
echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${RED}  PiDoors Access Control System${NC}"
echo -e "${RED}  Uninstallation Script${NC}"
echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

# ──────────────────────────────────────────────
# Pre-flight checks
# ──────────────────────────────────────────────

step "Pre-flight checks"

# Root check
if [ "$EUID" -ne 0 ]; then
    fail "This script must be run as root: ${BOLD}sudo ./uninstall.sh${NC}"
    exit 1
fi
ok "Running as root"

# ──────────────────────────────────────────────
# Uninstallation type
# ──────────────────────────────────────────────

step "Uninstallation type"

echo "  What would you like to uninstall?"
echo
echo -e "    ${BOLD}1) Server${NC}          - Web interface + Database"
echo "                       Remove from the central Pi"
echo
echo -e "    ${BOLD}2) Door Controller${NC} - GPIO + Card reader"
echo "                       Remove from a door Pi"
echo
echo -e "    ${BOLD}3) Both${NC}            - Server + Door Controller"
echo "                       Remove everything"
echo
read -p "  Enter choice [1-3]: " UNINSTALL_TYPE

case $UNINSTALL_TYPE in
    1) UNINSTALL_SERVER=true;  UNINSTALL_DOOR=false ;;
    2) UNINSTALL_SERVER=false; UNINSTALL_DOOR=true  ;;
    3) UNINSTALL_SERVER=true;  UNINSTALL_DOOR=true  ;;
    *) fail "Invalid choice"; exit 1 ;;
esac

# ──────────────────────────────────────────────
# Database wipe confirmation
# ──────────────────────────────────────────────

WIPE_DATABASE=false
if [ "$UNINSTALL_SERVER" = true ]; then
    step "Database backup and wipe"

    echo "  ${YELLOW}WARNING: This will delete all PiDoors data!${NC}"
    echo
    echo "  Would you like to:"
    echo "    1) Keep the database (can reinstall on top)"
    echo "    2) Backup and wipe the database (recommended for clean install)"
    echo "    3) Just wipe without backup (delete all data)"
    echo
    read -p "  Enter choice [1-3]: " DB_CHOICE

    case $DB_CHOICE in
        2)
            WIPE_DATABASE=true
            BACKUP_DATABASE=true
            ;;
        3)
            WIPE_DATABASE=true
            BACKUP_DATABASE=false
            ;;
        *)
            WIPE_DATABASE=false
            BACKUP_DATABASE=false
            ;;
    esac

    if [ "$WIPE_DATABASE" = true ] && [ "$BACKUP_DATABASE" = true ]; then
        echo
        info "Database will be backed up before removal"
    elif [ "$WIPE_DATABASE" = true ]; then
        echo
        warn "Database will be deleted WITHOUT backup"
        read -p "  Are you absolutely sure? Type 'yes' to confirm: " CONFIRM
        if [ "$CONFIRM" != "yes" ]; then
            info "Aborted. Database will not be wiped."
            WIPE_DATABASE=false
        fi
    fi
fi

# ──────────────────────────────────────────────
# Removal confirmation
# ──────────────────────────────────────────────

step "Confirm removal"

if [ "$UNINSTALL_SERVER" = true ]; then
    echo -e "  ${RED}Server components will be removed:${NC}"
    echo "    • Web interface ($WEB_ROOT)"
    echo "    • React UI ($WEB_UI_ROOT)"
    echo "    • Nginx configuration"
    echo "    • PHP configuration"
    [ "$WIPE_DATABASE" = true ] && echo "    • Databases (users, access)" || echo "    • Databases will be kept"
    [ "$WIPE_DATABASE" = true ] && echo "    • Cron jobs" || echo "    • Cron jobs will be kept"
    echo
fi

if [ "$UNINSTALL_DOOR" = true ]; then
    echo -e "  ${RED}Door Controller components will be removed:${NC}"
    echo "    • Door controller service ($INSTALL_DIR)"
    echo "    • Python virtual environment"
    echo "    • Config files"
    echo "    • Systemd service"
    echo
fi

echo -e "  ${YELLOW}Shared components:${NC}"
echo "    • CA certificates and keys"
echo "    • Firewall rules"
echo "    • Log rotation configuration"
echo "    • pidoors user (if no components remain)"
echo

read -p "  ${RED}Are you absolutely sure? Type 'yes' to continue: ${NC}" CONFIRM
if [ "$CONFIRM" != "yes" ]; then
    echo "  Uninstallation cancelled."
    exit 0
fi

# ============================================================
# BACKUP
# ============================================================

if [ "$BACKUP_DATABASE" = true ]; then
    step "Backing up databases"

    BACKUP_DIR="/var/backups/pidoors"
    mkdir -p "$BACKUP_DIR"
    DATE=$(date +%Y%m%d_%H%M%S)

    info "Backing up databases..."
    if command -v mysqldump > /dev/null 2>&1; then
        if mysql -u root -e "SELECT 1" > /dev/null 2>&1; then
            mysqldump --all-databases > "$BACKUP_DIR/full_backup_$DATE.sql" 2>/dev/null && \
                ok "Full database backup: $BACKUP_DIR/full_backup_$DATE.sql" || \
                warn "Database backup failed (might need password or permissions)"
        else
            warn "Cannot connect to MySQL, skipping backup"
        fi
    else
        warn "mysqldump not found, skipping database backup"
    fi
fi

# ============================================================
# SERVER UNINSTALLATION
# ============================================================

if [ "$UNINSTALL_SERVER" = true ]; then

    step "Uninstalling Server components"

    # Stop services
    info "Stopping services..."
    systemctl stop nginx 2>/dev/null || true
    systemctl stop "php"* 2>/dev/null || true
    ok "Services stopped"

    # Remove web files
    if [ -d "$WEB_ROOT" ]; then
        info "Removing web interface ($WEB_ROOT)..."
        rm -rf "$WEB_ROOT"
        ok "Web interface removed"
    fi

    if [ -d "$WEB_UI_ROOT" ]; then
        info "Removing React UI ($WEB_UI_ROOT)..."
        rm -rf "$WEB_UI_ROOT"
        ok "React UI removed"
    fi

    # Remove Nginx configuration
    info "Removing Nginx configuration..."
    rm -f /etc/nginx/sites-available/pidoors
    rm -f /etc/nginx/sites-enabled/pidoors
    rm -f /usr/local/sbin/pidoors-nginx-upgrade
    rm -f /etc/sudoers.d/pidoors-nginx
    ok "Nginx configuration removed"

    # Remove custom PHP session path
    info "Removing custom PHP session path..."
    rm -rf /var/lib/php/pidoors-sessions
    ok "PHP session path removed"

    # Wipe databases if requested
    if [ "$WIPE_DATABASE" = true ]; then
        step "Wiping databases"

        # Try socket auth first, then ask for password
        if mysql -u root -e "SELECT 1" > /dev/null 2>&1; then
            MYSQL_ROOT_PASS=""
            info "Dropping databases..."
            mysql -u root -e "DROP DATABASE IF EXISTS users;" 2>/dev/null || true
            mysql -u root -e "DROP DATABASE IF EXISTS access;" 2>/dev/null || true
            mysql -u root -e "DROP USER IF EXISTS 'pidoors'@'localhost';" 2>/dev/null || true
            mysql -u root -e "DROP USER IF EXISTS 'pidoors'@'127.0.0.1';" 2>/dev/null || true
            mysql -u root -e "DROP USER IF EXISTS 'pidoors'@'%';" 2>/dev/null || true
            mysql -u root -e "DROP USER IF EXISTS 'pidoors'@'0.0.0.0/255.255.255.0';" 2>/dev/null || true
            # Try with LAN subnet pattern
            MYSQL_PWD="" mysql -u root -e "SELECT 1" > /dev/null 2>&1 && \
                mysql -u root -e "FLUSH PRIVILEGES;" 2>/dev/null || true
            ok "Databases and users dropped"
        else
            warn "Could not connect to MySQL as root, databases not wiped"
        fi
    fi
    systemctl stop mariadb 2>/dev/null || true
    apt purge mariadb-server -y 2>/dev/null || true


    # Remove backup script
    rm -f /usr/local/bin/pidoors-backup.sh
    ok "Backup script removed"

    # Remove cron job
    rm -f /etc/cron.d/pidoors
    ok "Cron job removed"

    # Remove log rotation
    rm -f /etc/logrotate.d/pidoors
    ok "Log rotation configuration removed"

    rm -f /etc/mysql/mariadb.conf.d/90-pidoors-sd.cnf
    rm -f /etc/systemd/journald.conf.d/pidoors.conf
    systemctl restart systemd-journald 2>/dev/null || true

fi

# ============================================================
# DOOR CONTROLLER UNINSTALLATION
# ============================================================

if [ "$UNINSTALL_DOOR" = true ]; then

    step "Uninstalling Door Controller components"

    # Stop service
    if systemctl is-enabled pidoors 2>/dev/null; then
        info "Stopping door controller service..."
        systemctl stop pidoors 2>/dev/null || true
        systemctl disable pidoors 2>/dev/null || true
        ok "Door controller service stopped and disabled"
    fi

    # Remove installation directory
    if [ -d "$INSTALL_DIR" ]; then
        info "Removing controller installation ($INSTALL_DIR)..."
        rm -rf "$INSTALL_DIR"
        ok "Controller installation removed"
    fi

    # Remove systemd service
    rm -f /etc/systemd/system/pidoors.service
    systemctl daemon-reload 2>/dev/null || true
    ok "Systemd service removed"

    # Remove sudoers entry
    rm -f /etc/sudoers.d/pidoors-update
    ok "Sudoers entry removed"

fi

# ============================================================
# SHARED: Certificates, Firewall, User
# ============================================================

step "Cleaning up shared components"

# Remove CA certificates and keys
info "Removing CA certificates and keys..."
rm -f /etc/mysql/ssl/ca-key.pem
rm -f /etc/mysql/ssl/ca.pem
rm -f /etc/mysql/ssl/server-*.pem
rm -f /etc/mysql/ssl/ca.srl
rm -f /etc/ssl/certs/pidoors.crt
rm -f /etc/ssl/private/pidoors.key
ok "Certificates and keys removed"

# Remove firewall rules (best effort)
if command -v ufw > /dev/null 2>&1; then
    info "Removing firewall rules..."
    ufw delete allow 80/tcp 2>/dev/null || true
    ufw delete allow 443/tcp 2>/dev/null || true
    ufw delete allow 3306/tcp 2>/dev/null || true
    ufw delete allow 8443/tcp 2>/dev/null || true
    ok "Firewall rules removed"
fi

# Remove pidoors user if no components remain
if [ "$UNINSTALL_SERVER" = true ] || [ "$UNINSTALL_DOOR" = true ]; then
    if id -u pidoors > /dev/null 2>&1; then
        info "Removing pidoors user..."
        userdel -r pidoors 2>/dev/null || true
        ok "pidoors user removed"
    fi
fi

# Remove log files
info "Removing log files..."
rm -f /var/log/pidoors.log*
find /var/log/nginx -name "*pidoors*" -delete 2>/dev/null || true
ok "Log files removed"

rm -f /etc/systemd/journald.conf.d/pidoors.conf
systemctl restart systemd-journald 2>/dev/null || true

# ============================================================
# Summary
# ============================================================

echo
echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo -e "${RED}  Uninstallation Complete!${NC}"
echo -e "${RED}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
echo

if [ "$UNINSTALL_SERVER" = true ]; then
    echo -e "  ${BOLD}Server${NC}"
    echo -e "    ✗ Web interface removed"
    echo -e "    ✗ React UI removed"
    echo -e "    ✗ Nginx configuration removed"
    if [ "$WIPE_DATABASE" = true ]; then
        echo -e "    ✗ Databases wiped"
    else
        echo -e "    ℹ Databases preserved (can reinstall on top)"
    fi
    echo
fi

if [ "$UNINSTALL_DOOR" = true ]; then
    echo -e "  ${BOLD}Door Controller${NC}"
    echo -e "    ✗ Controller service removed"
    echo -e "    ✗ Installation directory removed"
    echo -e "    ✗ Systemd service removed"
    echo
fi

echo -e "  ${BOLD}Shared Components${NC}"
echo -e "    ✗ CA certificates and keys removed"
echo -e "    ✗ Firewall rules removed"
echo -e "    ✗ pidoors user removed"
echo

if [ "$BACKUP_DATABASE" = true ]; then
    echo -e "  ${BOLD}Backup${NC}"
    echo -e "    ✓ Database backup saved to /var/backups/pidoors/"
    echo
fi

echo -e "  ${BOLD}Next steps:${NC}"
echo "    • You can now run ./install.sh again for a fresh installation"
if [ "$WIPE_DATABASE" = false ] && [ "$UNINSTALL_SERVER" = true ]; then
    echo "    • Database is still present - reinstall will reuse it"
fi
echo

ok "Uninstallation finished successfully"
