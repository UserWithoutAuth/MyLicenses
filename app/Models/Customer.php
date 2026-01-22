<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Customer Model
 *
 * Represents a customer who owns licenses
 *
 * @package LicenseServer\Models
 */
class Customer extends Model
{
    protected $table = 'customers';

    protected $fillable = [
        'uuid',
        'email',
        'name',
        'company',
        'phone',
        'country',
        'address',
        'password_hash',
        'email_verified_at',
        'is_active',
        'notes',
        'metadata'
    ];

    protected $hidden = [
        'password_hash'
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'is_active' => 'boolean',
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

        static::creating(function ($customer) {
            if (!$customer->uuid) {
                $customer->uuid = generate_uuid();
            }
        });
    }

    /**
     * Get licenses owned by this customer
     */
    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    /**
     * Get active licenses only
     */
    public function activeLicenses()
    {
        return $this->hasMany(License::class)->active();
    }

    /**
     * Set password
     */
    public function setPassword(string $password): void
    {
        $this->password_hash = hash_password($password);
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
     * Scope: Active customers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find customer by UUID
     */
    public static function findByUuid(string $uuid)
    {
        return static::where('uuid', $uuid)->first();
    }

    /**
     * Find customer by email
     */
    public static function findByEmail(string $email)
    {
        return static::where('email', $email)->first();
    }
}
