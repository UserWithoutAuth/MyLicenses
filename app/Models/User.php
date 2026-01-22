<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * User Model
 *
 * Represents admin/staff users with role-based access
 *
 * @package LicenseServer\Models
 */
class User extends Model
{
    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'email',
        'username',
        'password_hash',
        'name',
        'role',
        'is_active',
        'email_verified_at',
        'two_factor_secret',
        'two_factor_enabled',
        'two_factor_recovery_codes',
        'password_changed_at',
        'last_login_at',
        'last_login_ip',
        'failed_login_attempts',
        'locked_until',
        'ip_whitelist',
        'metadata'
    ];

    protected $hidden = [
        'password_hash',
        'two_factor_secret',
        'two_factor_recovery_codes'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime',
        'locked_until' => 'datetime',
        'is_active' => 'boolean',
        'two_factor_enabled' => 'boolean',
        'two_factor_recovery_codes' => 'array',
        'ip_whitelist' => 'array',
        'metadata' => 'array',
        'failed_login_attempts' => 'integer'
    ];

    /**
     * Boot function
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (!$user->uuid) {
                $user->uuid = generate_uuid();
            }
        });
    }

    /**
     * Get API tokens for this user
     */
    public function apiTokens()
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Set password
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = hash_password($password);
        $this->password_changed_at = now();
        $this->save();
    }

    /**
     * Verify password
     */
    public function verifyPassword(string $password): bool
    {
        return verify_password($password, $this->password_hash);
    }

    /**
     * Check if user is locked
     */
    public function isLocked(): bool
    {
        if (!$this->locked_until) {
            return false;
        }

        if ($this->locked_until->isFuture()) {
            return true;
        }

        // Lock expired, clear it
        $this->locked_until = null;
        $this->failed_login_attempts = 0;
        $this->save();

        return false;
    }

    /**
     * Increment failed login attempts
     */
    public function incrementFailedLogins(): void
    {
        $this->increment('failed_login_attempts');

        $maxAttempts = env('MAX_LOGIN_ATTEMPTS', 5);
        $lockoutDuration = env('LOGIN_LOCKOUT_DURATION', 900); // 15 minutes

        if ($this->failed_login_attempts >= $maxAttempts) {
            $this->locked_until = now()->addSeconds($lockoutDuration);
            $this->save();

            audit_log('user.locked', [
                'user_id' => $this->id,
                'attempts' => $this->failed_login_attempts
            ]);
        }
    }

    /**
     * Reset failed login attempts
     */
    public function resetFailedLogins(): void
    {
        $this->failed_login_attempts = 0;
        $this->locked_until = null;
        $this->save();
    }

    /**
     * Record login
     */
    public function recordLogin(): void
    {
        $this->last_login_at = now();
        $this->last_login_ip = get_client_ip();
        $this->resetFailedLogins();
        $this->save();

        audit_log('user.login', [
            'user_id' => $this->id,
            'ip' => $this->last_login_ip
        ]);
    }

    /**
     * Enable 2FA
     */
    public function enable2FA(string $secret): void
    {
        $this->two_factor_secret = encrypt_data($secret);
        $this->two_factor_enabled = true;
        $this->generateRecoveryCodes();
        $this->save();

        audit_log('user.2fa_enabled', ['user_id' => $this->id]);
    }

    /**
     * Disable 2FA
     */
    public function disable2FA(): void
    {
        $this->two_factor_secret = null;
        $this->two_factor_enabled = false;
        $this->two_factor_recovery_codes = null;
        $this->save();

        audit_log('user.2fa_disabled', ['user_id' => $this->id]);
    }

    /**
     * Get decrypted 2FA secret
     */
    public function get2FASecret(): ?string
    {
        if (!$this->two_factor_secret) {
            return null;
        }

        return decrypt_data($this->two_factor_secret);
    }

    /**
     * Generate recovery codes
     */
    public function generateRecoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(4)));
        }

        $this->two_factor_recovery_codes = array_map('hash_password', $codes);
        $this->save();

        return $codes; // Return plain codes for user to save
    }

    /**
     * Verify recovery code
     */
    public function verifyRecoveryCode(string $code): bool
    {
        if (!$this->two_factor_recovery_codes) {
            return false;
        }

        foreach ($this->two_factor_recovery_codes as $index => $hashedCode) {
            if (verify_password(strtoupper($code), $hashedCode)) {
                // Remove used code
                $codes = $this->two_factor_recovery_codes;
                unset($codes[$index]);
                $this->two_factor_recovery_codes = array_values($codes);
                $this->save();

                audit_log('user.recovery_code_used', ['user_id' => $this->id]);

                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP is whitelisted
     */
    public function isIpWhitelisted(string $ip): bool
    {
        if (!$this->ip_whitelist || empty($this->ip_whitelist)) {
            return true; // No whitelist means all IPs allowed
        }

        foreach ($this->ip_whitelist as $allowedIp) {
            if ($this->ipMatch($ip, $allowedIp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if IP matches pattern
     */
    private function ipMatch(string $ip, string $pattern): bool
    {
        if ($ip === $pattern) {
            return true;
        }

        // CIDR notation
        if (strpos($pattern, '/') !== false) {
            list($subnet, $mask) = explode('/', $pattern);
            $ipLong = ip2long($ip);
            $subnetLong = ip2long($subnet);
            $maskLong = -1 << (32 - (int)$mask);

            return ($ipLong & $maskLong) === ($subnetLong & $maskLong);
        }

        return false;
    }

    /**
     * Check if user has role
     */
    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Check if user has permission based on role hierarchy
     */
    public function can(string $permission): bool
    {
        $roleHierarchy = [
            'super_admin' => ['*'],
            'admin' => [
                'licenses.*', 'customers.*', 'products.*',
                'reports.view', 'settings.view'
            ],
            'support' => [
                'licenses.view', 'licenses.extend', 'licenses.deactivate',
                'customers.view', 'products.view'
            ],
            'viewer' => [
                'licenses.view', 'customers.view', 'products.view', 'reports.view'
            ]
        ];

        $permissions = $roleHierarchy[$this->role] ?? [];

        // Super admin has all permissions
        if (in_array('*', $permissions)) {
            return true;
        }

        // Check exact match
        if (in_array($permission, $permissions)) {
            return true;
        }

        // Check wildcard match (e.g., licenses.* matches licenses.create)
        foreach ($permissions as $allowed) {
            if (strpos($allowed, '*') !== false) {
                $pattern = str_replace('*', '.*', $allowed);
                if (preg_match("/^{$pattern}$/", $permission)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scope: Active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: By role
     */
    public function scopeByRole($query, string $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Find user by email
     */
    public static function findByEmail(string $email)
    {
        return static::where('email', $email)->first();
    }

    /**
     * Find user by username
     */
    public static function findByUsername(string $username)
    {
        return static::where('username', $username)->first();
    }

    /**
     * Find user by UUID
     */
    public static function findByUuid(string $uuid)
    {
        return static::where('uuid', $uuid)->first();
    }
}
