<?php

namespace App\Models;

use App\Services\Mikrotik\MikrotikService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Router extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip_address',
        'api_port',
        'api_username',
        'api_password',
        'use_ssl',
        'identity',
        'is_online',
        'last_seen_at',
        'ros_version',
        'board_name',
        'cpu_load',
        'total_memory',
        'free_memory',
        'uptime',
    ];

    protected $casts = [
        'api_password' => 'encrypted',
        'use_ssl' => 'boolean',
        'is_online' => 'boolean',
        'last_seen_at' => 'datetime',
        'api_port' => 'integer',
        'cpu_load' => 'integer',
        'total_memory' => 'integer',
        'free_memory' => 'integer',
    ];

    protected $hidden = [
        'api_password',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(RouterActivityLog::class);
    }

    /**
     * Get instance of MikrotikService bound to this router.
     *
     * When a service is bound in the container (tests inject fakes that
     * record calls instead of opening sockets), that instance is returned as
     *-is — note that passing constructor parameters here would bypass the
     * bound instance, so the router is only passed on the direct-construction
     * path.
     */
    public function service(): MikrotikService
    {
        return app()->bound(MikrotikService::class)
            ? app(MikrotikService::class)
            : new MikrotikService($this);
    }
}

