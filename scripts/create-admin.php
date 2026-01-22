<?php
/**
 * Create Initial Admin User
 *
 * Creates the first super admin user for the license server
 *
 * Usage: php scripts/create-admin.php
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
    die("ERROR: .env file not found. Please create it from .env.example\n");
}

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

// Bootstrap database
require_once BASE_PATH . '/config/database.php';

use App\Models\User;

// Colors for output
$colors = [
    'reset' => "\033[0m",
    'green' => "\033[32m",
    'red' => "\033[31m",
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
echo colorize("║          CREATE INITIAL ADMIN USER                        ║\n", 'cyan', true);
echo colorize("╚═══════════════════════════════════════════════════════════╝\n\n", 'cyan', true);

// Check if admin already exists
$existingAdmin = User::byRole('super_admin')->first();

if ($existingAdmin) {
    echo colorize("⚠ WARNING: A super admin user already exists!\n", 'yellow', true);
    echo "  Email: {$existingAdmin->email}\n";
    echo "  Name: {$existingAdmin->name}\n\n";
    echo "Do you want to create another admin user? (yes/no): ";

    $confirmation = trim(fgets(STDIN));

    if (strtolower($confirmation) !== 'yes') {
        echo colorize("\n✓ Operation cancelled.\n\n", 'green');
        exit(0);
    }
}

// Collect user information
echo colorize("Enter admin user details:\n\n", 'cyan', true);

echo "Full Name: ";
$name = trim(fgets(STDIN));

echo "Email: ";
$email = trim(fgets(STDIN));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    die(colorize("\n✗ ERROR: Invalid email address\n\n", 'red', true));
}

// Check if email already exists
if (User::findByEmail($email)) {
    die(colorize("\n✗ ERROR: User with this email already exists\n\n", 'red', true));
}

echo "Username (optional, press enter to skip): ";
$username = trim(fgets(STDIN));

if (empty($username)) {
    $username = null;
}

// Password
echo "\nPassword (min 12 characters): ";
system('stty -echo');
$password = trim(fgets(STDIN));
system('stty echo');
echo "\n";

// Validate password
if (strlen($password) < 12) {
    die(colorize("\n✗ ERROR: Password must be at least 12 characters long\n\n", 'red', true));
}

echo "Confirm Password: ";
system('stty -echo');
$passwordConfirm = trim(fgets(STDIN));
system('stty echo');
echo "\n";

if ($password !== $passwordConfirm) {
    die(colorize("\n✗ ERROR: Passwords do not match\n\n", 'red', true));
}

// Select role
echo "\nSelect Role:\n";
echo "  1. Super Admin (full access)\n";
echo "  2. Admin (manage licenses, customers, products)\n";
echo "  3. Support (view and assist)\n";
echo "  4. Viewer (read-only)\n";
echo "\nChoice (1-4, default: 1): ";
$roleChoice = trim(fgets(STDIN));

$roles = [
    1 => 'super_admin',
    2 => 'admin',
    3 => 'support',
    4 => 'viewer'
];

$role = $roles[$roleChoice] ?? 'super_admin';

// Confirm creation
echo colorize("\n" . str_repeat("─", 60) . "\n", 'cyan');
echo colorize("CONFIRM USER CREATION\n", 'cyan', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'cyan');

echo "Name:     {$name}\n";
echo "Email:    {$email}\n";
echo "Username: " . ($username ?? '(not set)') . "\n";
echo "Role:     " . ucfirst(str_replace('_', ' ', $role)) . "\n\n";

echo "Create this user? (yes/no): ";
$confirmation = trim(fgets(STDIN));

if (strtolower($confirmation) !== 'yes') {
    echo colorize("\n✓ Operation cancelled.\n\n", 'green');
    exit(0);
}

// Create user
try {
    $user = User::create([
        'name' => $name,
        'email' => $email,
        'username' => $username,
        'password_hash' => hash_password($password),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now()
    ]);

    echo colorize("\n" . str_repeat("═", 60) . "\n", 'green');
    echo colorize("✓ ADMIN USER CREATED SUCCESSFULLY!\n", 'green', true);
    echo colorize(str_repeat("═", 60) . "\n\n", 'green');

    echo "User Details:\n";
    echo "  ID:       {$user->id}\n";
    echo "  UUID:     {$user->uuid}\n";
    echo "  Email:    {$user->email}\n";
    echo "  Role:     " . ucfirst(str_replace('_', ' ', $user->role)) . "\n\n";

    echo colorize("Login URL:\n", 'cyan', true);
    echo "  " . env('APP_URL') . "/admin/login\n\n";

    echo colorize("Next Steps:\n", 'yellow', true);
    echo "  1. Login to the admin panel\n";
    echo "  2. Enable 2FA for enhanced security (recommended)\n";
    echo "  3. Create products and license tiers\n";
    echo "  4. Start generating licenses\n\n";

    echo colorize("Security Recommendations:\n", 'yellow', true);
    echo "  • Enable 2FA immediately after first login\n";
    echo "  • Use a strong, unique password\n";
    echo "  • Add IP whitelist restrictions if needed\n";
    echo "  • Regularly review audit logs\n\n";

} catch (Exception $e) {
    echo colorize("\n✗ ERROR: Failed to create user\n", 'red', true);
    echo "  {$e->getMessage()}\n\n";
    exit(1);
}
