# 🔐 PHP License Server

A secure, production-ready PHP license management system for issuing and managing licenses for C#, C++, Rust, and Python applications. Optimized for Hostinger shared hosting with enterprise-grade security features.

## 📋 Features

### Core License Management
- ✅ **Online & Offline Activation** - Support for both connected and air-gapped environments
- ✅ **Hardware Fingerprinting** - Bind licenses to specific machines (CPU, MAC, Disk Serial, Motherboard)
- ✅ **RSA 4096-bit Encryption** - Military-grade cryptographic security
- ✅ **License Types** - Standard, Trial, Floating, Node-Locked
- ✅ **Custom Fields** - Product-specific metadata and configurations
- ✅ **Expiry Management** - Grace periods, custom expiry messages
- ✅ **License Transfer** - Legitimate machine changes with approval

### Security Features
- 🔒 **2FA/TOTP Authentication** - Two-factor authentication for admin panel
- 🔒 **RBAC** - Role-based access control (Super Admin, Admin, Support, Viewer)
- 🔒 **IP Whitelisting** - Restrict admin access by IP/CIDR
- 🔒 **Rate Limiting** - DDoS protection and abuse prevention
- 🔒 **Audit Logging** - Complete trail of all operations
- 🔒 **Session Security** - Device tracking, timeouts, fingerprinting
- 🔒 **Password Policies** - Complexity, expiry, breach checking

### Monitoring & Analytics
- 📊 **Ping/Heartbeat System** - Real-time license usage monitoring
- 📊 **Analytics Dashboard** - Active licenses, expiry forecasts, usage patterns
- 📊 **Webhook Support** - Real-time event notifications
- 📊 **License Compliance** - Detect piracy patterns and anomalies

### Business Features
- 💼 **License Tiers** - Basic, Pro, Enterprise plans
- 💼 **Subscription Management** - Auto-renewal, billing cycles
- 💼 **Payment Integration** - Stripe/PayPal support
- 💼 **Reseller Portal** - Partner management with commissions
- 💼 **Customer Portal** - Self-service license management
- 💼 **Bulk Operations** - Generate, revoke, extend licenses in bulk

### Technical Features
- ⚡ **RESTful API** - Complete API for integrations
- ⚡ **Automated Backups** - Multiple destinations (S3, SFTP, Dropbox)
- ⚡ **Email/SMS Notifications** - Expiry warnings, activation alerts
- ⚡ **Multi-tenancy Ready** - SaaS deployment support
- ⚡ **Caching** - Redis/File-based caching for performance

## 🚀 Quick Start

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
nano .env

# 3. Generate keypairs
php scripts/generate-keypair.php

# 4. Set permissions
bash scripts/setup-permissions.sh

# 5. Import database
mysql -u user -p database < database/migrations/001_create_tables.sql

# 6. Test installation
curl https://yourdomain.com/api/v1/health
```

## 📚 Documentation

See the full [Installation Guide](#-installation) below for detailed setup instructions.

---

## 🏗️ Architecture

### Secure Directory Structure (Hostinger-Optimized)

```
/home/username/domains/yourdomain.com/
│
├── public_html/                    ← ONLY PUBLIC FILES
│   ├── index.php                   ← Entry point
│   ├── .htaccess                   ← Security rules
│   ├── assets/                     ← CSS, JS, images
│   └── uploads/                    ← User uploads (PHP disabled)
│
├── app/                            ← APPLICATION CODE (PROTECTED)
│   ├── Controllers/
│   ├── Models/
│   ├── Services/
│   ├── Middleware/
│   ├── Routes/
│   └── Helpers/
│
├── config/                         ← CONFIGURATION (PROTECTED)
├── storage/                        ← STORAGE (PROTECTED)
│   ├── keys/                       ← RSA keypairs (400 perms)
│   ├── logs/                       ← Application logs (600 perms)
│   ├── backups/                    ← Backups (600 perms)
│   ├── cache/
│   └── sessions/
│
├── database/
│   └── migrations/
│
├── scripts/
│   ├── generate-keypair.php        ← Key generation
│   └── setup-permissions.sh        ← Permission setup
│
├── .env                            ← Environment config (600 perms)
├── composer.json
└── README.md
```

## 🚀 Installation

### Prerequisites

- **PHP 7.4+** (8.0+ recommended)
- **MySQL 5.7+** or **MariaDB 10.3+**
- **Composer** (PHP package manager)
- **OpenSSL** extension
- **SSH access** (for initial setup)

### Step 1: Clone/Upload Files

```bash
# If using Git
git clone https://github.com/yourusername/license-server.git
cd license-server

# Or upload via FTP/SFTP to your Hostinger directory
```

### Step 2: Install Dependencies

```bash
composer install --no-dev --optimize-autoloader
```

### Step 3: Configure Environment

```bash
# Copy example environment file
cp .env.example .env

# Edit .env with your settings
nano .env
```

**Key settings to configure:**
```env
APP_KEY=                          # Generate: php -r "echo bin2hex(random_bytes(32));"
DB_HOST=localhost
DB_DATABASE=u123456789_licenses
DB_USERNAME=u123456789_admin
DB_PASSWORD=your_password

# Update paths to match your Hostinger directory
SERVER_PRIVATE_KEY=/home/username/domains/yourdomain.com/storage/keys/server_private.key
SERVER_PUBLIC_KEY=/home/username/domains/yourdomain.com/storage/keys/server_public.key
```

### Step 4: Generate RSA Keypairs

```bash
php scripts/generate-keypair.php
```

Follow the prompts to:
1. Set a strong passphrase (recommended)
2. Keys will be saved to `storage/keys/`
3. **IMPORTANT:** Delete the script after use: `rm scripts/generate-keypair.php`

### Step 5: Set File Permissions

```bash
bash scripts/setup-permissions.sh
```

This sets secure permissions:
- **400** for private keys (read-only owner)
- **600** for .env and sensitive files
- **755** for directories
- **644** for regular files

### Step 6: Database Setup

```bash
# Import the database schema
mysql -u username -p database_name < database/migrations/001_create_tables.sql
```

Or use phpMyAdmin:
1. Login to your Hostinger phpMyAdmin
2. Select your database
3. Import `database/migrations/001_create_tables.sql`

### Step 7: Verify Installation

```bash
# Test health endpoint
curl https://yourdomain.com/api/v1/health

# Should return:
{
  "status": "ok",
  "timestamp": 1234567890,
  "version": "1.0.0",
  "service": "License Server"
}
```

### Step 8: Security Verification

```bash
# Test that keys are NOT accessible
curl https://yourdomain.com/storage/keys/server_private.key
# Should return: 403 Forbidden

# Verify file permissions
ls -la storage/keys/
# server_private.key should be: -r-------- (400)
```

## 🔑 API Usage

### Activate License (Online)

```bash
POST /api/v1/licenses/activate
Content-Type: application/json

{
  "license_key": "XXXXX-XXXXX-XXXXX-XXXXX-XXXXX",
  "machine_fingerprint": "sha256_hash_of_hardware",
  "public_key": "-----BEGIN PUBLIC KEY-----\n...",
  "machine_info": {
    "name": "DEV-MACHINE-01",
    "os": "Windows 11 Pro",
    "cpu": "Intel i7-12700K"
  }
}
```

**Response:**
```json
{
  "success": true,
  "activation_token": "...",
  "license": {
    "key": "XXXXX-XXXXX-XXXXX-XXXXX-XXXXX",
    "product": "MyApp Pro",
    "expires_at": "2025-01-22 12:00:00",
    "features": ["feature1", "feature2"]
  },
  "signature": "base64_encoded_signature"
}
```

## 🛡️ Security Best Practices

### 1. Private Key Protection
- ✅ Store with **400 permissions** (read-only owner)
- ✅ Use strong passphrase encryption
- ✅ Never commit to version control
- ✅ Backup to encrypted offline storage

### 2. Environment Configuration
- ✅ `.env` with **600 permissions**
- ✅ Never expose in web root
- ✅ Use strong APP_KEY (32+ bytes)

### 3. Admin Panel Security
- ✅ Enable 2FA/TOTP for all admins
- ✅ Use IP whitelisting
- ✅ Strong password policy (12+ chars)
- ✅ Session timeouts (15-30 minutes)

### 4. HTTPS/SSL
- ✅ Force HTTPS (uncomment in .htaccess)
- ✅ Use HSTS headers
- ✅ Valid SSL certificate (Let's Encrypt)

## 🐛 Troubleshooting

### Issue: "Dependencies not installed"
**Solution:** Run `composer install`

### Issue: "Configuration missing"
**Solution:** Copy `.env.example` to `.env` and configure

### Issue: 500 Internal Server Error
**Check:**
1. PHP error log: `storage/logs/php_errors.log`
2. Application log: `storage/logs/app.log`
3. File permissions (especially storage/)
4. Database connection in `.env`

## ⚠️ Important Notes

1. **Delete generation script after use:** `rm scripts/generate-keypair.php`
2. **Backup your private key** to encrypted offline storage
3. **Never commit `.env` or `storage/keys/`** to version control
4. **Enable HTTPS** before production use
5. **Review audit logs** regularly for suspicious activity

---

**Version:** 1.0.0
**Last Updated:** 2026-01-22
**PHP Version:** 8.0+
**Database:** MySQL 5.7+ / MariaDB 10.3+
