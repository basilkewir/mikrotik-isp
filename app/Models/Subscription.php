<?php

namespace App\Models;

use App\Support\CalendarWindow;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Subscription extends Model
{
    use HasFactory;

    public const ENFORCEMENT_PENDING = 'pending';
    public const ENFORCEMENT_ENFORCED = 'enforced';
    public const ENFORCEMENT_FAILED = 'failed';

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
        'enforcement_status',
        'start_at',
        'expires_at',
        'expired_at',
        'enforced_at',
        'last_enforcement_error',
        'data_quota_bytes',
        'data_used_bytes',
        'auto_renew',
        'last_synced_at',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'expires_at' => 'datetime',
        'expired_at' => 'datetime',
        'enforced_at' => 'datetime',
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

    public function boundDevices(): HasMany
    {
        return $this->hasMany(SubscriptionBoundDevice::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(SubscriptionReminder::class);
    }

    /**
     * Number of devices this account may bind at once.
     */
    public function deviceLimit(): int
    {
        return max(1, (int) ($this->plan?->shared_users ?? 1));
    }

    /**
     * Let a device take (or keep) a slot, or refuse it when all slots are held.
     *
     * An already-bound MAC always keeps its slot and only refreshes its
     * heartbeat; a new MAC is accepted only while a free slot remains.
     */
    public function allowsDevice(string $mac): bool
    {
        $mac = strtolower(trim($mac));

        if ($mac === '' || $mac === '00:00:00:00:00:00') {
            return true;
        }

        $bound = $this->boundDevices()->where('mac_address', $mac)->first();

        if ($bound) {
            $bound->update([
                'last_seen_at' => now(),
                'is_active' => true,
            ]);

            return true;
        }

        $held = $this->boundDevices()->where('is_active', true)->count();

        if ($held >= $this->deviceLimit()) {
            return false;
        }

        $this->boundDevices()->create([
            'mac_address' => $mac,
            'bound_at' => now(),
            'last_seen_at' => now(),
            'is_active' => true,
        ]);

        return true;
    }

    /**
     * Release a bound device so a different one may take the slot.
     */
    public function unbindDevice(string $mac): bool
    {
        return (bool) $this->boundDevices()
            ->where('mac_address', strtolower(trim($mac)))
            ->delete();
    }

    /**
     * End of the calendar window that starts on $from and lasts $days days,
     * counting the starting day as day 1.
     *
     * 3 days activated on Oct 17 therefore expires at the end of Oct 19 and
     * access is cut at 00:00 on Oct 20 — three calendar days, never three
     * blocks of 24 hours of accumulated uptime.
     */
    public static function calendarExpiry(Carbon $from, int $days): Carbon
    {
        return CalendarWindow::endOf($days, $from);
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
     * Fresh recheck used immediately before touching the router: a renewal
     * (webhook or wallet job) may have landed since the caller selected this
     * row, and a stale enforcement pass must never undo paid access.
     *
     * Automatic expiry reasons require the row to still be due; any other
     * reason is an explicit operator action (e.g. manual_admin_disable) and
     * always applies while the subscription is active.
     */
    public function stillDueForEnforcement(string $reason): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return match ($reason) {
            'time_expired' => $this->isExpired(),
            'data_quota_exceeded' => $this->isDataQuotaExceeded(),
            default => true,
        };
    }

    /**
     * Run $callback with this row locked FOR UPDATE and re-checked against
     * $reason. Returns null (and skips the callback) when the subscription is
     * no longer due — the renewal-wins case.
     *
     * The lock is held only for the duration of the callback: never perform
     * slow RouterOS API calls inside it.
     */
    public function withEnforcementLock(string $reason, callable $callback): mixed
    {
        return DB::transaction(function () use ($reason, $callback) {
            $fresh = static::query()->whereKey($this->id)->lockForUpdate()->first();

            if (!$fresh || !$fresh->stillDueForEnforcement($reason)) {
                return null;
            }

            return $callback($fresh);
        });
    }

    /**
     * Run $callback with this row locked FOR UPDATE, regardless of current
     * status — used by renewal/payment paths that must serialise against a
     * concurrent enforcement pass without requiring the row to be expired.
     */
    public function withRowLock(callable $callback): mixed
    {
        return DB::transaction(function () use ($callback) {
            $fresh = static::query()->whereKey($this->id)->lockForUpdate()->first();

            return $fresh ? $callback($fresh) : null;
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
            $profile = $this->plan->hotspot_profile ?? 'default';

            $service->createOrUpdateHotspotUser(
                username: $this->username,
                password: $this->password,
                profile: $profile,
                comment: $comment,
                limitBytesTotal: $this->data_quota_bytes,
                sharedUsers: $this->plan?->shared_users
            );

            // RouterOS 7 dropped shared-users from the user itself, so the
            // per-account device cap has to live on the user profile (where
            // it is inherited by every account of the plan's profile).
            if ($this->plan?->shared_users) {
                $service->ensureHotspotProfileDeviceCap($profile, (int) $this->plan->shared_users);
            }

            // Devices on this profile must reconnect silently from now on:
            // MAC-cookie + idle timeout on the user profile, and cookie
            // login methods on the hotspot server profile.
            $service->ensureAutoReconnectProfile($profile);
            $service->ensureHotspotServerAuth();
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
     * Re-activate the subscriber on the MikroTik router: enable the user,
     * lift any expiry quarantine and move the calendar expiry forward.
     *
     * This is the single activation path — used by the admin "enable" button,
     * the quick expiry editor, and (later) any payment-gateway webhook.
     *
     * @param  ?Carbon  $expiresAt  explicit new expiry
     * @param  bool  $usePlanDefault  when $expiresAt is null, fall back to plan validity (true) or keep permanent (false)
     */
    public function activateOnRouter(?Carbon $expiresAt = null, bool $usePlanDefault = true): void
    {
        $this->applyEnabledOnRouterState();

        $this->update([
            'status' => 'active',
            'start_at' => now(),
            'expires_at' => $expiresAt
                ?? ($usePlanDefault && $this->plan?->validity_days
                    ? static::calendarExpiry(now(), (int) $this->plan->validity_days)
                    : null),
            'enforcement_status' => null,
            'last_enforcement_error' => null,
        ]);

        RouterActivityLog::create([
            'router_id' => $this->router_id,
            'subscription_id' => $this->id,
            'event' => 'subscription_activate',
            'level' => 'info',
            'details' => [
                'username' => $this->username,
                'auth_type' => $this->auth_type,
                'expires_at' => $this->expires_at?->toIso8601String(),
            ],
            'ip_address' => $this->assigned_ip,
        ]);
    }

    /**
     * (Re)enable the account on the router and lift any expiry quarantine.
     * No database changes — used by activation and by the race-heal path.
     */
    public function applyEnabledOnRouterState(): void
    {
        $service = $this->router->service();

        try {
            if ($this->auth_type === 'hotspot') {
                $service->enableHotspotUser($this->username);
            } elseif ($this->auth_type === 'pppoe') {
                $service->enablePppoeSecret($this->username);
            }

            if ($this->assigned_ip) {
                $service->unfreezeIp($this->assigned_ip);
            }
        } finally {
            $service->disconnect();
        }
    }

    /**
     * Disable subscriber on MikroTik and terminate active sessions immediately.
     *
     * Race-safe by construction:
     *  1. recheck under a row lock before touching the router — a subscription
     *     renewed since the caller selected it is skipped entirely;
     *  2. router operations run outside any database transaction;
     *  3. recheck again after the router work — if a renewal landed meanwhile,
     *     access is restored on the router instead of being left broken.
     */
    public function disableOnRouter(string $reason = 'expired'): void
    {
        $claimed = $this->withEnforcementLock($reason, function (Subscription $fresh) {
            $fresh->forceFill(['enforcement_status' => self::ENFORCEMENT_PENDING])->save();

            return true;
        });

        if (!$claimed) {
            RouterActivityLog::create([
                'router_id' => $this->router_id,
                'subscription_id' => $this->id,
                'event' => 'auto_disable_skipped',
                'level' => 'info',
                'details' => [
                    'reason' => $reason,
                    'username' => $this->username,
                    'note' => 'subscription no longer due at enforcement time (renewed?)',
                ],
            ]);

            $this->refresh();

            return;
        }

        $service = $this->router->service();

        try {
            if ($this->auth_type === 'hotspot') {
                $service->disableHotspotUser($this->username);
            } elseif ($this->auth_type === 'pppoe') {
                $service->disablePppoeSecret($this->username);
            }

            if ($this->assigned_ip) {
                $service->isolateIp($this->assigned_ip, 'EXPIRED_USERS', $reason);
            }
        } catch (Exception $exception) {
            DB::transaction(function () use ($exception) {
                $this->forceFill([
                    'enforcement_status' => self::ENFORCEMENT_FAILED,
                    'last_enforcement_error' => mb_substr($exception->getMessage(), 0, 1000),
                ])->save();
            });

            throw $exception;
        } finally {
            $service->disconnect();
        }

        // Second recheck: a renewal may have completed while we were talking
        // to the router. If so, heal instead of marking the sub expired.
        $renewedUnderUs = DB::transaction(function () use ($reason) {
            $fresh = static::query()->whereKey($this->id)->lockForUpdate()->first();

            if ($fresh && $fresh->stillDueForEnforcement($reason)) {
                $fresh->forceFill([
                    'status' => in_array($reason, ['time_expired', 'data_quota_exceeded'], true) ? 'expired' : 'disabled',
                    'expired_at' => $reason === 'time_expired' ? ($fresh->expired_at ?? now()) : $fresh->expired_at,
                    'enforced_at' => now(),
                    'enforcement_status' => self::ENFORCEMENT_ENFORCED,
                    'last_enforcement_error' => null,
                ])->save();

                // Device slots are released so a future renewal starts clean;
                // rows are kept for audit and fast re-binding.
                $fresh->boundDevices()->update(['is_active' => false]);

                return false;
            }

            if ($fresh) {
                // Renewed under us: drop the pending claim so nothing looks stuck.
                $fresh->forceFill([
                    'enforcement_status' => null,
                    'last_enforcement_error' => null,
                ])->save();
            }

            return $fresh !== null;
        });

        if ($renewedUnderUs) {
            // We may have just disabled an account that is paid up again —
            // put the router back in line with the (renewed) database state.
            $this->refresh();
            $this->applyEnabledOnRouterState();

            RouterActivityLog::create([
                'router_id' => $this->router_id,
                'subscription_id' => $this->id,
                'event' => 'auto_disable_healed',
                'level' => 'warning',
                'details' => [
                    'reason' => $reason,
                    'username' => $this->username,
                    'note' => 'subscription renewed during enforcement; router access restored',
                    'expires_at' => $this->expires_at?->toIso8601String(),
                ],
            ]);

            return;
        }

        $this->refresh();

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
