<?php

namespace App\Services;

use App\Models\User;
use PragmaRX\Google2FA\Google2FA;
use Exception;

/**
 * Authentication Service
 *
 * Handles user authentication, 2FA, and session management
 *
 * @package LicenseServer\Services
 */
class AuthService
{
    private Google2FA $google2fa;

    public function __construct()
    {
        $this->google2fa = new Google2FA();
    }

    /**
     * Attempt to login a user
     *
     * @param string $emailOrUsername
     * @param string $password
     * @return array Login result
     */
    public function login(string $emailOrUsername, string $password): array
    {
        // Find user by email or username
        $user = User::where('email', $emailOrUsername)
            ->orWhere('username', $emailOrUsername)
            ->first();

        if (!$user) {
            return [
                'success' => false,
                'error' => 'Invalid credentials'
            ];
        }

        // Check if user is active
        if (!$user->is_active) {
            return [
                'success' => false,
                'error' => 'Account is disabled'
            ];
        }

        // Check if account is locked
        if ($user->isLocked()) {
            return [
                'success' => false,
                'error' => 'Account is temporarily locked due to too many failed attempts. Please try again later.',
                'locked_until' => $user->locked_until->toDateTimeString()
            ];
        }

        // Verify password
        if (!$user->verifyPassword($password)) {
            $user->incrementFailedLogins();

            return [
                'success' => false,
                'error' => 'Invalid credentials'
            ];
        }

        // Check IP whitelist
        $clientIp = get_client_ip();
        if (!$user->isIpWhitelisted($clientIp)) {
            audit_log('user.login_blocked_ip', [
                'user_id' => $user->id,
                'ip' => $clientIp
            ]);

            return [
                'success' => false,
                'error' => 'Access denied from this IP address'
            ];
        }

        // Check if 2FA is enabled
        if ($user->two_factor_enabled) {
            // Don't record login yet, wait for 2FA verification
            return [
                'success' => true,
                'requires_2fa' => true,
                'user_id' => $user->id
            ];
        }

        // Record login and create session
        $user->recordLogin();
        $this->createSession($user);

        return [
            'success' => true,
            'requires_2fa' => false,
            'user' => $this->getUserData($user)
        ];
    }

    /**
     * Verify 2FA code and complete login
     *
     * @param int $userId
     * @param string $code
     * @return array
     */
    public function verify2FA(int $userId, string $code): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        if (!$user->two_factor_enabled) {
            return [
                'success' => false,
                'error' => '2FA is not enabled for this account'
            ];
        }

        $secret = $user->get2FASecret();
        $valid = $this->google2fa->verifyKey($secret, $code);

        if (!$valid) {
            // Try recovery code
            if ($user->verifyRecoveryCode($code)) {
                $user->recordLogin();
                $this->createSession($user);

                return [
                    'success' => true,
                    'recovery_code_used' => true,
                    'user' => $this->getUserData($user)
                ];
            }

            $user->incrementFailedLogins();

            return [
                'success' => false,
                'error' => 'Invalid 2FA code'
            ];
        }

        // 2FA verified, complete login
        $user->recordLogin();
        $this->createSession($user);

        return [
            'success' => true,
            'user' => $this->getUserData($user)
        ];
    }

    /**
     * Enable 2FA for user
     *
     * @param int $userId
     * @return array
     */
    public function enable2FA(int $userId): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        if ($user->two_factor_enabled) {
            return [
                'success' => false,
                'error' => '2FA is already enabled'
            ];
        }

        // Generate secret
        $secret = $this->google2fa->generateSecretKey();

        // Generate QR code URL
        $qrCodeUrl = $this->google2fa->getQRCodeUrl(
            env('APP_NAME', 'License Server'),
            $user->email,
            $secret
        );

        // Enable 2FA (this also generates recovery codes)
        $user->enable2FA($secret);
        $recoveryCodes = $user->generateRecoveryCodes();

        return [
            'success' => true,
            'secret' => $secret,
            'qr_code_url' => $qrCodeUrl,
            'recovery_codes' => $recoveryCodes
        ];
    }

    /**
     * Disable 2FA for user
     *
     * @param int $userId
     * @param string $password
     * @return array
     */
    public function disable2FA(int $userId, string $password): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        // Verify password for security
        if (!$user->verifyPassword($password)) {
            return [
                'success' => false,
                'error' => 'Invalid password'
            ];
        }

        $user->disable2FA();

        return [
            'success' => true,
            'message' => '2FA has been disabled'
        ];
    }

    /**
     * Create session for user
     *
     * @param User $user
     * @return void
     */
    private function createSession(User $user): void
    {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Regenerate session ID to prevent session fixation
        session_regenerate_id(true);

        // Store user data in session
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user_role'] = $user->role;
        $_SESSION['user_email'] = $user->email;
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        $_SESSION['ip_address'] = get_client_ip();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? null;

        // Store in database sessions table
        $this->storeSessionInDb($user);
    }

    /**
     * Store session in database
     *
     * @param User $user
     * @return void
     */
    private function storeSessionInDb(User $user): void
    {
        try {
            \Illuminate\Database\Capsule\Manager::table('sessions')->insert([
                'id' => session_id(),
                'user_id' => $user->id,
                'ip_address' => get_client_ip(),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
                'payload' => json_encode($_SESSION),
                'device_fingerprint' => $this->generateDeviceFingerprint(),
                'last_activity' => now(),
                'created_at' => now()
            ]);
        } catch (Exception $e) {
            logger()->warning('Failed to store session in database', [
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate device fingerprint
     *
     * @return string
     */
    private function generateDeviceFingerprint(): string
    {
        $components = [
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
            $_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''
        ];

        return hash('sha256', implode('|', $components));
    }

    /**
     * Get current authenticated user
     *
     * @return User|null
     */
    public function getCurrentUser(): ?User
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            return null;
        }

        // Check session timeout
        $sessionLifetime = env('ADMIN_SESSION_TIMEOUT', 1800); // 30 minutes
        if (isset($_SESSION['last_activity'])) {
            if (time() - $_SESSION['last_activity'] > $sessionLifetime) {
                $this->logout();
                return null;
            }
        }

        // Check absolute timeout
        $absoluteTimeout = env('ADMIN_ABSOLUTE_TIMEOUT', 28800); // 8 hours
        if (isset($_SESSION['login_time'])) {
            if (time() - $_SESSION['login_time'] > $absoluteTimeout) {
                $this->logout();
                return null;
            }
        }

        // Update last activity
        $_SESSION['last_activity'] = time();

        return User::find($_SESSION['user_id']);
    }

    /**
     * Check if user is authenticated
     *
     * @return bool
     */
    public function isAuthenticated(): bool
    {
        return $this->getCurrentUser() !== null;
    }

    /**
     * Check if user has permission
     *
     * @param string $permission
     * @return bool
     */
    public function can(string $permission): bool
    {
        $user = $this->getCurrentUser();

        if (!$user) {
            return false;
        }

        return $user->can($permission);
    }

    /**
     * Logout user
     *
     * @return void
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionId = session_id();

        // Remove from database
        try {
            \Illuminate\Database\Capsule\Manager::table('sessions')
                ->where('id', $sessionId)
                ->delete();
        } catch (Exception $e) {
            logger()->warning('Failed to delete session from database', [
                'error' => $e->getMessage()
            ]);
        }

        // Clear session data
        $_SESSION = [];

        // Delete session cookie
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        // Destroy session
        session_destroy();
    }

    /**
     * Get user data for response
     *
     * @param User $user
     * @return array
     */
    private function getUserData(User $user): array
    {
        return [
            'id' => $user->id,
            'uuid' => $user->uuid,
            'email' => $user->email,
            'username' => $user->username,
            'name' => $user->name,
            'role' => $user->role,
            'two_factor_enabled' => $user->two_factor_enabled,
            'last_login_at' => $user->last_login_at ? $user->last_login_at->toDateTimeString() : null
        ];
    }

    /**
     * Change password
     *
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return array
     */
    public function changePassword(int $userId, string $currentPassword, string $newPassword): array
    {
        $user = User::find($userId);

        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }

        // Verify current password
        if (!$user->verifyPassword($currentPassword)) {
            return [
                'success' => false,
                'error' => 'Current password is incorrect'
            ];
        }

        // Validate new password
        $validation = $this->validatePassword($newPassword);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error' => $validation['error']
            ];
        }

        // Set new password
        $user->setPassword($newPassword);

        audit_log('user.password_changed', ['user_id' => $user->id]);

        return [
            'success' => true,
            'message' => 'Password changed successfully'
        ];
    }

    /**
     * Validate password strength
     *
     * @param string $password
     * @return array
     */
    public function validatePassword(string $password): array
    {
        $minLength = env('PASSWORD_MIN_LENGTH', 12);

        if (strlen($password) < $minLength) {
            return [
                'valid' => false,
                'error' => "Password must be at least {$minLength} characters long"
            ];
        }

        if (env('PASSWORD_REQUIRE_UPPERCASE', true) && !preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'error' => 'Password must contain at least one uppercase letter'
            ];
        }

        if (env('PASSWORD_REQUIRE_LOWERCASE', true) && !preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'error' => 'Password must contain at least one lowercase letter'
            ];
        }

        if (env('PASSWORD_REQUIRE_NUMBERS', true) && !preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'error' => 'Password must contain at least one number'
            ];
        }

        if (env('PASSWORD_REQUIRE_SYMBOLS', true) && !preg_match('/[^A-Za-z0-9]/', $password)) {
            return [
                'valid' => false,
                'error' => 'Password must contain at least one special character'
            ];
        }

        return ['valid' => true];
    }
}
