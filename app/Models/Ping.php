<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Ping Model
 *
 * Represents a heartbeat/ping from an activated license
 *
 * @package LicenseServer\Models
 */
class Ping extends Model
{
    protected $table = 'pings';

    public $timestamps = false;

    protected $fillable = [
        'activation_id',
        'license_id',
        'machine_id',
        'ping_at',
        'ip_address',
        'app_version',
        'uptime_seconds',
        'status_code',
        'response_time_ms',
        'metadata'
    ];

    protected $casts = [
        'ping_at' => 'datetime',
        'uptime_seconds' => 'integer',
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
        'metadata' => 'array'
    ];

    /**
     * Get the activation for this ping
     */
    public function activation()
    {
        return $this->belongsTo(Activation::class);
    }

    /**
     * Get the license for this ping
     */
    public function license()
    {
        return $this->belongsTo(License::class);
    }

    /**
     * Get the machine for this ping
     */
    public function machine()
    {
        return $this->belongsTo(Machine::class);
    }

    /**
     * Scope: Recent pings (last N hours)
     */
    public function scopeRecent($query, int $hours = 24)
    {
        return $query->where('ping_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Successful pings
     */
    public function scopeSuccessful($query)
    {
        return $query->where('status_code', 200);
    }

    /**
     * Scope: Failed pings
     */
    public function scopeFailed($query)
    {
        return $query->where('status_code', '!=', 200);
    }
}
