#!/usr/bin/env php
<?php
/**
 * Backup Runner Script
 *
 * Run this script manually or via cron to create backups
 *
 * Usage:
 *   php scripts/run-backup.php
 *
 * Cron examples:
 *   # Daily at 2 AM
 *   0 2 * * * /usr/bin/php /path/to/scripts/run-backup.php
 *
 *   # Hourly
 *   0 * * * * /usr/bin/php /path/to/scripts/run-backup.php
 *
 *   # Weekly on Sunday at 3 AM
 *   0 3 * * 0 /usr/bin/php /path/to/scripts/run-backup.php
 *
 * @package LicenseServer
 */

// Define base path
define('BASE_PATH', dirname(__DIR__));
define('APP_PATH', BASE_PATH . '/app');

// Load environment variables
require_once BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Load helpers
require_once APP_PATH . '/Helpers/helpers.php';

// Initialize database
require_once BASE_PATH . '/config/database.php';

// Colors for CLI output
class CliColors {
    public static $colors = [
        'reset'   => "\033[0m",
        'red'     => "\033[31m",
        'green'   => "\033[32m",
        'yellow'  => "\033[33m",
        'blue'    => "\033[34m",
        'magenta' => "\033[35m",
        'cyan'    => "\033[36m",
        'white'   => "\033[37m",
        'bold'    => "\033[1m",
    ];

    public static function color($text, $color) {
        return self::$colors[$color] . $text . self::$colors['reset'];
    }
}

function println($message, $color = 'white') {
    echo CliColors::color($message, $color) . "\n";
}

function printSection($title) {
    echo "\n";
    println(str_repeat('=', 60), 'cyan');
    println($title, 'bold');
    println(str_repeat('=', 60), 'cyan');
}

// ASCII Art Banner
function printBanner() {
    $banner = "
╔═══════════════════════════════════════════════════════════╗
║                                                           ║
║              LICENSE SERVER BACKUP SYSTEM                 ║
║                                                           ║
╚═══════════════════════════════════════════════════════════╝
    ";
    println($banner, 'cyan');
}

try {
    printBanner();

    println("Starting backup process...", 'yellow');
    println("Timestamp: " . date('Y-m-d H:i:s'), 'white');
    println("");

    // Create backup service instance
    $backupService = new App\Services\BackupService();

    // Run backup
    printSection("CREATING BACKUP");
    println("⏳ This may take several minutes depending on data size...", 'yellow');
    println("");

    $result = $backupService->createBackup();

    println("");
    printSection("BACKUP RESULT");

    if ($result['success']) {
        println("✅ Backup completed successfully!", 'green');
        println("");
        println("Details:", 'cyan');
        println("  • Backup Name:  " . $result['backup_name'], 'white');
        println("  • File Path:    " . $result['file_path'], 'white');
        println("  • File Size:    " . $result['file_size_human'], 'white');
        println("  • Duration:     " . $result['duration'] . " seconds", 'white');
        println("  • Timestamp:    " . $result['timestamp'], 'white');

        if (!empty($result['destinations'])) {
            println("", 'white');
            println("Remote Destinations:", 'cyan');
            foreach ($result['destinations'] as $dest => $status) {
                $icon = $status['success'] ?? false ? '✅' : '❌';
                $color = $status['success'] ?? false ? 'green' : 'red';
                $message = $status['message'] ?? $status['error'] ?? 'Unknown';
                println("  {$icon} " . ucfirst($dest) . ": " . $message, $color);
            }
        }

        println("", 'white');
        println("📋 Backup has been saved and is ready for restore if needed.", 'white');

    } else {
        println("❌ Backup failed!", 'red');
        println("");
        println("Error: " . $result['error'], 'red');
        println("Duration: " . $result['duration'] . " seconds", 'white');
        println("Timestamp: " . $result['timestamp'], 'white');
        println("");
        println("Please check the logs for more details:", 'yellow');
        println("  " . BASE_PATH . "/storage/logs/backup.log", 'white');

        exit(1);
    }

    // List recent backups
    println("");
    printSection("RECENT BACKUPS");

    $backups = $backupService->listBackups();
    if (empty($backups)) {
        println("No backups found.", 'yellow');
    } else {
        println("Showing " . min(5, count($backups)) . " most recent backups:", 'cyan');
        println("");

        $displayBackups = array_slice($backups, 0, 5);
        foreach ($displayBackups as $index => $backup) {
            $number = $index + 1;
            println("  {$number}. " . $backup['name'], 'white');
            println("     Size: " . $backup['size_human'] . " | Created: " . $backup['created'] . " (" . $backup['age_days'] . " days ago)", 'white');
        }

        if (count($backups) > 5) {
            println("");
            println("  ... and " . (count($backups) - 5) . " more backups", 'white');
        }
    }

    // Storage usage
    println("");
    printSection("STORAGE USAGE");

    $totalSize = 0;
    foreach ($backups as $backup) {
        $totalSize += $backup['size'];
    }

    println("Total backups: " . count($backups), 'white');
    println("Total size: " . $backupService->formatBytes($totalSize), 'white');

    $diskFree = disk_free_space(BASE_PATH . '/storage');
    $diskTotal = disk_total_space(BASE_PATH . '/storage');
    $diskUsed = $diskTotal - $diskFree;
    $diskPercent = round(($diskUsed / $diskTotal) * 100, 2);

    println("Disk usage: " . round($diskUsed / 1024 / 1024 / 1024, 2) . " GB / " .
            round($diskTotal / 1024 / 1024 / 1024, 2) . " GB ({$diskPercent}%)", 'white');

    if ($diskPercent > 90) {
        println("⚠️  Warning: Disk usage is above 90%!", 'yellow');
    }

    println("");
    printSection("SUMMARY");
    println("✅ Backup process completed successfully", 'green');
    println("📧 Email notifications have been sent (if enabled)", 'white');
    println("📝 Check logs for detailed information", 'white');
    println("");

    exit(0);

} catch (\Exception $e) {
    println("");
    printSection("ERROR");
    println("❌ Fatal error occurred!", 'red');
    println("");
    println("Error: " . $e->getMessage(), 'red');
    println("");
    println("Stack trace:", 'yellow');
    println($e->getTraceAsString(), 'white');
    println("");
    println("Please check the application logs for more details.", 'yellow');

    exit(1);
}
