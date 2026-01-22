<?php
/**
 * Send Scheduled Notifications
 *
 * Checks for expiring licenses and sends email notifications
 *
 * Usage: php scripts/send-notifications.php
 *
 * Add to crontab:
 * 0 9 * * * /usr/bin/php /path/to/scripts/send-notifications.php
 *
 * @package LicenseServer
 */

define('BASE_PATH', dirname(__DIR__));

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line.' . PHP_EOL);
}

// Load dependencies
require_once BASE_PATH . '/vendor/autoload.php';

// Load environment
if (!file_exists(BASE_PATH . '/.env')) {
    die("ERROR: .env file not found.\n");
}

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Bootstrap database
require_once BASE_PATH . '/config/database.php';

use App\Services\NotificationService;

// Colors for output
$colors = [
    'reset' => "\033[0m",
    'green' => "\033[32m",
    'yellow' => "\033[33m",
    'cyan' => "\033[36m",
    'bold' => "\033[1m",
];

function colorize($text, $color, $bold = false) {
    global $colors;
    $style = $bold ? $colors['bold'] : '';
    return $style . $colors[$color] . $text . $colors['reset'];
}

echo colorize("\n╔═══════════════════════════════════════════════════════════╗\n", 'cyan', true);
echo colorize("║          LICENSE NOTIFICATION SCHEDULER                   ║\n", 'cyan', true);
echo colorize("╚═══════════════════════════════════════════════════════════╝\n\n", 'cyan', true);

echo "Started: " . date('Y-m-d H:i:s') . "\n\n";

// Check if notifications are enabled
if (!env('MAIL_ENABLED', true)) {
    echo colorize("⚠ Email notifications are disabled in .env\n", 'yellow');
    echo "  Set MAIL_ENABLED=true to enable.\n\n";
    exit(0);
}

try {
    $notificationService = new NotificationService();

    echo colorize("Running notification checks...\n\n", 'cyan', true);

    // Run all notifications
    $results = $notificationService->runAll();

    // Display results
    echo colorize("EXPIRING LICENSES:\n", 'cyan', true);
    echo "  Checked:  {$results['expiring']['checked']}\n";
    echo "  Notified: " . colorize($results['expiring']['notified'], 'green') . "\n";
    echo "  Errors:   " . colorize($results['expiring']['errors'], $results['expiring']['errors'] > 0 ? 'yellow' : 'green') . "\n\n";

    echo colorize("EXPIRED LICENSES:\n", 'cyan', true);
    echo "  Checked:  {$results['expired']['checked']}\n";
    echo "  Notified: " . colorize($results['expired']['notified'], 'green') . "\n";
    echo "  Errors:   " . colorize($results['expired']['errors'], $results['expired']['errors'] > 0 ? 'yellow' : 'green') . "\n\n";

    echo colorize("MISSED PINGS:\n", 'cyan', true);
    echo "  Checked:  {$results['missed_pings']['checked']}\n";
    echo "  Notified: " . colorize($results['missed_pings']['notified'], 'green') . "\n";
    echo "  Errors:   " . colorize($results['missed_pings']['errors'], $results['missed_pings']['errors'] > 0 ? 'yellow' : 'green') . "\n\n";

    echo str_repeat("─", 60) . "\n";
    echo colorize("TOTAL NOTIFICATIONS SENT: {$results['total_notified']}\n", 'green', true);

    if ($results['total_errors'] > 0) {
        echo colorize("TOTAL ERRORS: {$results['total_errors']}\n", 'yellow', true);
        echo "Check logs for details: storage/logs/app.log\n";
    }

    echo str_repeat("─", 60) . "\n\n";

    echo "Completed: " . date('Y-m-d H:i:s') . "\n\n";

    exit(0);

} catch (Exception $e) {
    echo colorize("\n✗ ERROR: " . $e->getMessage() . "\n\n", 'red', true);
    echo "Check logs: storage/logs/app.log\n\n";
    exit(1);
}
