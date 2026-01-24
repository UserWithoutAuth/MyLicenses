<?php
/**
 * RSA Keypair Generation Script (Windows Compatible)
 *
 * Generates a secure RSA 4096-bit keypair for the license server
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
    'cyan' => "\033[36m",
    'bold' => "\033[1m",
];

function colorize($text, $color, $bold = false) {
    global $colors;
    $style = $bold ? $colors['bold'] : '';
    return $style . $colors[$color] . $text . $colors['reset'];
}

echo colorize("\n╔═══════════════════════════════════════════════════════════╗\n", 'cyan', true);
echo colorize("║     LICENSE SERVER - KEYPAIR GENERATOR (WINDOWS)          ║\n", 'cyan', true);
echo colorize("╚═══════════════════════════════════════════════════════════╝\n\n", 'cyan', true);

// Check OpenSSL extension
if (!extension_loaded('openssl')) {
    echo colorize("✗ ERROR: OpenSSL extension is not loaded!\n", 'red', true);
    echo "  Please install/enable the OpenSSL PHP extension.\n\n";
    exit(1);
}

// Find OpenSSL config file
function findOpenSSLConfig() {
    $possiblePaths = [
        // Common Windows paths
        'C:/xampp/apache/bin/openssl.cnf',
        'C:/xampp/php/extras/ssl/openssl.cnf',
        'C:/php/extras/ssl/openssl.cnf',
        'C:/wamp/bin/apache/apache2.4.46/conf/openssl.cnf',
        'C:/wamp64/bin/php/php7.4.9/extras/ssl/openssl.cnf',
        // Try to find from PHP binary path
        dirname(PHP_BINARY) . '/extras/ssl/openssl.cnf',
        dirname(PHP_BINARY) . '/../apache/bin/openssl.cnf',
    ];

    // Check environment variable
    $envConfig = getenv('OPENSSL_CONF');
    if ($envConfig && file_exists($envConfig)) {
        return $envConfig;
    }

    // Check common paths
    foreach ($possiblePaths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }

    return null;
}

// Detect Windows and configure OpenSSL
$isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
$opensslConfig = null;

if ($isWindows) {
    echo colorize("🖥️  Windows detected - configuring OpenSSL...\n", 'blue');

    $configPath = findOpenSSLConfig();

    if ($configPath) {
        echo colorize("✓ Found OpenSSL config: $configPath\n", 'green');
        $opensslConfig = $configPath;
        putenv("OPENSSL_CONF=$configPath");
    } else {
        echo colorize("⚠ WARNING: OpenSSL config file not found!\n", 'yellow', true);
        echo "  Searching common paths...\n";
        echo "  PHP Binary: " . PHP_BINARY . "\n";
        echo "  OPENSSL_CONF env: " . (getenv('OPENSSL_CONF') ?: 'Not set') . "\n\n";
        echo colorize("  We'll try to generate without config file.\n", 'yellow');
        echo colorize("  If this fails, manually set OPENSSL_CONF environment variable.\n\n", 'yellow');

        // Try to continue anyway
        $opensslConfig = null;
    }
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
    echo "\n  Do you want to OVERWRITE them? (yes/no): ";

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

// Get password input
if ($isWindows) {
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

// Configure OpenSSL for key generation
$config = [
    'private_key_bits' => 4096,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
    'digest_alg' => 'sha512',
];

// Only add config path if found (Windows)
if ($opensslConfig !== null) {
    $config['config'] = $opensslConfig;
}

// Try to generate keypair
$keypair = @openssl_pkey_new($config);

// If failed and on Windows, try alternative method
if (!$keypair && $isWindows) {
    echo colorize("⚠ First attempt failed, trying alternative method...\n", 'yellow');

    // Try without explicit config
    unset($config['config']);
    $keypair = @openssl_pkey_new($config);

    // If still failed, try with smaller key size as fallback
    if (!$keypair) {
        echo colorize("⚠ Trying with 2048-bit key as fallback...\n", 'yellow');
        $config['private_key_bits'] = 2048;
        $keypair = @openssl_pkey_new($config);
    }
}

if (!$keypair) {
    echo colorize("✗ ERROR: Failed to generate keypair!\n", 'red', true);

    $error = openssl_error_string();
    if ($error) {
        echo "  OpenSSL Error: $error\n";
    }

    echo "\n";
    echo colorize("TROUBLESHOOTING:\n", 'yellow', true);
    echo "1. Set OPENSSL_CONF environment variable:\n";
    echo "   set OPENSSL_CONF=C:\\path\\to\\openssl.cnf\n\n";
    echo "2. Find openssl.cnf in your PHP installation:\n";
    echo "   - Look in: C:\\xampp\\apache\\bin\\openssl.cnf\n";
    echo "   - Or: C:\\php\\extras\\ssl\\openssl.cnf\n\n";
    echo "3. Or use the manual method below.\n\n";

    echo colorize("MANUAL METHOD (ALTERNATIVE):\n", 'cyan', true);
    echo "Run these OpenSSL commands directly:\n\n";
    echo "# Generate private key\n";
    echo "openssl genrsa -aes256 -out storage/keys/server_private.key 4096\n\n";
    echo "# Generate public key\n";
    echo "openssl rsa -in storage/keys/server_private.key -pubout -out storage/keys/server_public.key\n\n";

    exit(1);
}

$generationTime = round((microtime(true) - $startTime) * 1000, 2);
echo colorize("✓ Keypair generated successfully! ({$generationTime}ms)\n\n", 'green');

// Export private key
$exportSuccess = @openssl_pkey_export($keypair, $privateKey, $passphrase, $config);

if (!$exportSuccess) {
    echo colorize("✗ ERROR: Failed to export private key!\n", 'red', true);
    echo "  OpenSSL Error: " . openssl_error_string() . "\n\n";
    exit(1);
}

// Export public key
$details = openssl_pkey_get_details($keypair);
$publicKey = $details['key'];

// Save keys to files
echo "💾 Saving keys to disk...\n";

// Save private key
file_put_contents($privateKeyFile, $privateKey);
chmod($privateKeyFile, 0400); // Read-only for owner

// Save public key
file_put_contents($publicKeyFile, $publicKey);
chmod($publicKeyFile, 0444); // Read-only for everyone

echo colorize("✓ Keys saved successfully!\n\n", 'green');

// Display results
echo colorize("═══════════════════════════════════════════════════════════\n", 'cyan', true);
echo colorize("SUCCESS! 🎉\n", 'green', true);
echo colorize("═══════════════════════════════════════════════════════════\n\n", 'cyan', true);

echo colorize("Key Details:\n", 'blue', true);
echo "  • Private Key: $privateKeyFile\n";
echo "  • Public Key:  $publicKeyFile\n";
echo "  • Key Size:    " . $details['bits'] . " bits\n";
echo "  • Algorithm:   " . $details['type'] . "\n";
echo "  • Passphrase:  " . (empty($passphrase) ? "None (unencrypted)" : "Protected") . "\n";
echo "\n";

echo colorize("Next Steps:\n", 'yellow', true);
echo "  1. Update .env file with key paths:\n";
echo "     SERVER_PRIVATE_KEY=$privateKeyFile\n";
echo "     SERVER_PUBLIC_KEY=$publicKeyFile\n";
if (!empty($passphrase)) {
    echo "     KEY_PASSPHRASE=your_passphrase\n";
}
echo "\n";
echo "  2. BACKUP the private key to secure offline storage!\n";
echo "  3. NEVER commit the private key to version control!\n";
echo "  4. Test key loading in your application\n";
echo "\n";

echo colorize("Security Reminders:\n", 'red', true);
echo "  ⚠️  Keep the private key SECRET and SECURE!\n";
echo "  ⚠️  The private key file is now read-only (chmod 400)\n";
echo "  ⚠️  Share only the PUBLIC key with clients\n";
echo "  ⚠️  Consider deleting this script after key generation\n";
echo "\n";

echo colorize("═══════════════════════════════════════════════════════════\n\n", 'cyan', true);

// Display public key for easy copying
echo colorize("Public Key (for client applications):\n", 'blue', true);
echo colorize(str_repeat("─", 60) . "\n", 'blue');
echo $publicKey;
echo colorize(str_repeat("─", 60) . "\n\n", 'blue');

echo colorize("✓ Keypair generation complete!\n\n", 'green', true);

exit(0);
