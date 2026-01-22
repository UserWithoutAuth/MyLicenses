<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Product Model
 *
 * Represents a product/application that uses the license system
 *
 * @package LicenseServer\Models
 */
class Product extends Model
{
    protected $table = 'products';

    protected $fillable = [
        'name',
        'slug',
        'description',
        'version',
        'is_active'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get licenses for this product
     */
    public function licenses()
    {
        return $this->hasMany(License::class);
    }

    /**
     * Get license tiers for this product
     */
    public function tiers()
    {
        return $this->hasMany(LicenseTier::class);
    }

    /**
     * Scope: Active products only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Get product by slug
     */
    public static function findBySlug(string $slug)
    {
        return static::where('slug', $slug)->first();
    }
}
