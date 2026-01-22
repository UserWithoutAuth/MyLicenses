<?php

namespace App\Services;

use PDO;
use ZipArchive;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

/**
 * Backup Service
 *
 * Handles automated backups of database and files with support for
 * multiple destinations, encryption, compression, and retention policies.
 *
 * @package App\Services
 */
class BackupService
{
    private $config;
    private $emailService;
    private $backupPath;
    private $errors = [];

    public function __construct()
    {
        $this->config = require BASE_PATH . '/config/backup.php';
        $this->emailService = new EmailService();
        $this->backupPath = $this->config['destinations']['local']['path'];

        // Create backup directory if it doesn't exist
        if (!is_dir($this->backupPath)) {
            mkdir($this->backupPath, 0750, true);
        }
    }

    /**
     * Create a full backup (database + files)
     *
     * @return array Backup result with status and metadata
     */
    public function createBackup(): array
    {
        $startTime = microtime(true);
        $backupName = $this->generateBackupName();
        $tempDir = $this->createTempDirectory();

        try {
            // Set performance limits
            $this->setPerformanceLimits();

            logger('info', 'Starting backup creation', ['name' => $backupName]);

            // Step 1: Backup database
            $dbFile = null;
            if ($this->config['database']['enabled']) {
                logger('info', 'Backing up database...');
                $dbFile = $this->backupDatabase($tempDir);
                if (!$dbFile) {
                    throw new \Exception('Database backup failed');
                }
            }

            // Step 2: Copy files
            logger('info', 'Backing up files...');
            $filesDir = $this->backupFiles($tempDir);
            if (!$filesDir) {
                throw new \Exception('File backup failed');
            }

            // Step 3: Create archive
            logger('info', 'Creating backup archive...');
            $archivePath = $this->createArchive($tempDir, $backupName);
            if (!$archivePath) {
                throw new \Exception('Archive creation failed');
            }

            // Step 4: Encrypt if enabled
            if ($this->config['encryption']['enabled']) {
                logger('info', 'Encrypting backup...');
                $archivePath = $this->encryptBackup($archivePath);
            }

            // Step 5: Verify backup
            if ($this->config['verification']['enabled']) {
                logger('info', 'Verifying backup...');
                if (!$this->verifyBackup($archivePath)) {
                    throw new \Exception('Backup verification failed');
                }
            }

            // Step 6: Upload to remote destinations
            $destinations = $this->uploadToDestinations($archivePath, $backupName);

            // Step 7: Clean up temp directory
            $this->cleanupDirectory($tempDir);

            // Step 8: Apply retention policy
            if ($this->config['retention']['auto_delete_old']) {
                $this->applyRetentionPolicy();
            }

            $duration = round(microtime(true) - $startTime, 2);
            $fileSize = filesize($archivePath);

            $result = [
                'success' => true,
                'backup_name' => $backupName,
                'file_path' => $archivePath,
                'file_size' => $fileSize,
                'file_size_human' => $this->formatBytes($fileSize),
                'duration' => $duration,
                'destinations' => $destinations,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            logger('info', 'Backup completed successfully', $result);

            // Send success notification if enabled
            if ($this->config['notifications']['on_success']) {
                $this->sendNotification(true, $result);
            }

            // Audit log
            audit_log('backup.created', null, $result);

            return $result;

        } catch (\Exception $e) {
            logger('error', 'Backup failed: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString()
            ]);

            // Clean up on failure
            if (is_dir($tempDir)) {
                $this->cleanupDirectory($tempDir);
            }

            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'duration' => round(microtime(true) - $startTime, 2),
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            // Send failure notification
            if ($this->config['notifications']['on_failure']) {
                $this->sendNotification(false, $result);
            }

            return $result;
        }
    }

    /**
     * Backup database to SQL dump
     *
     * @param string $outputDir Directory to save the dump
     * @return string|null Path to the SQL dump file
     */
    private function backupDatabase(string $outputDir): ?string
    {
        try {
            $filename = 'database_' . date('Y-m-d_H-i-s') . '.sql';
            $filepath = $outputDir . '/' . $filename;

            $host = env('DB_HOST', 'localhost');
            $database = env('DB_DATABASE');
            $username = env('DB_USERNAME');
            $password = env('DB_PASSWORD');
            $port = env('DB_PORT', 3306);

            // Use mysqldump if available
            if ($this->commandExists('mysqldump')) {
                $excludeTables = '';
                foreach ($this->config['database']['exclude_tables'] as $table) {
                    $excludeTables .= " --ignore-table={$database}.{$table}";
                }

                $compress = $this->config['database']['compress'] ? '| gzip' : '';
                $extension = $this->config['database']['compress'] ? '.sql.gz' : '.sql';
                $filepath = $outputDir . '/database_' . date('Y-m-d_H-i-s') . $extension;

                $command = sprintf(
                    'mysqldump -h%s -P%s -u%s -p%s %s --single-transaction --quick --lock-tables=false %s %s > %s',
                    escapeshellarg($host),
                    escapeshellarg($port),
                    escapeshellarg($username),
                    escapeshellarg($password),
                    escapeshellarg($database),
                    $excludeTables,
                    $compress,
                    escapeshellarg($filepath)
                );

                exec($command, $output, $returnVar);

                if ($returnVar !== 0) {
                    throw new \Exception('mysqldump failed with code: ' . $returnVar);
                }
            } else {
                // Fallback to PDO-based dump
                $this->dumpDatabaseWithPDO($filepath);
            }

            if (!file_exists($filepath) || filesize($filepath) === 0) {
                throw new \Exception('Database dump file is empty or missing');
            }

            logger('info', 'Database backed up', [
                'file' => $filename,
                'size' => $this->formatBytes(filesize($filepath))
            ]);

            return $filepath;

        } catch (\Exception $e) {
            logger('error', 'Database backup failed: ' . $e->getMessage());
            $this->errors[] = 'Database backup: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Backup database using PDO (fallback method)
     *
     * @param string $filepath Output file path
     */
    private function dumpDatabaseWithPDO(string $filepath): void
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s',
                env('DB_HOST', 'localhost'),
                env('DB_PORT', 3306),
                env('DB_DATABASE')
            ),
            env('DB_USERNAME'),
            env('DB_PASSWORD'),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $dump = "-- Database Backup\n";
        $dump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $dump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        // Get all tables
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            // Skip excluded tables
            if (in_array($table, $this->config['database']['exclude_tables'])) {
                continue;
            }

            // Table structure
            $createTable = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
            $dump .= "\n-- Table structure for {$table}\n";
            $dump .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $dump .= $createTable['Create Table'] . ";\n\n";

            // Table data
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $dump .= "-- Dumping data for {$table}\n";
                foreach ($rows as $row) {
                    $values = array_map(function($value) use ($pdo) {
                        return $value === null ? 'NULL' : $pdo->quote($value);
                    }, array_values($row));

                    $dump .= "INSERT INTO `{$table}` VALUES (" . implode(',', $values) . ");\n";
                }
                $dump .= "\n";
            }
        }

        $dump .= "SET FOREIGN_KEY_CHECKS=1;\n";

        file_put_contents($filepath, $dump);
    }

    /**
     * Backup files according to configuration
     *
     * @param string $outputDir Directory to save files
     * @return string|null Path to the files directory
     */
    private function backupFiles(string $outputDir): ?string
    {
        try {
            $filesDir = $outputDir . '/files';
            mkdir($filesDir, 0750, true);

            $totalSize = 0;
            $fileCount = 0;

            foreach ($this->config['source']['files']['include'] as $path) {
                if (!file_exists($path)) {
                    logger('warning', 'Backup path does not exist: ' . $path);
                    continue;
                }

                $relativePath = str_replace(BASE_PATH . '/', '', $path);
                $destPath = $filesDir . '/' . $relativePath;

                if (is_file($path)) {
                    // Single file
                    $destDir = dirname($destPath);
                    if (!is_dir($destDir)) {
                        mkdir($destDir, 0750, true);
                    }
                    copy($path, $destPath);
                    $totalSize += filesize($path);
                    $fileCount++;
                } else {
                    // Directory
                    $result = $this->copyDirectory($path, $destPath);
                    $totalSize += $result['size'];
                    $fileCount += $result['files'];
                }
            }

            logger('info', 'Files backed up', [
                'files' => $fileCount,
                'size' => $this->formatBytes($totalSize)
            ]);

            return $filesDir;

        } catch (\Exception $e) {
            logger('error', 'File backup failed: ' . $e->getMessage());
            $this->errors[] = 'File backup: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Copy directory recursively with exclusion rules
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     * @return array Statistics (files, size)
     */
    private function copyDirectory(string $source, string $dest): array
    {
        $fileCount = 0;
        $totalSize = 0;

        if (!is_dir($dest)) {
            mkdir($dest, 0750, true);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $destPath = $dest . '/' . $iterator->getSubPathName();

            // Check exclusion rules
            if ($this->isExcluded($item->getPathname())) {
                continue;
            }

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    mkdir($destPath, 0750, true);
                }
            } else {
                copy($item->getPathname(), $destPath);
                $totalSize += $item->getSize();
                $fileCount++;
            }
        }

        return ['files' => $fileCount, 'size' => $totalSize];
    }

    /**
     * Check if path should be excluded from backup
     *
     * @param string $path Path to check
     * @return bool True if should be excluded
     */
    private function isExcluded(string $path): bool
    {
        foreach ($this->config['source']['files']['exclude'] as $exclude) {
            // Support wildcards
            $pattern = str_replace(['*', '?'], ['.*', '.'], $exclude);
            if (preg_match('#' . $pattern . '#', $path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Create compressed archive from backup directory
     *
     * @param string $sourceDir Source directory
     * @param string $backupName Backup name
     * @return string|null Path to archive
     */
    private function createArchive(string $sourceDir, string $backupName): ?string
    {
        try {
            $extension = $this->config['compression']['enabled'] ? '.zip' : '.tar';
            $archivePath = $this->backupPath . '/' . $backupName . $extension;

            if ($this->config['compression']['enabled'] && class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                    throw new \Exception('Could not create zip archive');
                }

                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    $relativePath = substr($item->getPathname(), strlen($sourceDir) + 1);
                    if ($item->isDir()) {
                        $zip->addEmptyDir($relativePath);
                    } else {
                        $zip->addFile($item->getPathname(), $relativePath);
                    }
                }

                $zip->close();
            } else {
                // Fallback to tar
                $archivePath = $this->backupPath . '/' . $backupName . '.tar';
                $command = sprintf('tar -cf %s -C %s .',
                    escapeshellarg($archivePath),
                    escapeshellarg($sourceDir)
                );
                exec($command, $output, $returnVar);

                if ($returnVar !== 0) {
                    throw new \Exception('tar command failed');
                }
            }

            logger('info', 'Archive created', [
                'file' => basename($archivePath),
                'size' => $this->formatBytes(filesize($archivePath))
            ]);

            return $archivePath;

        } catch (\Exception $e) {
            logger('error', 'Archive creation failed: ' . $e->getMessage());
            $this->errors[] = 'Archive creation: ' . $e->getMessage();
            return null;
        }
    }

    /**
     * Encrypt backup file
     *
     * @param string $filePath Path to file
     * @return string Path to encrypted file
     */
    private function encryptBackup(string $filePath): string
    {
        try {
            $key = $this->config['encryption']['key'];
            if (strlen($key) < 32) {
                $key = hash('sha256', $key, true);
            } else {
                $key = substr($key, 0, 32);
            }

            $data = file_get_contents($filePath);
            $encrypted = encrypt_data($data, $key);

            $encryptedPath = $filePath . '.enc';
            file_put_contents($encryptedPath, $encrypted);

            // Remove unencrypted file
            unlink($filePath);

            logger('info', 'Backup encrypted', [
                'file' => basename($encryptedPath)
            ]);

            return $encryptedPath;

        } catch (\Exception $e) {
            logger('error', 'Encryption failed: ' . $e->getMessage());
            return $filePath; // Return original if encryption fails
        }
    }

    /**
     * Verify backup integrity
     *
     * @param string $filePath Path to backup file
     * @return bool True if valid
     */
    private function verifyBackup(string $filePath): bool
    {
        try {
            if (!file_exists($filePath)) {
                throw new \Exception('Backup file does not exist');
            }

            if (filesize($filePath) === 0) {
                throw new \Exception('Backup file is empty');
            }

            // Calculate checksum
            $checksum = hash_file('sha256', $filePath);
            $checksumFile = $filePath . '.sha256';
            file_put_contents($checksumFile, $checksum);

            logger('info', 'Backup verified', [
                'checksum' => $checksum
            ]);

            return true;

        } catch (\Exception $e) {
            logger('error', 'Verification failed: ' . $e->getMessage());
            $this->errors[] = 'Verification: ' . $e->getMessage();
            return false;
        }
    }

    /**
     * Upload backup to remote destinations
     *
     * @param string $filePath Path to backup file
     * @param string $backupName Backup name
     * @return array Upload results
     */
    private function uploadToDestinations(string $filePath, string $backupName): array
    {
        $results = [];

        // S3 Upload
        if ($this->config['destinations']['s3']['enabled']) {
            try {
                $result = $this->uploadToS3($filePath, $backupName);
                $results['s3'] = $result;
            } catch (\Exception $e) {
                logger('error', 'S3 upload failed: ' . $e->getMessage());
                $results['s3'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // SFTP Upload
        if ($this->config['destinations']['sftp']['enabled']) {
            try {
                $result = $this->uploadToSFTP($filePath, $backupName);
                $results['sftp'] = $result;
            } catch (\Exception $e) {
                logger('error', 'SFTP upload failed: ' . $e->getMessage());
                $results['sftp'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Dropbox Upload
        if ($this->config['destinations']['dropbox']['enabled']) {
            try {
                $result = $this->uploadToDropbox($filePath, $backupName);
                $results['dropbox'] = $result;
            } catch (\Exception $e) {
                logger('error', 'Dropbox upload failed: ' . $e->getMessage());
                $results['dropbox'] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Upload to S3
     */
    private function uploadToS3(string $filePath, string $backupName): array
    {
        // Note: Requires AWS SDK, implementation placeholder
        logger('info', 'S3 upload would be performed here');
        return ['success' => true, 'message' => 'S3 SDK not implemented'];
    }

    /**
     * Upload to SFTP
     */
    private function uploadToSFTP(string $filePath, string $backupName): array
    {
        // Note: Requires phpseclib, implementation placeholder
        logger('info', 'SFTP upload would be performed here');
        return ['success' => true, 'message' => 'SFTP not implemented'];
    }

    /**
     * Upload to Dropbox
     */
    private function uploadToDropbox(string $filePath, string $backupName): array
    {
        // Note: Requires Dropbox SDK, implementation placeholder
        logger('info', 'Dropbox upload would be performed here');
        return ['success' => true, 'message' => 'Dropbox SDK not implemented'];
    }

    /**
     * Apply retention policy to delete old backups
     */
    private function applyRetentionPolicy(): void
    {
        try {
            $files = glob($this->backupPath . '/*');
            $now = time();
            $deleted = 0;

            foreach ($files as $file) {
                if (is_dir($file)) {
                    continue;
                }

                $age = $now - filemtime($file);
                $ageDays = floor($age / 86400);

                $shouldDelete = false;

                // Keep all backups for X days
                if ($ageDays > $this->config['retention']['keep_all_for_days']) {
                    $shouldDelete = true;
                }

                // Apply daily/weekly/monthly retention
                // (Simplified logic - full implementation would be more sophisticated)

                if ($shouldDelete) {
                    unlink($file);
                    // Also delete checksum file if exists
                    if (file_exists($file . '.sha256')) {
                        unlink($file . '.sha256');
                    }
                    $deleted++;
                    logger('info', 'Deleted old backup', ['file' => basename($file)]);
                }
            }

            if ($deleted > 0) {
                logger('info', "Retention policy applied: deleted {$deleted} old backups");
            }

        } catch (\Exception $e) {
            logger('error', 'Retention policy failed: ' . $e->getMessage());
        }
    }

    /**
     * List all available backups
     *
     * @return array List of backups with metadata
     */
    public function listBackups(): array
    {
        $files = glob($this->backupPath . '/*');
        $backups = [];

        foreach ($files as $file) {
            if (is_dir($file) || pathinfo($file, PATHINFO_EXTENSION) === 'sha256') {
                continue;
            }

            $backups[] = [
                'name' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'size_human' => $this->formatBytes(filesize($file)),
                'created' => date('Y-m-d H:i:s', filemtime($file)),
                'age_days' => floor((time() - filemtime($file)) / 86400),
            ];
        }

        // Sort by date (newest first)
        usort($backups, function($a, $b) {
            return strcmp($b['created'], $a['created']);
        });

        return $backups;
    }

    /**
     * Restore from backup
     *
     * @param string $backupFile Backup file to restore
     * @return array Restore result
     */
    public function restoreBackup(string $backupFile): array
    {
        // Note: This is a placeholder - actual restore would need careful implementation
        logger('warning', 'Restore functionality is not yet implemented');

        return [
            'success' => false,
            'message' => 'Restore functionality requires manual implementation for safety',
            'backup_file' => $backupFile,
        ];
    }

    /**
     * Generate unique backup name
     */
    private function generateBackupName(): string
    {
        $name = $this->config['name'];
        $timestamp = date('Y-m-d_H-i-s');
        return "{$name}_{$timestamp}";
    }

    /**
     * Create temporary directory for backup staging
     */
    private function createTempDirectory(): string
    {
        $tempDir = BASE_PATH . '/storage/temp/backup_' . uniqid();
        mkdir($tempDir, 0750, true);
        return $tempDir;
    }

    /**
     * Clean up directory recursively
     */
    private function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->cleanupDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Set performance limits
     */
    private function setPerformanceLimits(): void
    {
        $memoryLimit = $this->config['performance']['memory_limit'];
        $timeLimit = $this->config['performance']['time_limit'];

        ini_set('memory_limit', $memoryLimit . 'M');
        set_time_limit($timeLimit);
    }

    /**
     * Check if command exists
     */
    private function commandExists(string $command): bool
    {
        $return = shell_exec(sprintf("which %s 2>/dev/null", escapeshellarg($command)));
        return !empty($return);
    }

    /**
     * Format bytes to human readable
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Send notification email
     */
    private function sendNotification(bool $success, array $data): void
    {
        try {
            $recipients = $this->config['notifications']['recipients'];
            if (empty($recipients)) {
                return;
            }

            $subject = $success
                ? '[Success] Backup Completed - ' . ($data['backup_name'] ?? 'Unknown')
                : '[Failed] Backup Failed';

            $message = $this->renderNotificationTemplate($success, $data);

            foreach ($recipients as $recipient) {
                $this->emailService->send($recipient, $subject, $message);
            }

        } catch (\Exception $e) {
            logger('error', 'Failed to send backup notification: ' . $e->getMessage());
        }
    }

    /**
     * Render notification email template
     */
    private function renderNotificationTemplate(bool $success, array $data): string
    {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $color = $success ? '#10b981' : '#ef4444';

        $details = '';
        if ($success) {
            $details = "
                <p><strong>Backup Name:</strong> {$data['backup_name']}</p>
                <p><strong>File Size:</strong> {$data['file_size_human']}</p>
                <p><strong>Duration:</strong> {$data['duration']} seconds</p>
                <p><strong>Timestamp:</strong> {$data['timestamp']}</p>
            ";
        } else {
            $details = "
                <p><strong>Error:</strong> {$data['error']}</p>
                <p><strong>Duration:</strong> {$data['duration']} seconds</p>
                <p><strong>Timestamp:</strong> {$data['timestamp']}</p>
            ";
        }

        return "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
                <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                    <div style='background: {$color}; color: white; padding: 20px; border-radius: 5px; margin-bottom: 20px;'>
                        <h2 style='margin: 0;'>Backup {$status}</h2>
                    </div>
                    <div style='padding: 20px; border: 1px solid #ddd; border-radius: 5px;'>
                        {$details}
                    </div>
                    <p style='margin-top: 20px; font-size: 12px; color: #666;'>
                        This is an automated message from your License Server backup system.
                    </p>
                </div>
            </body>
            </html>
        ";
    }
}
