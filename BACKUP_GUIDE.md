# Backup System Guide

Complete guide for the automated backup system in the PHP License Server.

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Configuration](#configuration)
- [Usage](#usage)
- [Destinations](#destinations)
- [Retention Policies](#retention-policies)
- [Automation](#automation)
- [Restore](#restore)
- [Troubleshooting](#troubleshooting)

## Overview

The backup system provides comprehensive automated backups of your license server data, including:

- **Database backups** - All MySQL tables with structure and data
- **File backups** - Application files, configurations, and keys
- **Encryption** - AES-256 encryption for backup files
- **Compression** - ZIP/GZIP compression to save storage
- **Multiple destinations** - Local, S3, SFTP, Dropbox
- **Retention policies** - Automatic cleanup of old backups
- **Verification** - Checksums and integrity validation
- **Email notifications** - Success/failure alerts

## Features

### ✅ Included in Backups

- Database tables and data
- Application code (`/app`, `/config`)
- Public files (`/public_html`)
- Migration files
- Environment configuration (`.env`)

### ❌ Excluded from Backups

- Cache files
- Session files
- Temporary files
- Old log files

### 🔐 Security Features

- **Encryption**: AES-256-CBC encryption of backup archives
- **Checksums**: SHA-256 verification for integrity
- **Access Control**: RBAC permissions for backup operations
- **Audit Logging**: All backup operations are logged
- **Secure Storage**: Backups stored outside web root

## Configuration

All backup settings are in `/config/backup.php`. Key configuration options:

### Basic Settings

```php
'name' => env('BACKUP_NAME', 'license-server'),  // Backup name prefix
```

### Database Settings

```php
'database' => [
    'enabled' => true,                    // Enable database backups
    'compress' => true,                   // Compress SQL dumps
    'single_transaction' => true,         // Use transactions for consistency
    'exclude_tables' => [                 // Tables to skip
        'sessions',
        'cache',
    ],
],
```

### Encryption

```php
'encryption' => [
    'enabled' => true,                    // Encrypt backups
    'key' => env('BACKUP_ENCRYPTION_KEY'), // 32-character key
    'method' => 'AES-256-CBC',           // Encryption method
],
```

### Compression

```php
'compression' => [
    'enabled' => true,                    // Compress backups
    'method' => 'gzip',                   // zip, gzip, or bzip2
    'level' => 6,                         // 1-9 (9 = max compression)
],
```

### Notifications

```php
'notifications' => [
    'on_success' => false,                // Email on success
    'on_failure' => true,                 // Email on failure
    'recipients' => explode(',', env('BACKUP_NOTIFY_EMAILS')),
],
```

## Usage

### Manual Backup (CLI)

Run a backup manually:

```bash
php scripts/run-backup.php
```

Output example:
```
╔═══════════════════════════════════════════════════════════╗
║              LICENSE SERVER BACKUP SYSTEM                 ║
╚═══════════════════════════════════════════════════════════╝

✅ Backup completed successfully!

Details:
  • Backup Name:  license-server_2026-01-22_15-30-45
  • File Path:    /path/to/storage/backups/...
  • File Size:    45.3 MB
  • Duration:     23.5 seconds
  • Timestamp:    2026-01-22 15:30:45
```

### Admin Panel

Access backup management at: **`https://yourdomain.com/admin/backups`**

Features:
- Create new backups with one click
- View backup history
- Download backups
- Delete old backups
- View storage usage
- See backup configuration

### API Usage

Create a backup via API:

```bash
curl -X POST https://yourdomain.com/admin/backups/create \
  -H "Cookie: session_id=..." \
  -H "Content-Type: application/json"
```

Response:
```json
{
  "success": true,
  "backup_name": "license-server_2026-01-22_15-30-45",
  "file_size": 47551488,
  "file_size_human": "45.3 MB",
  "duration": 23.5,
  "timestamp": "2026-01-22 15:30:45"
}
```

## Destinations

### Local Storage

Backups are always stored locally at `/storage/backups/`.

Configuration:
```env
BACKUP_LOCAL_ENABLED=true
```

### Amazon S3

Store backups on AWS S3:

```env
BACKUP_S3_ENABLED=true
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=us-east-1
AWS_BACKUP_BUCKET=my-backups
AWS_BACKUP_PATH=license-server/backups
```

### SFTP

Upload backups to remote SFTP server:

```env
BACKUP_SFTP_ENABLED=true
BACKUP_SFTP_HOST=sftp.example.com
BACKUP_SFTP_PORT=22
BACKUP_SFTP_USERNAME=backup_user
BACKUP_SFTP_PASSWORD=secure_password
BACKUP_SFTP_PRIVATE_KEY=/path/to/private_key
BACKUP_SFTP_PATH=/backups
```

### Dropbox

Sync backups to Dropbox:

```env
BACKUP_DROPBOX_ENABLED=true
BACKUP_DROPBOX_TOKEN=your_dropbox_token
BACKUP_DROPBOX_PATH=/Apps/LicenseServer/backups
```

## Retention Policies

Configure how long to keep backups:

```env
# Keep all backups for 7 days
BACKUP_KEEP_ALL_DAYS=7

# Keep daily backups for 30 days
BACKUP_KEEP_DAILY_DAYS=30

# Keep weekly backups for 12 weeks
BACKUP_KEEP_WEEKLY_WEEKS=12

# Keep monthly backups for 12 months
BACKUP_KEEP_MONTHLY_MONTHS=12

# Keep yearly backups forever
BACKUP_KEEP_YEARLY_FOREVER=true

# Auto-delete old backups
BACKUP_AUTO_DELETE_OLD=true
```

### Retention Logic

- **All backups**: Kept for `BACKUP_KEEP_ALL_DAYS`
- **Daily backups**: One per day for `BACKUP_KEEP_DAILY_DAYS`
- **Weekly backups**: One per week for `BACKUP_KEEP_WEEKLY_WEEKS`
- **Monthly backups**: One per month for `BACKUP_KEEP_MONTHLY_MONTHS`
- **Yearly backups**: One per year, kept forever if enabled

## Automation

### Cron Setup

Add to your crontab (`crontab -e`):

```bash
# Daily backup at 2 AM
0 2 * * * /usr/bin/php /path/to/scripts/run-backup.php >> /path/to/storage/logs/backup-cron.log 2>&1

# Every 6 hours
0 */6 * * * /usr/bin/php /path/to/scripts/run-backup.php

# Weekly on Sunday at 3 AM
0 3 * * 0 /usr/bin/php /path/to/scripts/run-backup.php
```

### Hostinger Cron Jobs

In Hostinger control panel:

1. Go to **Advanced** → **Cron Jobs**
2. Click **Create Cron Job**
3. Set schedule (e.g., "Once a day" at 02:00)
4. Command: `/usr/bin/php /home/username/MyLicenses/scripts/run-backup.php`
5. Save

### Schedule Configuration

Set backup frequency in `.env`:

```env
BACKUP_FREQUENCY=daily        # hourly, daily, or weekly
BACKUP_TIME=02:00             # Time for daily backups
BACKUP_DAY_OF_WEEK=0          # Day for weekly (0=Sunday)
```

## Restore

### Manual Restore

**⚠️ Warning**: Restoring will overwrite existing data!

1. **Stop the application** (optional but recommended)
   ```bash
   # Put in maintenance mode
   echo "MAINTENANCE_MODE=true" >> .env
   ```

2. **Decrypt backup** (if encrypted)
   ```bash
   # Decrypt using OpenSSL or PHP script
   ```

3. **Extract backup**
   ```bash
   unzip backup_file.zip -d /tmp/restore
   # or
   tar -xzf backup_file.tar.gz -d /tmp/restore
   ```

4. **Restore database**
   ```bash
   mysql -u username -p database_name < /tmp/restore/database_*.sql
   ```

5. **Restore files**
   ```bash
   cp -r /tmp/restore/files/* /home/username/MyLicenses/
   ```

6. **Set permissions**
   ```bash
   bash scripts/setup-permissions.sh
   ```

7. **Restart application**
   ```bash
   # Remove maintenance mode
   sed -i '/MAINTENANCE_MODE/d' .env
   ```

### Automated Restore (Future)

Automated restore functionality is planned for a future release. For now, use manual restore process above.

## Troubleshooting

### Common Issues

#### 1. "Permission denied" errors

**Solution**: Ensure backup directory is writable:
```bash
chmod 750 storage/backups
chmod 750 storage/temp
```

#### 2. "mysqldump: command not found"

**Solution**: Backup service will fallback to PDO-based dump automatically. Or install mysqldump:
```bash
# On Hostinger, mysqldump should be available
# Otherwise, the system uses PHP PDO fallback
```

#### 3. Backup file is too large

**Solution**: Enable compression and split large backups:
```env
BACKUP_COMPRESS=true
BACKUP_COMPRESSION_LEVEL=9
BACKUP_SPLIT_SIZE=100    # Split into 100MB chunks
```

#### 4. Out of memory errors

**Solution**: Increase memory limit:
```env
BACKUP_MEMORY_LIMIT=512   # MB
```

#### 5. Backup takes too long

**Solution**: Increase time limit and exclude unnecessary files:
```env
BACKUP_TIME_LIMIT=3600    # 1 hour
```

And update `/config/backup.php` exclude list:
```php
'exclude' => [
    BASE_PATH . '/storage/logs/*.log',
    BASE_PATH . '/storage/cache/*',
],
```

#### 6. Email notifications not sending

**Solution**: Check mail configuration:
```env
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=your_email@domain.com
MAIL_PASSWORD=your_password
MAIL_FROM_ADDRESS=noreply@domain.com
BACKUP_NOTIFY_EMAILS=admin@domain.com
```

### Log Files

Check logs for detailed error information:

- **Backup log**: `/storage/logs/backup.log`
- **Application log**: `/storage/logs/app.log`
- **Cron log**: `/storage/logs/backup-cron.log` (if using cron)

### Verify Backups

Test backup integrity:

```bash
# Check if backup file exists and is not empty
ls -lh storage/backups/

# Verify checksum
sha256sum -c backup_file.sha256

# Test extraction (without actually extracting)
unzip -t backup_file.zip
# or
tar -tzf backup_file.tar.gz > /dev/null
```

### Health Check

Run a backup health check:

```php
// In PHP
$backupService = new App\Services\BackupService();
$backups = $backupService->listBackups();

// Check last backup age
$lastBackup = $backups[0] ?? null;
if ($lastBackup && $lastBackup['age_days'] > 7) {
    echo "⚠️ Last backup is {$lastBackup['age_days']} days old!";
}
```

## Best Practices

### 🎯 Recommendations

1. **Daily backups minimum** - Set up automated daily backups
2. **Test restores regularly** - Verify backups work by testing restore process
3. **Multiple destinations** - Use at least 2 backup destinations (local + remote)
4. **Monitor disk space** - Keep at least 20% free space
5. **Encryption enabled** - Always encrypt backups containing sensitive data
6. **Secure remote storage** - Use strong passwords/keys for remote destinations
7. **Document recovery process** - Have written procedures for restore
8. **Regular cleanup** - Let retention policies automatically clean old backups

### 📊 Monitoring

Monitor these metrics:

- Last successful backup time
- Backup file sizes (should be consistent)
- Disk space usage
- Failed backup count
- Backup duration (sudden increases may indicate issues)

### 🔒 Security

- Store encryption keys securely (not in git)
- Use separate credentials for backup destinations
- Limit access to backup files (chmod 640)
- Enable audit logging for backup operations
- Regularly rotate encryption keys
- Test disaster recovery procedures

## Support

For issues or questions:

- Check logs: `/storage/logs/backup.log`
- Review configuration: `/config/backup.php`
- Test manually: `php scripts/run-backup.php`
- Contact support with log excerpts

---

**Last Updated**: 2026-01-22
**Version**: 1.0.0
