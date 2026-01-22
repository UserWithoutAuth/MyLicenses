<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Activation Model
 *
 * Represents a license activation on a specific machine
 *
 * @package LicenseServer\Models
 */
class Activation extends Model
{
    protected $table = 'activations';

    protected $fillable = [
        'license_id',
        'machine_id',
        'activation_token',
        'activation_type',
        'status',
        'activated_at',
        'deactivated_at',
        'last_ping_at',
        'ping_count',
        'ip_address',
        'user_agent',
        'app_version',
        'metadata'
    ];

    protected $casts = [
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
        'last_ping_at' => 'datetime',
        'ping_count' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Boot function
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($activation) {
            // Generate activation token
            if (!$activation->activation_token) {
                $activation->activation_token = generate_token(32);
            }

            // Set activated_at
            if (!$activation->activated_at) {
                $activation->activated_at = now();
            }
        });

        static::created(function ($activation) {
            // Increment license activation count
            $activation->license->incrementActivations();
        });

        static::updated(function ($activation) {
            // If status changed to deactivated, decrement count
            if ($activation->isDirty('status') && $activation->status === 'deactivated') {
                $activation->license->decrementActivations();
            }
        });
    }

    /**
     * Get the license for this activation
     */
    public function license()
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Get the machine for this activation
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Get pings for this activation
     */
    public function pings()
    {
        return $this->hasMany(Ping::class);
    }

    /**
     * Check if activation is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Deactivate this activation
     */
    public function deactivate(): void
    {
        $this->status = 'deactivated';
        $this->deactivated_at = now();
        $this->save();

        audit_log('activation.deactivated', [
            'activation_token' => $this->activation_token,
            'license_key' => $this->license->license_key,
            'machine_fingerprint' => $this->machine->fingerprint_hash
        ]);
    }

    /**
     * Update ping information
     */
    public function recordPing(array $pingData = []): void
    {
        $this->last_ping_at = now();
        $this->ping_count++;

        if (isset($pingData['app_version'])) {
            $this->app_version = $pingData['app_version'];
        }

        $this->save();

        // Create ping record
        Ping::create([
            'activation_id' => $this->id,
            'license_id' => $this->license_id,
            'machine_id' => $this->machine_id,
            'ping_at' => now(),
            'ip_address' => $pingData['ip_address'] ?? get_client_ip(),
            'app_version' => $pingData['app_version'] ?? $this->app_version,
            'uptime_seconds' => $pingData['uptime_seconds'] ?? null,
            'status_code' => $pingData['status_code'] ?? 200,
            'metadata' => $pingData['metadata'] ?? null
        ]);
    }

    /**
     * Check if ping is overdue
     */
    public function isPingOverdue(): bool
    {
        if (!$this->last_ping_at) {
            return false;
        }

        $pingInterval = env('PING_INTERVAL_SECONDS', 300);
        $pingTimeout = env('PING_TIMEOUT_MINUTES', 15);

        $timeoutThreshold = $pingInterval + ($pingTimeout * 60);

        return $this->last_ping_at->diffInSeconds(now()) > $timeoutThreshold;
    }

    /**
     * Scope: Active activations
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope: Online activations
     */
    public function scopeOnline($query)
    {
        return $query->where('activation_type', 'online');
    }

    /**
     * Scope: Offline activations
     */
    public function scopeOffline($query)
    {
        return $query->where('activation_type', 'offline');
    }

    /**
     * Find activation by token
     */
    public static function findByToken(string $token)
    {
        return static::where('activation_token', $token)->first();
    }
}
