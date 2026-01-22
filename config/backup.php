<?php
/**
 * Backup Configuration
 *
 * Configuration for automated backups using spatie/laravel-backup
 *
 * @package LicenseServer
 */

return [
    /**
     * Backup name - used in backup filenames
     */
    'name' => env('BACKUP_NAME', env('APP_NAME', 'license-server')),

    /**
     * Source configuration
     */
    'source' => [
        /**
         * Files to include in backup
         */
        'files' => [
            'include' => [
                BASE_PATH . '/app',
                BASE_PATH . '/config',
                BASE_PATH . '/public_html',
                BASE_PATH . '/database/migrations',
                BASE_PATH . '/.env',
            ],
            'exclude' => [
                BASE_PATH . '/storage/cache',
                BASE_PATH . '/storage/sessions',
                BASE_PATH . '/storage/temp',
                BASE_PATH . '/storage/logs/*.log',
            ],
            'follow_links' => false,
        ],

        /**
         * Database connections to backup
         */
        'databases' => [
            env('DB_CONNECTION', 'mysql')
        ],
    ],

    /**
     * Backup destinations
     */
    'destinations' => [
        /**
         * Local disk backup
         */
        'local' => [
            'enabled' => env('BACKUP_LOCAL_ENABLED', true),
            'path' => BASE_PATH . '/storage/backups',
            'encrypt' => env('BACKUP_ENCRYPT', true),
        ],

        /**
         * S3 backup (optional)
         */
        's3' => [
            'enabled' => env('BACKUP_S3_ENABLED', false),
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'bucket' => env('AWS_BACKUP_BUCKET'),
            'path' => env('AWS_BACKUP_PATH', 'backups'),
        ],

        /**
         * SFTP backup (optional)
         */
        'sftp' => [
            'enabled' => env('BACKUP_SFTP_ENABLED', false),
            'host' => env('BACKUP_SFTP_HOST'),
            'port' => env('BACKUP_SFTP_PORT', 22),
            'username' => env('BACKUP_SFTP_USERNAME'),
            'password' => env('BACKUP_SFTP_PASSWORD'),
            'private_key' => env('BACKUP_SFTP_PRIVATE_KEY'),
            'path' => env('BACKUP_SFTP_PATH', '/backups'),
        ],

        /**
         * Dropbox backup (optional)
         */
        'dropbox' => [
            'enabled' => env('BACKUP_DROPBOX_ENABLED', false),
            'token' => env('BACKUP_DROPBOX_TOKEN'),
            'path' => env('BACKUP_DROPBOX_PATH', '/backups'),
        ],
    ],

    /**
     * Backup schedule and retention
     */
    'schedule' => [
        /**
         * How often to create backups
         * Options: hourly, daily, weekly
         */
        'frequency' => env('BACKUP_FREQUENCY', 'daily'),

        /**
         * Time to run daily backups (24-hour format)
         */
        'time' => env('BACKUP_TIME', '02:00'),

        /**
         * Day of week for weekly backups (0-6, 0 = Sunday)
         */
        'day_of_week' => env('BACKUP_DAY_OF_WEEK', 0),
    ],

    /**
     * Backup retention policy
     */
    'retention' => [
        /**
         * Keep all backups for this many days
         */
        'keep_all_for_days' => env('BACKUP_KEEP_ALL_DAYS', 7),

        /**
         * Keep daily backups for this many days
         */
        'keep_daily_for_days' => env('BACKUP_KEEP_DAILY_DAYS', 30),

        /**
         * Keep weekly backups for this many weeks
         */
        'keep_weekly_for_weeks' => env('BACKUP_KEEP_WEEKLY_WEEKS', 12),

        /**
         * Keep monthly backups for this many months
         */
        'keep_monthly_for_months' => env('BACKUP_KEEP_MONTHLY_MONTHS', 12),

        /**
         * Keep yearly backups forever
         */
        'keep_yearly_forever' => env('BACKUP_KEEP_YEARLY_FOREVER', true),

        /**
         * Delete old backups automatically
         */
        'auto_delete_old' => env('BACKUP_AUTO_DELETE_OLD', true),
    ],

    /**
     * Backup encryption
     */
    'encryption' => [
        /**
         * Encrypt backups
         */
        'enabled' => env('BACKUP_ENCRYPT', true),

        /**
         * Encryption key (must be 32 characters for AES-256)
         */
        'key' => env('BACKUP_ENCRYPTION_KEY', env('APP_KEY')),

        /**
         * Encryption method
         */
        'method' => 'AES-256-CBC',
    ],

    /**
     * Backup compression
     */
    'compression' => [
        /**
         * Compress backups
         */
        'enabled' => env('BACKUP_COMPRESS', true),

        /**
         * Compression method: zip, gzip, bzip2
         */
        'method' => env('BACKUP_COMPRESSION_METHOD', 'gzip'),

        /**
         * Compression level (1-9, 9 = maximum compression)
         */
        'level' => env('BACKUP_COMPRESSION_LEVEL', 6),
    ],

    /**
     * Backup notifications
     */
    'notifications' => [
        /**
         * Send email on backup success
         */
        'on_success' => env('BACKUP_NOTIFY_SUCCESS', false),

        /**
         * Send email on backup failure
         */
        'on_failure' => env('BACKUP_NOTIFY_FAILURE', true),

        /**
         * Email recipients
         */
        'recipients' => explode(',', env('BACKUP_NOTIFY_EMAILS', env('ADMIN_EMAIL', ''))),
    ],

    /**
     * Database backup settings
     */
    'database' => [
        /**
         * Include database in backups
         */
        'enabled' => env('BACKUP_DATABASE_ENABLED', true),

        /**
         * Add timestamps to database dump
         */
        'add_timestamps' => true,

        /**
         * Use single transaction for consistency
         */
        'single_transaction' => true,

        /**
         * Compress database dumps
         */
        'compress' => env('BACKUP_DATABASE_COMPRESS', true),

        /**
         * Tables to exclude from backup
         */
        'exclude_tables' => [
            'sessions',
            'cache',
            'audit_logs_old',
        ],
    ],

    /**
     * Backup verification
     */
    'verification' => [
        /**
         * Verify backups after creation
         */
        'enabled' => env('BACKUP_VERIFY', true),

        /**
         * Test restore on random backups
         */
        'test_restore' => env('BACKUP_TEST_RESTORE', false),

        /**
         * Verify checksums
         */
        'check_integrity' => env('BACKUP_CHECK_INTEGRITY', true),
    ],

    /**
     * Performance settings
     */
    'performance' => [
        /**
         * Maximum memory limit for backups (MB)
         */
        'memory_limit' => env('BACKUP_MEMORY_LIMIT', 512),

        /**
         * Maximum execution time (seconds, 0 = unlimited)
         */
        'time_limit' => env('BACKUP_TIME_LIMIT', 3600),

        /**
         * Split large backups into chunks
         */
        'split_size' => env('BACKUP_SPLIT_SIZE', 100), // MB
    ],

    /**
     * Logging
     */
    'logging' => [
        /**
         * Log backup operations
         */
        'enabled' => env('BACKUP_LOGGING', true),

        /**
         * Log level: debug, info, warning, error
         */
        'level' => env('BACKUP_LOG_LEVEL', 'info'),

        /**
         * Log file path
         */
        'path' => BASE_PATH . '/storage/logs/backup.log',
    ],
];
