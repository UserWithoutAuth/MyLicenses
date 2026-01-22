<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

/**
 * License Model
 *
 * Represents a software license
 *
 * @package LicenseServer\Models
 */
class License extends Model
{
    protected $table = 'licenses';

    protected $fillable = [
        'license_key',
        'product_id',
        'tier_id',
        'customer_id',
        'license_type',
        'status',
        'max_activations',
        'current_activations',
        'issued_at',
        'expires_at',
        'grace_period_days',
        'expiry_message',
        'allow_offline',
        'allow_transfer',
        'transfer_count',
        'max_transfers',
        'last_transfer_at',
        'custom_fields',
        'features',
        'ip_restrictions',
        'geo_restrictions',
        'notes',
        'revoked_at',
        'revoked_reason'
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_transfer_at' => 'datetime',
        'revoked_at' => 'datetime',
        'allow_offline' => 'boolean',
        'allow_transfer' => 'boolean',
        'custom_fields' => 'array',
        'features' => 'array',
        'ip_restrictions' => 'array',
        'geo_restrictions' => 'array',
        'max_activations' => 'integer',
        'current_activations' => 'integer',
        'grace_period_days' => 'integer',
        'transfer_count' => 'integer',
        'max_transfers' => 'integer'
    ];

    /**
     * Get the product this license belongs to
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get the customer who owns this license
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get the tier for this license
     */
    public function tier()
    {
        return $this->belongsTo(LicenseTier::class, 'tier_id');
    }

    /**
     * Get activations for this license
     */
    public function activations()
    {
        return $this->hasMany(Activation::class);
    }

    /**
     * Get active activations only
     */
    public function activeActivations()
    {
        return $this->hasMany(Activation::class)->where('status', 'active');
    }

    /**
     * Get pings for this license
     */
    public function pings()
    {
        return $this->hasMany(Ping::class);
    }

    /**
     * Check if license is expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false; // No expiry date means lifetime license
        }

        return Carbon::now()->isAfter($this->expires_at);
    }

    /**
     * Check if license is in grace period
     */
    public function isInGracePeriod(): bool
    {
        if (!$this->isExpired()) {
            return false;
        }

        $gracePeriodEnd = Carbon::parse($this->expires_at)
            ->addDays($this->grace_period_days ?? 0);

        return Carbon::now()->isBefore($gracePeriodEnd);
    }

    /**
     * Check if license is valid
     */
    public function isValid(): bool
    {
        // Check status
        if ($this->status !== 'active') {
            return false;
        }

        // Check if revoked
        if ($this->revoked_at) {
            return false;
        }

        // Check expiry (including grace period)
        if ($this->isExpired() && !$this->isInGracePeriod()) {
            return false;
        }

        return true;
    }

    /**
     * Check if license can accept new activation
     */
    public function canActivate(): bool
    {
        if (!$this->isValid()) {
            return false;
        }

        // For floating licenses, check current activations
        if ($this->license_type === 'floating') {
            return $this->current_activations < $this->max_activations;
        }

        // For standard licenses, check total activations
        return $this->activations()->where('status', 'active')->count() < $this->max_activations;
    }

    /**
     * Check if license can be transferred
     */
    public function canTransfer(): bool
    {
        if (!$this->allow_transfer) {
            return false;
        }

        if ($this->transfer_count >= $this->max_transfers) {
            return false;
        }

        // Check transfer cooldown (30 days by default)
        if ($this->last_transfer_at) {
            $cooldownDays = env('LICENSE_TRANSFER_COOLDOWN_DAYS', 30);
            $nextAllowedTransfer = Carbon::parse($this->last_transfer_at)->addDays($cooldownDays);

            if (Carbon::now()->isBefore($nextAllowedTransfer)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get days until expiry
     */
    public function daysUntilExpiry(): ?int
    {
        if (!$this->expires_at) {
            return null;
        }

        return Carbon::now()->diffInDays($this->expires_at, false);
    }

    /**
     * Revoke license
     */
    public function revoke(string $reason = null): void
    {
        $this->status = 'revoked';
        $this->revoked_at = now();
        $this->revoked_reason = $reason;
        $this->save();

        // Deactivate all active activations
        $this->activeActivations()->update([
            'status' => 'deactivated',
            'deactivated_at' => now()
        ]);

        audit_log('license.revoked', [
            'license_key' => $this->license_key,
            'reason' => $reason
        ]);
    }

    /**
     * Increment activation count
     */
    public function incrementActivations(): void
    {
        $this->increment('current_activations');
    }

    /**
     * Decrement activation count
     */
    public function decrementActivations(): void
    {
        if ($this->current_activations > 0) {
            $this->decrement('current_activations');
        }
    }

    /**
     * Scope: Active licenses
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->whereNull('revoked_at');
    }

    /**
     * Scope: Expired licenses
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Scope: Expiring soon (within X days)
     */
    public function scopeExpiringSoon($query, int $days = 7)
    {
        return $query->whereBetween('expires_at', [now(), now()->addDays($days)]);
    }

    /**
     * Find license by key
     */
    public static function findByKey(string $licenseKey)
    {
        return static::where('license_key', $licenseKey)->first();
    }

    /**
     * Get expiry message or default
     */
    public function getExpiryMessageAttribute($value): string
    {
        return $value ?? 'Your license has expired. Please renew to continue using this software.';
    }
}
