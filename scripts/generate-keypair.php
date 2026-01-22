<?php
/**
 * RSA Keypair Generation Script
 *
 * Generates a secure RSA 4096-bit keypair for the license server
 *
 * IMPORTANT SECURITY NOTES:
 * 1. Run this script ONCE during initial setup
 * 2. DELETE this script after generating keys
 * 3. NEVER commit private keys to version control
 * 4. BACKUP private key to secure offline storage
 * 5. Use a strong passphrase for the private key
 *
 * Usage: php scripts/generate-keypair.php
 *
 * @package LicenseServer
 */

define('BASE_PATH', dirname(__DIR__));

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    die('This script must be run from command line for security reasons.' . PHP_EOL);
}

// Colors for CLI output
$colors = [
    'reset' => "\033[0m",
    'red' => "\033[31m",
    'green' => "\033[32m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'magenta' => "\033[35m",
    'cyan' => "\033[36m",
    'white' => "\033[37m",
    'bold' => "\033[1m",
];

function colorize($text, $color, $bold = false) {
    global $colors;
    $style = $bold ? $colors['bold'] : '';
    return $style . $colors[$color] . $text . $colors['reset'];
}

echo colorize("\n╔═══════════════════════════════════════════════════════════╗\n", 'cyan', true);
echo colorize("║          LICENSE SERVER - KEYPAIR GENERATOR               ║\n", 'cyan', true);
echo colorize("╚═══════════════════════════════════════════════════════════╝\n\n", 'cyan', true);

// Check OpenSSL extension
if (!extension_loaded('openssl')) {
    echo colorize("✗ ERROR: OpenSSL extension is not loaded!\n", 'red', true);
    echo "  Please install/enable the OpenSSL PHP extension.\n\n";
    exit(1);
}

// Prepare storage directory
$keyPath = BASE_PATH . '/storage/keys';

if (!is_dir($keyPath)) {
    mkdir($keyPath, 0755, true);
    echo colorize("✓ Created keys directory: $keyPath\n", 'green');
}

// Check if keys already exist
$privateKeyFile = $keyPath . '/server_private.key';
$publicKeyFile = $keyPath . '/server_public.key';

if (file_exists($privateKeyFile) || file_exists($publicKeyFile)) {
    echo colorize("\n⚠ WARNING: Keypair files already exist!\n", 'yellow', true);
    echo "  Existing files:\n";
    if (file_exists($privateKeyFile)) echo "  - $privateKeyFile\n";
    if (file_exists($publicKeyFile)) echo "  - $publicKeyFile\n";
    echo "\n  Do you want to OVERWRITE them? This cannot be undone! (yes/no): ";

    $confirmation = trim(fgets(STDIN));

    if (strtolower($confirmation) !== 'yes') {
        echo colorize("\n✓ Operation cancelled. Existing keys preserved.\n\n", 'green');
        exit(0);
    }

    echo colorize("\n⚠ Proceeding with key regeneration...\n", 'yellow');
}

// Get passphrase
echo colorize("\n" . str_repeat("─", 60) . "\n", 'blue');
echo colorize("PASSPHRASE CONFIGURATION\n", 'blue', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'blue');

echo "Enter a strong passphrase for the private key.\n";
echo colorize("Leave empty for no passphrase (NOT RECOMMENDED).\n\n", 'yellow');
echo "Passphrase: ";

// Hide password input
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $passphrase = trim(fgets(STDIN));
} else {
    system('stty -echo');
    $passphrase = trim(fgets(STDIN));
    system('stty echo');
    echo "\n";
}

if (empty($passphrase)) {
    echo colorize("\n⚠ WARNING: No passphrase set. Private key will be unencrypted!\n", 'yellow', true);
    echo "  Are you sure? (yes/no): ";

    $confirmation = trim(fgets(STDIN));

    if (strtolower($confirmation) !== 'yes') {
        echo colorize("\n✓ Operation cancelled.\n\n", 'green');
        exit(0);
    }
}

// Generate keypair
echo colorize("\n" . str_repeat("─", 60) . "\n", 'blue');
echo colorize("GENERATING RSA KEYPAIR\n", 'blue', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'blue');

echo "⏳ Generating 4096-bit RSA keypair...\n";
echo "   This may take a few seconds...\n\n";

$startTime = microtime(true);

$config = [
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha512',
    'config' => null,
];

$keypair = openssl_pkey_new($config);

if (!$keypair) {
    echo colorize("✗ ERROR: Failed to generate keypair!\n", 'red', true);
    echo "  OpenSSL Error: " . openssl_error_string() . "\n\n";
    exit(1);
}

$generationTime = round((microtime(true) - $startTime) * 1000, 2);
echo colorize("✓ Keypair generated successfully! ({$generationTime}ms)\n\n", 'green');

// Export private key
openssl_pkey_export($keypair, $privateKey, $passphrase);

// Export public key
$details = openssl_pkey_get_details($keypair);
$publicKey = $details['key'];

// Save keys to files
echo "💾 Saving keys to disk...\n\n";

file_put_contents($privateKeyFile, $privateKey);
file_put_contents($publicKeyFile, $publicKey);

// Set secure permissions
chmod($privateKeyFile, 0400);  // Read-only for owner
chmod($publicKeyFile, 0444);   // Read-only for all

echo colorize("✓ Private key saved: $privateKeyFile (permissions: 400)\n", 'green');
echo colorize("✓ Public key saved: $publicKeyFile (permissions: 444)\n\n", 'green');

// Create .htaccess protection
$htaccessFile = $keyPath . '/.htaccess';
$htaccessContent = "# CRITICAL SECURITY: DENY ALL ACCESS\n";
$htaccessContent .= "Order deny,allow\n";
$htaccessContent .= "Deny from all\n";

file_put_contents($htaccessFile, $htaccessContent);
echo colorize("✓ Created .htaccess protection\n", 'green');

// Display key information
echo colorize("\n" . str_repeat("─", 60) . "\n", 'blue');
echo colorize("KEY INFORMATION\n", 'blue', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'blue');

echo "Key Size:     4096 bits\n";
echo "Key Type:     RSA\n";
echo "Digest:       SHA-512\n";
echo "Passphrase:   " . (empty($passphrase) ? colorize("No (INSECURE)", 'red') : colorize("Yes ✓", 'green')) . "\n";
echo "Private Key:  $privateKeyFile\n";
echo "Public Key:   $publicKeyFile\n";

// Generate fingerprint
$publicKeyFingerprint = hash('sha256', $publicKey);
echo "Fingerprint:  " . substr($publicKeyFingerprint, 0, 16) . "...\n";

// Environment variable configuration
echo colorize("\n" . str_repeat("─", 60) . "\n", 'magenta');
echo colorize("ENVIRONMENT CONFIGURATION\n", 'magenta', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'magenta');

echo "Add these lines to your .env file:\n\n";
echo colorize("SERVER_PRIVATE_KEY=$privateKeyFile\n", 'cyan');
echo colorize("SERVER_PUBLIC_KEY=$publicKeyFile\n", 'cyan');

if (!empty($passphrase)) {
    echo colorize("KEY_PASSPHRASE=" . str_repeat('*', strlen($passphrase)) . "\n", 'cyan');
    echo colorize("\n⚠ Replace the asterisks with your actual passphrase!\n", 'yellow');
}

// Security warnings
echo colorize("\n" . str_repeat("═", 60) . "\n", 'red');
echo colorize("CRITICAL SECURITY WARNINGS\n", 'red', true);
echo colorize(str_repeat("═", 60) . "\n\n", 'red');

echo colorize("1. ", 'red', true) . "DELETE THIS SCRIPT NOW:\n";
echo "   rm -f " . __FILE__ . "\n\n";

echo colorize("2. ", 'red', true) . "BACKUP YOUR PRIVATE KEY IMMEDIATELY:\n";
echo "   - Copy to encrypted USB drive\n";
echo "   - Store in password manager\n";
echo "   - Keep in secure offline location\n\n";

echo colorize("3. ", 'red', true) . "NEVER COMMIT PRIVATE KEY TO VERSION CONTROL:\n";
echo "   - Add to .gitignore: storage/keys/*.key\n";
echo "   - Check existing commits for leaks\n\n";

echo colorize("4. ", 'red', true) . "VERIFY FILE PERMISSIONS:\n";
echo "   ls -la $keyPath/\n\n";

echo colorize("5. ", 'red', true) . "PROTECT YOUR PASSPHRASE:\n";
echo "   - Don't share it with anyone\n";
echo "   - Don't store it in plain text\n";
echo "   - Use a password manager\n\n";

// Display public key for embedding in clients
echo colorize(str_repeat("─", 60) . "\n", 'blue');
echo colorize("PUBLIC KEY (for client applications)\n", 'blue', true);
echo colorize(str_repeat("─", 60) . "\n\n", 'blue');

echo "Embed this public key in your client applications:\n\n";
echo colorize($publicKey . "\n", 'cyan');

echo colorize(str_repeat("═", 60) . "\n", 'green');
echo colorize("✓ KEYPAIR GENERATION COMPLETE!\n", 'green', true);
echo colorize(str_repeat("═", 60) . "\n\n", 'green');

echo "Next steps:\n";
echo "1. Update your .env file with the key paths\n";
echo "2. Delete this script: rm -f " . __FILE__ . "\n";
echo "3. Backup your private key securely\n";
echo "4. Test the license server\n\n";

echo colorize("⚠ Remember to delete this script after use!\n\n", 'yellow', true);
