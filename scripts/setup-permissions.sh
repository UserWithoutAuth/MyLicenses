#!/bin/bash

###############################################################################
# License Server - File Permissions Setup Script
#
# Sets secure file permissions optimized for Hostinger shared hosting
#
# Usage: bash scripts/setup-permissions.sh
#
# IMPORTANT: Adjust BASE_PATH if your directory structure is different
###############################################################################

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color
BOLD='\033[1m'

# Base directory (adjust for your Hostinger path)
# Example: /home/username/domains/yourdomain.com
BASE_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo -e "${CYAN}${BOLD}"
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║     LICENSE SERVER - PERMISSIONS SETUP SCRIPT             ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "${BLUE}Base Path: ${BOLD}$BASE_PATH${NC}\n"

# Check if running as correct user
CURRENT_USER=$(whoami)
echo -e "${BLUE}Current User: ${BOLD}$CURRENT_USER${NC}\n"

# Confirm before proceeding
echo -e "${YELLOW}⚠  This will set file permissions for the license server.${NC}"
echo -e "${YELLOW}   Make sure you're running this from the correct directory.${NC}\n"
read -p "Continue? (yes/no): " CONFIRM

if [ "$CONFIRM" != "yes" ]; then
    echo -e "\n${GREEN}✓ Operation cancelled.${NC}\n"
    exit 0
fi

echo -e "\n${CYAN}${BOLD}Setting up file permissions...${NC}\n"

# ============================================
# STEP 1: Set default permissions
# ============================================
echo -e "${BLUE}[1/8] Setting default permissions...${NC}"

# Directories: 755 (rwxr-xr-x)
find "$BASE_PATH" -type d -exec chmod 755 {} \; 2>/dev/null
echo -e "${GREEN}  ✓ Directories set to 755${NC}"

# Files: 644 (rw-r--r--)
find "$BASE_PATH" -type f -exec chmod 644 {} \; 2>/dev/null
echo -e "${GREEN}  ✓ Files set to 644${NC}"

# ============================================
# STEP 2: Public HTML (web-accessible)
# ============================================
echo -e "\n${BLUE}[2/8] Configuring public_html...${NC}"

chmod 755 "$BASE_PATH/public_html"
find "$BASE_PATH/public_html" -type d -exec chmod 755 {} \; 2>/dev/null
find "$BASE_PATH/public_html" -type f -exec chmod 644 {} \; 2>/dev/null
echo -e "${GREEN}  ✓ public_html configured${NC}"

# ============================================
# STEP 3: Secure sensitive files (600 = rw-------)
# ============================================
echo -e "\n${BLUE}[3/8] Securing sensitive configuration files...${NC}"

if [ -f "$BASE_PATH/.env" ]; then
    chmod 600 "$BASE_PATH/.env"
    echo -e "${GREEN}  ✓ .env secured (600)${NC}"
else
    echo -e "${YELLOW}  ⚠ .env not found (create from .env.example)${NC}"
fi

if [ -f "$BASE_PATH/config/database.php" ]; then
    chmod 600 "$BASE_PATH/config/database.php"
    echo -e "${GREEN}  ✓ database.php secured (600)${NC}"
fi

# ============================================
# STEP 4: Cryptographic keys (400 = r--------)
# ============================================
echo -e "\n${BLUE}[4/8] Securing cryptographic keys...${NC}"

if [ -d "$BASE_PATH/storage/keys" ]; then
    chmod 700 "$BASE_PATH/storage/keys"

    if [ -f "$BASE_PATH/storage/keys/server_private.key" ]; then
        chmod 400 "$BASE_PATH/storage/keys/server_private.key"
        echo -e "${GREEN}  ✓ Private key secured (400 - read-only for owner)${NC}"
    else
        echo -e "${YELLOW}  ⚠ Private key not found (run: php scripts/generate-keypair.php)${NC}"
    fi

    if [ -f "$BASE_PATH/storage/keys/server_public.key" ]; then
        chmod 444 "$BASE_PATH/storage/keys/server_public.key"
        echo -e "${GREEN}  ✓ Public key secured (444 - read-only for all)${NC}"
    fi
else
    echo -e "${YELLOW}  ⚠ Keys directory not found${NC}"
fi

# ============================================
# STEP 5: Writable directories
# ============================================
echo -e "\n${BLUE}[5/8] Configuring writable storage directories...${NC}"

# Storage directories need to be writable
WRITABLE_DIRS=(
    "storage"
    "storage/logs"
    "storage/cache"
    "storage/sessions"
    "storage/backups"
    "storage/temp"
    "public_html/uploads"
)

for dir in "${WRITABLE_DIRS[@]}"; do
    if [ -d "$BASE_PATH/$dir" ]; then
        chmod 755 "$BASE_PATH/$dir"
        echo -e "${GREEN}  ✓ $dir (755 - writable)${NC}"
    fi
done

# ============================================
# STEP 6: Secure log and backup files
# ============================================
echo -e "\n${BLUE}[6/8] Securing logs and backups...${NC}"

# Logs: 600
if [ -d "$BASE_PATH/storage/logs" ]; then
    find "$BASE_PATH/storage/logs" -type f -exec chmod 600 {} \; 2>/dev/null
    echo -e "${GREEN}  ✓ Log files secured (600)${NC}"
fi

# Backups: 600
if [ -d "$BASE_PATH/storage/backups" ]; then
    find "$BASE_PATH/storage/backups" -type f -exec chmod 600 {} \; 2>/dev/null
    echo -e "${GREEN}  ✓ Backup files secured (600)${NC}"
fi

# Sessions: 600
if [ -d "$BASE_PATH/storage/sessions" ]; then
    find "$BASE_PATH/storage/sessions" -type f -exec chmod 600 {} \; 2>/dev/null
    echo -e "${GREEN}  ✓ Session files secured (600)${NC}"
fi

# ============================================
# STEP 7: Make scripts executable
# ============================================
echo -e "\n${BLUE}[7/8] Making scripts executable...${NC}"

if [ -d "$BASE_PATH/scripts" ]; then
    find "$BASE_PATH/scripts" -name "*.sh" -exec chmod 750 {} \; 2>/dev/null
    echo -e "${GREEN}  ✓ Shell scripts are executable (750)${NC}"
fi

# ============================================
# STEP 8: Verify .htaccess files exist
# ============================================
echo -e "\n${BLUE}[8/8] Verifying .htaccess protection...${NC}"

HTACCESS_CHECKS=(
    "public_html/.htaccess"
    "public_html/uploads/.htaccess"
    "storage/keys/.htaccess"
    "storage/backups/.htaccess"
    "storage/logs/.htaccess"
)

for htaccess in "${HTACCESS_CHECKS[@]}"; do
    if [ -f "$BASE_PATH/$htaccess" ]; then
        echo -e "${GREEN}  ✓ $htaccess exists${NC}"
    else
        echo -e "${RED}  ✗ $htaccess MISSING!${NC}"
    fi
done

# ============================================
# Summary and verification
# ============================================
echo -e "\n${CYAN}${BOLD}"
echo "╔═══════════════════════════════════════════════════════════╗"
echo "║              PERMISSIONS VERIFICATION                     ║"
echo "╚═══════════════════════════════════════════════════════════╝"
echo -e "${NC}"

echo -e "${YELLOW}Critical files to verify:${NC}\n"

if [ -f "$BASE_PATH/.env" ]; then
    ls -lh "$BASE_PATH/.env" | awk '{print "  .env: " $1 " " $3 ":" $4}'
fi

if [ -f "$BASE_PATH/storage/keys/server_private.key" ]; then
    ls -lh "$BASE_PATH/storage/keys/server_private.key" | awk '{print "  Private Key: " $1 " " $3 ":" $4}'
fi

if [ -f "$BASE_PATH/storage/keys/server_public.key" ]; then
    ls -lh "$BASE_PATH/storage/keys/server_public.key" | awk '{print "  Public Key: " $1 " " $3 ":" $4}'
fi

echo -e "\n${GREEN}${BOLD}✓ PERMISSIONS SETUP COMPLETE!${NC}\n"

echo -e "${YELLOW}Important Security Checks:${NC}"
echo -e "  ${BOLD}1.${NC} Private key should be: ${GREEN}400 (r--------)${NC}"
echo -e "  ${BOLD}2.${NC} .env file should be: ${GREEN}600 (rw-------)${NC}"
echo -e "  ${BOLD}3.${NC} Verify .htaccess files are in place"
echo -e "  ${BOLD}4.${NC} Test that storage/keys/ is NOT web-accessible\n"

echo -e "${CYAN}Test key directory protection:${NC}"
echo -e "  curl https://yourdomain.com/storage/keys/server_private.key"
echo -e "  ${YELLOW}(Should return 403 Forbidden)${NC}\n"

echo -e "${CYAN}Next steps:${NC}"
echo -e "  1. Copy .env.example to .env and configure"
echo -e "  2. Generate keypairs: ${BOLD}php scripts/generate-keypair.php${NC}"
echo -e "  3. Install dependencies: ${BOLD}composer install${NC}"
echo -e "  4. Run database migrations"
echo -e "  5. Test the installation\n"
