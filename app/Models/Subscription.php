<?php

namespace App\Models;

use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'plan_id',
        'router_id',
        'auth_type',
        'username',
        'password',
        'assigned_ip',
        'assigned_mac',
        'status',
        'start_at',
        'expires_at',
        'data_quota_bytes',
        'data_used_bytes',
        'auto_renew',
        'last_synced_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'data_quota_bytes' => 'integer',
        'data_used_bytes' => 'integer',
        'auto_renew' => 'boolean',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Check if subscription has time-expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at && Carbon::now()->greaterThanOrEqualTo($this->expires_at);
    }

    /**
     * Check if subscription has exceeded data quota.
     */
    public function isDataQuotaExceeded(): bool
    {
        return $this->data_quota_bytes > 0 && $this->data_used_bytes >= $this->data_quota_bytes;
    }

    /**
     * Query scope for active subscriptions that are expired or data-depleted.
     */
    public function scopeExpiredOrExceeded(Builder $query): Builder
    {
        return $query->where('status', 'active')
            ->where(function ($sub) {
                $sub->where(function ($q) {
                    $q->whereNotNull('expires_at')
                      ->where('expires_at', '<=', Carbon::now());
                })->orWhere(function ($q) {
                    $q->whereNotNull('data_quota_bytes')
                      ->where('data_quota_bytes', '>', 0)
                      ->whereColumn('data_used_bytes', '>=', 'data_quota_bytes');
                });
            });
    }

    /**
     * Provision user credentials on the associated MikroTik Router.
     */
    public function provisionOnRouter(): void
    {
        $service = $this->router->service();
        $comment = "Client: {$this->client->name} [Sub #{$this->id}]";

        if ($this->auth_type === 'hotspot') {
            $service->createOrUpdateHotspotUser(
                username: $this->username,
                password: $this->password,
                profile: $this->plan->hotspot_profile ?? 'default',
                comment: $comment,
                limitBytesTotal: $this->data_quota_bytes
            );
        } elseif ($this->auth_type === 'pppoe') {
            $service->createOrUpdatePppoeSecret(
                username: $this->username,
                password: $this->password,
                profile: $this->plan->pppoe_profile ?? 'default',
                remoteAddress: $this->assigned_ip,
                comment: $comment
            );
        }

        $service->disconnect();
    }

    /**
     * Disable subscriber on MikroTik and terminate active sessions immediately.
     */
    public function disableOnRouter(string $reason = 'expired'): void
    {
        $service = $this->router->service();

        if ($this->auth_type === 'hotspot') {
            $service->disableHotspotUser($this->username);
        } elseif ($this->auth_type === 'pppoe') {
            $service->disablePppoeSecret($this->username);
        }

        if ($this->assigned_ip) {
            $service->isolateIp($this->assigned_ip, 'EXPIRED_USERS', $reason);
        }

        $service->disconnect();

        $this->update([
            'status' => in_array($reason, ['time_expired', 'data_quota_exceeded'], true) ? 'expired' : 'disabled',
        ]);

        RouterActivityLog::create([
            'router_id' => $this->router_id,
            'subscription_id' => $this->id,
            'event' => 'auto_disable',
            'level' => 'warning',
            'details' => [
                'reason' => $reason,
                'username' => $this->username,
                'auth_type' => $this->auth_type,
                'data_used_bytes' => $this->data_used_bytes,
                'expires_at' => $this->expires_at?->toIso8601String(),
            ],
            'ip_address' => $this->assigned_ip,
        ]);
    }
}
