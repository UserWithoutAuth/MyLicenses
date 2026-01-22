<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * License Tier Model
 *
 * Represents a pricing tier/plan for licenses (Basic, Pro, Enterprise, etc.)
 *
 * @package LicenseServer\Models
 */
class LicenseTier extends Model
{
    protected $table = 'license_tiers';

    protected $fillable = [
        'product_id',
        'name',
        'slug',
        'description',
        'max_activations',
        'duration_days',
        'price',
        'currency',
        'features',
        'is_active'
    ];

    protected $casts = [
        'max_activations' => 'integer',
        'duration_days' => 'integer',
        'price' => 'decimal:2',
        'features' => 'array',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the product for this tier
     */
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get licenses using this tier
     */
    public function licenses()
    {
        return $this->hasMany(License::class, 'tier_id');
    }

    /**
     * Check if feature is enabled in this tier
     */
    public function hasFeature(string $feature): bool
    {
        return in_array($feature, $this->features ?? []);
    }

    /**
     * Scope: Active tiers
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Find tier by slug
     */
    public static function findBySlug(string $slug)
    {
        return static::where('slug', $slug)->first();
    }
}
