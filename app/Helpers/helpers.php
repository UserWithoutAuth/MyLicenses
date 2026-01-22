<?php
/**
 * Global Helper Functions
 *
 * @package LicenseServer
 */

if (!function_exists('env')) {
    /**
     * Get environment variable value
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false) {
            return $default;
        }

        // Convert string boolean to actual boolean
        if (in_array(strtolower($value), ['true', 'false', '1', '0', 'yes', 'no', 'on', 'off'])) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $value;
    }
}

if (!function_exists('config')) {
    /**
     * Get configuration value
     *
     * @param string $key Dot notation key (e.g., 'database.host')
     * @param mixed $default
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        static $cache = [];

        $parts = explode('.', $key);
        $file = array_shift($parts);
        $configFile = CONFIG_PATH . '/' . $file . '.php';

        if (!isset($cache[$file]) && file_exists($configFile)) {
            $cache[$file] = require $configFile;
        }

        $value = $cache[$file] ?? [];

        foreach ($parts as $part) {
            if (!isset($value[$part])) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

if (!function_exists('app_path')) {
    /**
     * Get application path
     *
     * @param string $path
     * @return string
     */
    function app_path(string $path = ''): string
    {
        return APP_PATH . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage path
     *
     * @param string $path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return STORAGE_PATH . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('public_path')) {
    /**
     * Get public path
     *
     * @param string $path
     * @return string
     */
    function public_path(string $path = ''): string
    {
        return PUBLIC_PATH . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('base_path')) {
    /**
     * Get base path
     *
     * @param string $path
     * @return string
     */
    function base_path(string $path = ''): string
    {
        return BASE_PATH . ($path ? DIRECTORY_SEPARATOR . $path : '');
    }
}

if (!function_exists('response_json')) {
    /**
     * Send JSON response
     *
     * @param mixed $data
     * @param int $statusCode
     * @param array $headers
     * @return void
     */
    function response_json($data, int $statusCode = 200, array $headers = []): void
    {
        http_response_code($statusCode);

        header('Content-Type: application/json');
        foreach ($headers as $key => $value) {
            header("$key: $value");
        }

        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }
}

if (!function_exists('abort')) {
    /**
     * Abort with error response
     *
     * @param int $code
     * @param string $message
     * @param array $data
     * @return void
     */
    function abort(int $code = 500, string $message = '', array $data = []): void
    {
        $messages = [
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            503 => 'Service Unavailable'
        ];

        $message = $message ?: ($messages[$code] ?? 'Error');

        response_json(array_merge([
            'error' => $message,
            'code' => $code
        ], $data), $code);
    }
}

if (!function_exists('logger')) {
    /**
     * Get logger instance
     *
     * @return \Monolog\Logger
     */
    function logger()
    {
        static $logger = null;

        if ($logger === null) {
            $logger = new \Monolog\Logger('license-server');

            $logPath = storage_path('logs');
            if (!is_dir($logPath)) {
                mkdir($logPath, 0755, true);
            }

            $handler = new \Monolog\Handler\StreamHandler(
                $logPath . '/app.log',
                \Monolog\Level::fromName(strtoupper(env('LOG_LEVEL', 'warning')))
            );

            $formatter = new \Monolog\Formatter\LineFormatter(
                "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s',
                true,
                true
            );

            $handler->setFormatter($formatter);
            $logger->pushHandler($handler);
        }

        return $logger;
    }
}

if (!function_exists('audit_log')) {
    /**
     * Log audit event
     *
     * @param string $action
     * @param array $data
     * @param int|null $userId
     * @return void
     */
    function audit_log(string $action, array $data = [], ?int $userId = null): void
    {
        if (!env('AUDIT_LOG_ENABLED', true)) {
            return;
        }

        $logPath = storage_path('logs/audit.log');
        $logDir = dirname($logPath);

        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'action' => $action,
            'user_id' => $userId,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'data' => $data
        ];

        file_put_contents($logPath, json_encode($entry) . PHP_EOL, FILE_APPEND);
    }
}

if (!function_exists('generate_uuid')) {
    /**
     * Generate UUID v4
     *
     * @return string
     */
    function generate_uuid(): string
    {
        return \Ramsey\Uuid\Uuid::uuid4()->toString();
    }
}

if (!function_exists('generate_token')) {
    /**
     * Generate secure random token
     *
     * @param int $length
     * @return string
     */
    function generate_token(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }
}

if (!function_exists('hash_password')) {
    /**
     * Hash password using bcrypt
     *
     * @param string $password
     * @return string
     */
    function hash_password(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('verify_password')) {
    /**
     * Verify password against hash
     *
     * @param string $password
     * @param string $hash
     * @return bool
     */
    function verify_password(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}

if (!function_exists('encrypt_data')) {
    /**
     * Encrypt data using application key
     *
     * @param mixed $data
     * @return string
     * @throws Exception
     */
    function encrypt_data($data): string
    {
        $key = env('APP_KEY');
        if (empty($key)) {
            throw new Exception('APP_KEY not set');
        }

        // Remove 'base64:' prefix if present
        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7));
        }

        $method = env('CIPHER_METHOD', 'AES-256-GCM');
        $iv = random_bytes(openssl_cipher_iv_length($method));

        if ($method === 'AES-256-GCM') {
            $tag = '';
            $encrypted = openssl_encrypt(
                json_encode($data),
                $method,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                '',
                16
            );

            return base64_encode($iv . $tag . $encrypted);
        } else {
            $encrypted = openssl_encrypt(
                json_encode($data),
                $method,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            return base64_encode($iv . $encrypted);
        }
    }
}

if (!function_exists('decrypt_data')) {
    /**
     * Decrypt data using application key
     *
     * @param string $encrypted
     * @return mixed
     * @throws Exception
     */
    function decrypt_data(string $encrypted)
    {
        $key = env('APP_KEY');
        if (empty($key)) {
            throw new Exception('APP_KEY not set');
        }

        // Remove 'base64:' prefix if present
        if (strpos($key, 'base64:') === 0) {
            $key = base64_decode(substr($key, 7));
        }

        $method = env('CIPHER_METHOD', 'AES-256-GCM');
        $data = base64_decode($encrypted);

        $ivLength = openssl_cipher_iv_length($method);
        $iv = substr($data, 0, $ivLength);

        if ($method === 'AES-256-GCM') {
            $tag = substr($data, $ivLength, 16);
            $ciphertext = substr($data, $ivLength + 16);

            $decrypted = openssl_decrypt(
                $ciphertext,
                $method,
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );
        } else {
            $ciphertext = substr($data, $ivLength);
            $decrypted = openssl_decrypt(
                $ciphertext,
                $method,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );
        }

        if ($decrypted === false) {
            throw new Exception('Decryption failed');
        }

        return json_decode($decrypted, true);
    }
}

if (!function_exists('sanitize_input')) {
    /**
     * Sanitize user input
     *
     * @param mixed $data
     * @return mixed
     */
    function sanitize_input($data)
    {
        if (is_array($data)) {
            return array_map('sanitize_input', $data);
        }

        if (is_string($data)) {
            return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
        }

        return $data;
    }
}

if (!function_exists('get_client_ip')) {
    /**
     * Get client IP address
     *
     * @return string
     */
    function get_client_ip(): string
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (strpos($ip, ',') !== false) {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('is_json')) {
    /**
     * Check if string is valid JSON
     *
     * @param string $string
     * @return bool
     */
    function is_json(string $string): bool
    {
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}

if (!function_exists('now')) {
    /**
     * Get current timestamp
     *
     * @return string
     */
    function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('dd')) {
    /**
     * Dump and die (for debugging)
     *
     * @param mixed ...$vars
     * @return void
     */
    function dd(...$vars): void
    {
        foreach ($vars as $var) {
            echo '<pre>';
            var_dump($var);
            echo '</pre>';
        }
        die();
    }
}
