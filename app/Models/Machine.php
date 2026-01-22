<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Machine Model
 *
 * Represents a physical/virtual machine with hardware fingerprint
 *
 * @package LicenseServer\Models
 */
class Machine extends Model
{
    protected $table = 'machines';

    public $timestamps = false;

    protected $fillable = [
        'fingerprint',
        'fingerprint_hash',
        'public_key',
        'machine_name',
        'os_info',
        'cpu_info',
        'mac_address',
        'disk_serial',
        'motherboard_id',
        'first_seen_at',
        'last_seen_at',
        'is_blacklisted',
        'blacklist_reason',
        'metadata'
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_blacklisted' => 'boolean',
        'metadata' => 'array'
    ];

    /**
     * Boot function
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($machine) {
            // Generate fingerprint hash for faster lookups
            if ($machine->fingerprint && !$machine->fingerprint_hash) {
                $machine->fingerprint_hash = hash('sha256', $machine->fingerprint);
            }

            // Set first_seen_at
            if (!$machine->first_seen_at) {
                $machine->first_seen_at = now();
            }
        });
    }

    /**
     * Get activations for this machine
     */
    public function activations()
    {
        return $this->hasMany(Activation::class);
    }

    /**
     * Get active activations
     */
    public function activeActivations()
    {
        return $this->hasMany(Activation::class)->where('status', 'active');
    }

    /**
     * Get pings from this machine
     */
    public function pings()
    {
        return $this->hasMany(Ping::class);
    }

    /**
     * Update last seen timestamp
     */
    public function updateLastSeen(): void
    {
        $this->last_seen_at = now();
        $this->save();
    }

    /**
     * Blacklist this machine
     */
    public function blacklist(string $reason = null): void
    {
        $this->is_blacklisted = true;
        $this->blacklist_reason = $reason;
        $this->save();

        // Deactivate all active licenses
        $this->activeActivations()->update([
            'status' => 'deactivated',
            'deactivated_at' => now()
        ]);

        audit_log('machine.blacklisted', [
            'fingerprint_hash' => $this->fingerprint_hash,
            'reason' => $reason
        ]);
    }

    /**
     * Remove from blacklist
     */
    public function unblacklist(): void
    {
        $this->is_blacklisted = false;
        $this->blacklist_reason = null;
        $this->save();

        audit_log('machine.unblacklisted', [
            'fingerprint_hash' => $this->fingerprint_hash
        ]);
    }

    /**
     * Check if machine is blacklisted
     */
    public function isBlacklisted(): bool
    {
        return $this->is_blacklisted;
    }

    /**
     * Scope: Blacklisted machines
     */
    public function scopeBlacklisted($query)
    {
        return $query->where('is_blacklisted', true);
    }

    /**
     * Scope: Not blacklisted
     */
    public function scopeNotBlacklisted($query)
    {
        return $query->where('is_blacklisted', false);
    }

    /**
     * Find machine by fingerprint
     */
    public static function findByFingerprint(string $fingerprint)
    {
        $hash = hash('sha256', $fingerprint);
        return static::where('fingerprint_hash', $hash)->first();
    }

    /**
     * Find or create machine
     */
    public static function findOrCreateByFingerprint(string $fingerprint, array $machineInfo = []): Machine
    {
        $machine = static::findByFingerprint($fingerprint);

        if (!$machine) {
            $machine = static::create(array_merge([
                'fingerprint' => $fingerprint,
                'fingerprint_hash' => hash('sha256', $fingerprint)
            ], $machineInfo));
        } else {
            // Update last seen and machine info
            $machine->last_seen_at = now();

            if (!empty($machineInfo)) {
                foreach ($machineInfo as $key => $value) {
                    if (in_array($key, $machine->fillable) && $value !== null) {
                        $machine->$key = $value;
                    }
                }
            }

            $machine->save();
        }

        return $machine;
    }
}
