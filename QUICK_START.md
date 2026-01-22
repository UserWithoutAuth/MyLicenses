# 🚀 Quick Start Guide - PHP License Server

**Ready to Use:** Core License System + Admin Panel
**Time to Deploy:** ~15 minutes

---

## ✅ What's Working Now

### **Complete Systems (Production-Ready)**

1. **License Management** ✅
   - Generate unique license keys
   - Online activation (hardware-bound)
   - Offline activation (air-gapped)
   - License validation
   - Transfer between machines
   - Revocation
   - Expiry with grace periods

2. **Admin Panel** ✅
   - Secure login with 2FA
   - Dashboard with statistics
   - License management interface
   - Role-based access control
   - Audit logging
   - Session management

3. **Security** ✅
   - RSA 4096-bit encryption
   - Hardware fingerprinting
   - Rate limiting
   - IP whitelisting
   - CSRF/XSS protection
   - Audit trails

4. **API** ✅
   - Complete REST API
   - 8 endpoints
   - JSON responses
   - Error handling

---

## 📦 Installation (15 Minutes)

### Step 1: Install Dependencies (2 min)

```bash
composer install --no-dev --optimize-autoloader
```

### Step 2: Configure Environment (3 min)

```bash
# Copy environment file
cp .env.example .env

# Edit configuration
nano .env
```

**Required settings:**
```env
APP_KEY=                    # Generate: php -r "echo bin2hex(random_bytes(32));"
DB_HOST=localhost
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password

# Update paths for Hostinger
SERVER_PRIVATE_KEY=/home/username/domains/yourdomain.com/storage/keys/server_private.key
SERVER_PUBLIC_KEY=/home/username/domains/yourdomain.com/storage/keys/server_public.key
```

### Step 3: Generate Security Keys (2 min)

```bash
php scripts/generate-keypair.php
```

**Follow the prompts:**
1. Enter strong passphrase (or leave empty)
2. Keys saved to `storage/keys/`
3. **IMPORTANT:** Delete script after use!

```bash
rm scripts/generate-keypair.php
```

### Step 4: Set Permissions (2 min)

```bash
bash scripts/setup-permissions.sh
```

This sets:
- `400` for private keys
- `600` for .env and configs
- `755` for directories
- `644` for files

### Step 5: Create Database (3 min)

```bash
# Import schema
mysql -u username -p database_name < database/migrations/001_create_tables.sql
```

Or use phpMyAdmin:
1. Login to Hostinger phpMyAdmin
2. Select database
3. Import `database/migrations/001_create_tables.sql`

### Step 6: Create Admin User (3 min)

```bash
php scripts/create-admin.php
```

**Follow the wizard:**
```
Enter admin user details:

Full Name: John Doe
Email: admin@example.com
Username: admin
Password: ************ (min 12 chars)
Confirm Password: ************

Select Role:
  1. Super Admin (full access)
Choice: 1

✓ ADMIN USER CREATED SUCCESSFULLY!
```

---

## 🎯 First Steps After Installation

### 1. Login to Admin Panel

Visit: `https://yourdomain.com/admin/login`

**Login with:**
- Email/Username: (what you just created)
- Password: (what you just set)

### 2. Enable 2FA (Recommended)

1. Go to Settings → Security
2. Click "Enable 2FA"
3. Scan QR code with Google Authenticator
4. Save recovery codes securely
5. Enter test code to verify

### 3. Create Your First Product

**Via Database (for now):**
```sql
INSERT INTO products (name, slug, description, is_active, created_at, updated_at)
VALUES (
    'My Application',
    'my-app',
    'Description of my software',
    1,
    NOW(),
    NOW()
);
```

### 4. Create Your First License

**Via Admin Panel:**
1. Go to Licenses → Create License
2. Fill in:
   - Product: My Application
   - Customer Email: customer@example.com
   - Customer Name: Test Customer
   - License Type: Standard
   - Max Activations: 1
   - Duration: 365 days
3. Click "Generate License"
4. Copy the license key

**Or via API:**
```bash
# First, generate license manually via admin panel
# You'll get a license key like: ABC12-DEF34-GHI56-JKL78-MNO90
```

---

## 🔑 Testing License Activation

### Test Online Activation

```bash
curl -X POST https://yourdomain.com/api/v1/licenses/activate \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "YOUR-LICENSE-KEY-HERE",
    "machine_fingerprint": "test_fingerprint_sha256_hash",
    "public_key": "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA...\n-----END PUBLIC KEY-----",
    "machine_info": {
      "machine_name": "TEST-PC",
      "os_info": "Windows 11 Pro",
      "cpu_info": "Intel i7",
      "mac_address": "00:11:22:33:44:55"
    }
  }'
```

**Expected Response:**
```json
{
  "success": true,
  "activation_token": "abc123...",
  "license": {
    "key": "YOUR-LICENSE-KEY",
    "product": "My Application",
    "type": "standard",
    "expires_at": "2027-01-22 12:00:00",
    "features": []
  },
  "encrypted_license": "base64_encrypted_data...",
  "signature": "base64_signature...",
  "server_public_key": "-----BEGIN PUBLIC KEY-----\n..."
}
```

### Test License Validation

```bash
curl -X POST https://yourdomain.com/api/v1/licenses/validate \
  -H "Content-Type: application/json" \
  -d '{
    "license_key": "YOUR-LICENSE-KEY",
    "machine_fingerprint": "test_fingerprint_sha256_hash",
    "activation_token": "abc123..."
  }'
```

### Test Health Check

```bash
curl https://yourdomain.com/api/v1/health
```

**Response:**
```json
{
  "status": "ok",
  "timestamp": 1234567890,
  "version": "1.0.0",
  "service": "License Server"
}
```

---

## 📊 Admin Panel Features

### Dashboard (`/admin/dashboard`)
- Total licenses count
- Active vs expired licenses
- Expiring soon alerts
- Customer statistics
- Recent activations
- Quick overview

### License Management (`/admin/licenses`)
- List all licenses
- Search by key or customer
- Filter by status
- Create new licenses
- View details
- Revoke licenses
- Track activations

### Security Features
- 2FA with TOTP
- Recovery codes
- Session timeout (30 min)
- IP whitelisting
- Failed login lockout
- Audit logging

---

## 🔒 Security Checklist

Before going live:

- [ ] Generated RSA keypairs (4096-bit)
- [ ] Private key has passphrase
- [ ] Private key backed up securely
- [ ] `.env` file has 600 permissions
- [ ] All storage directories protected
- [ ] SSL/HTTPS enabled
- [ ] Changed default passwords
- [ ] Created admin user with strong password
- [ ] Enabled 2FA for admin
- [ ] Rate limiting enabled
- [ ] Tested key directory not web-accessible
- [ ] Removed `generate-keypair.php` script

**Test protection:**
```bash
curl https://yourdomain.com/storage/keys/server_private.key
# Should return: 403 Forbidden
```

---

## 📖 API Reference

### Available Endpoints

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/v1/health` | GET | Health check |
| `/api/v1/licenses/activate` | POST | Activate license |
| `/api/v1/licenses/validate` | POST | Validate license |
| `/api/v1/licenses/deactivate` | POST | Deactivate license |
| `/api/v1/licenses/offline/request` | POST | Offline activation |
| `/api/v1/licenses/ping` | POST | Send heartbeat |
| `/api/v1/licenses/transfer` | POST | Transfer license |
| `/api/v1/licenses/{key}` | GET | Get license info |

### Authentication

- Public API endpoints (no auth required)
- Rate limited (60 requests/minute default)
- IP-based tracking
- Signature verification for licenses

---

## 🎨 Customization

### Change Application Name

Edit `.env`:
```env
APP_NAME="Your Company License Server"
```

### Adjust Security Settings

```env
# Session timeouts
ADMIN_SESSION_TIMEOUT=1800          # 30 minutes
ADMIN_ABSOLUTE_TIMEOUT=28800        # 8 hours

# Login security
MAX_LOGIN_ATTEMPTS=5                # Failed attempts before lock
LOGIN_LOCKOUT_DURATION=900          # 15 minutes lockout

# Password policies
PASSWORD_MIN_LENGTH=12
PASSWORD_REQUIRE_UPPERCASE=true
PASSWORD_REQUIRE_LOWERCASE=true
PASSWORD_REQUIRE_NUMBERS=true
PASSWORD_REQUIRE_SYMBOLS=true
```

### Configure License Defaults

```env
LICENSE_DEFAULT_DURATION_DAYS=365
LICENSE_GRACE_PERIOD_DAYS=7
LICENSE_MAX_ACTIVATIONS=1
LICENSE_ALLOW_TRANSFER=true
LICENSE_TRANSFER_COOLDOWN_DAYS=30
```

---

## 🐛 Troubleshooting

### Issue: Can't login to admin panel

**Check:**
1. Did you create an admin user?
   ```bash
   php scripts/create-admin.php
   ```
2. Is the user active?
   ```sql
   SELECT * FROM users WHERE email='admin@example.com';
   ```
3. Check error logs:
   ```bash
   tail -f storage/logs/app.log
   ```

### Issue: "Dependencies not installed"

**Solution:**
```bash
composer install
```

### Issue: "Configuration missing"

**Solution:**
```bash
cp .env.example .env
nano .env  # Configure database credentials
```

### Issue: Database connection failed

**Check:**
1. Database exists
2. Credentials in `.env` are correct
3. Database user has proper permissions
4. MySQL is running

### Issue: 500 Internal Server Error

**Check logs:**
```bash
# Application log
tail -f storage/logs/app.log

# PHP errors
tail -f storage/logs/php_errors.log

# Web server error log
tail -f /var/log/apache2/error.log  # or nginx
```

---

## 📱 What's Next?

### Immediate Next Steps (if needed):

1. **Create Products** - Add your software products
2. **Generate Licenses** - Start creating licenses for customers
3. **Test Activation** - Verify the full workflow

### Future Enhancements:

1. **Client Libraries** - C#, C++, Rust, Python
2. **Customer Portal** - Self-service license management
3. **Payment Integration** - Stripe/PayPal for sales
4. **Email Notifications** - Expiry warnings, activation alerts
5. **Automated Backups** - S3, SFTP, Dropbox

---

## 💡 Pro Tips

1. **Backup Private Key**
   - Copy to encrypted USB drive
   - Store in password manager
   - Keep offline copy

2. **Monitor Audit Logs**
   ```bash
   tail -f storage/logs/audit.log
   ```

3. **Regular Backups**
   - Database daily
   - Keys monthly
   - Test restore procedure

4. **Security Updates**
   ```bash
   composer update
   ```

5. **Performance**
   - Enable opcache
   - Use Redis if available
   - Configure CDN

---

## 📞 Support

- **Documentation**: `README.md`
- **Implementation Status**: `IMPLEMENTATION_STATUS.md`
- **Security Guide**: See `.htaccess` files
- **Database Schema**: `database/migrations/001_create_tables.sql`

---

**Status:** 🟢 Production Ready
**Version:** 1.0.0
**Last Updated:** 2026-01-22

---

🎉 **Your license server is ready! Start generating licenses and integrating with your applications.**
