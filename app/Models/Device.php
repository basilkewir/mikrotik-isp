<?php

namespace App\Models;

use App\Support\CalendarWindow;
use App\Services\Mikrotik\MacAuthManager;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'router_id',
        'parent_device_id',
        'mac_address',
        'ip_address',
        'ipv6_address',
        'hostname',
        'type',
        'serial_number',
        'model',
        'manufacturer',
        'firmware_version',
        'interface',
        'management_ip',
        'snmp_community',
        'is_online',
        'last_seen_at',
        'signal_strength',
        'uptime_seconds',
        'notes',
        'comment',
        'is_active',
        'access_duration_days',
        'access_expires_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_online' => 'boolean',
        'access_duration_days' => 'integer',
        'access_expires_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'signal_strength' => 'integer',
        'uptime_seconds' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_device_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_device_id');
    }

    /**
     * Typed view of the raw type column.
     */
    public function deviceType(): ?\App\Enums\DeviceType
    {
        return \App\Enums\DeviceType::tryFrom((string) $this->type);
    }

    public function getTypeLabelAttribute(): string
    {
        return $this->deviceType()?->label() ?? ucfirst((string) $this->type);
    }

    /**
     * Grant access (MAC authorization: allow-list + static lease + L2 gate open).
     */
    public function provisionLanAccess(): array
    {
        return $this->runMacAuth(fn (MacAuthManager $macAuth) => $macAuth->activate($this), 'device_mac_auth_enable');
    }

    /**
     * Cut access instantly (L2 MAC gate + allow-list removal) and mark inactive.
     */
    public function disableLanAccess(): array
    {
        $actions = $this->runMacAuth(fn (MacAuthManager $macAuth) => $macAuth->deactivate($this), 'device_mac_auth_disable');
        $this->update(['is_active' => false]);

        return $actions;
    }

    /**
     * Resolve the access duration + expiry datetime from form input.
     * An absolute expires_at always wins over a day duration; when neither
     * is given, customer-edge devices default to 30 days and infrastructure
     * devices never expire.
     *
     * @return array{0: ?int, 1: ?Carbon} [access_duration_days, access_expires_at]
     */
    public static function resolveAccessExpiry(?int $days, ?string $expiresAt, bool $isCustomerEdge): array
    {
        if (!empty($expiresAt)) {
            $expires = Carbon::parse($expiresAt);

            return [$days, $expires];
        }

        if ($days !== null && $days > 0) {
            return [$days, CalendarWindow::endOf($days)];
        }

        if ($isCustomerEdge) {
            return [30, CalendarWindow::endOf(30)];
        }

        return [null, null];
    }

    /**
     * Re-grant access and restart the calendar access period from today.
     * Pass $days to override the stored access_duration_days, or $expiresAt
     * to set an absolute expiry datetime (which wins over $days).
     */
    public function renewLanAccess(?int $days = null, ?Carbon $expiresAt = null): array
    {
        if ($days !== null && $days > 0) {
            $this->access_duration_days = $days;
        }

        $this->access_expires_at = $expiresAt
            ?? CalendarWindow::endOf((int) ($this->access_duration_days ?: 30));
        $this->save();

        $actions = $this->runMacAuth(fn (MacAuthManager $macAuth) => $macAuth->activate($this), 'device_mac_auth_enable');
        $this->update(['is_active' => true]);

        return $actions;
    }

    /**
     * Remove every trace of this device from the router (gate rule,
     * allow-list entry, static lease) — used on device deletion.
     */
    public function removeLanAccess(): array
    {
        return $this->runMacAuth(fn (MacAuthManager $macAuth) => $macAuth->purge($this), 'device_mac_auth_purge');
    }

    public function isAccessExpired(): bool
    {
        return $this->access_expires_at !== null && $this->access_expires_at->isPast();
    }

    /**
     * Execute a MAC authorization operation against the device's router,
     * disconnect, and write an audit trail to router_activity_logs.
     *
     * @return array RouterOS actions performed
     */
    private function runMacAuth(callable $operation, string $event): array
    {
        if (!$this->router) {
            throw new \Exception("Device {$this->id} ({$this->mac_address}) has no router assigned.");
        }

        $service = $this->router->service();

        try {
            $actions = $operation(new MacAuthManager($service));
        } finally {
            $service->disconnect();
        }

        RouterActivityLog::create([
            'router_id' => $this->router_id,
            'event' => $event,
            'level' => 'info',
            'details' => [
                'device_id' => $this->id,
                'client_id' => $this->client_id,
                'mac_address' => $this->mac_address,
                'ip_address' => $this->ip_address,
                'hostname' => $this->hostname,
                'access_expires_at' => $this->access_expires_at?->toIso8601String(),
                'actions' => $actions,
            ],
            'ip_address' => $this->ip_address,
        ]);

        return $actions;
    }
}
