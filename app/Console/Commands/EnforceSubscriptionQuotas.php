<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Device;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class EnforceSubscriptionQuotas extends Command
{
    protected $signature = 'isp:enforce-quotas';
    protected $description = 'Disable expired RouterOS accounts and LAN devices, and enforce subscriber data quotas';

    public function handle(): int
    {
        $this->info('Starting subscriber access expiry and quota audit...');

        $successCount = 0;
        $failedCount = 0;
        $skippedCount = 0;

        // Batched so a large subscriber base never loads into memory at once.
        // Each row is then re-checked under a row lock inside disableOnRouter
        // (withEnforcementLock): a renewal that lands between this query and
        // the router call wins, and the stale pass is skipped.
        Subscription::query()
            ->where('status', 'active')
            ->where(function ($sub) {
                $sub->where(function ($q) {
                    $q->whereNotNull('expires_at')
                      ->where('expires_at', '<=', now());
                })->orWhere(function ($q) {
                    $q->whereNotNull('data_quota_bytes')
                      ->where('data_quota_bytes', '>', 0)
                      ->whereColumn('data_used_bytes', '>=', 'data_quota_bytes');
                });
            })
            ->with(['client', 'plan', 'router'])
            ->chunkById(100, function ($subscriptions) use (&$successCount, &$failedCount, &$skippedCount) {
                foreach ($subscriptions as $subscription) {
                    $result = $this->enforceSubscription($subscription);

                    match ($result) {
                        'enforced' => $successCount++,
                        'skipped' => $skippedCount++,
                        default => $failedCount++,
                    };
                }
            });

        $expiredDevices = Device::query()
            ->where('is_active', true)
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', now())
            ->with(['client', 'router'])
            ->chunkById(100, function ($devices) use (&$successCount, &$failedCount) {
                foreach ($devices as $device) {
                    $this->line("Processing LAN device {$device->mac_address} for '{$device->client->name}' on router '" . ($device->router?->name ?? 'unassigned') . "'...");

                    try {
                        if (!$device->router) {
                            $device->update(['is_active' => false]);
                            $this->warn("⚠ LAN device {$device->mac_address} has no router assigned — marked inactive only.");
                            $successCount++;
                            continue;
                        }

                        $device->disableLanAccess();
                        $this->info("✓ Successfully blocked expired LAN device {$device->mac_address}.");
                        $successCount++;
                    } catch (Exception $e) {
                        $this->error("✗ Failed to block LAN device {$device->mac_address}: " . $e->getMessage());
                        Log::error("Failed to disable LAN device {$device->id} on router {$device->router_id}: " . $e->getMessage());
                        $failedCount++;
                    }
                }
            });

        $this->info("Enforcement run complete: {$successCount} access records disabled, {$skippedCount} skipped (renewed/claim lost), {$failedCount} failed.");

        return self::SUCCESS;
    }

    /**
     * Enforce a single subscription. Returns 'enforced', 'skipped', or 'failed'.
     *
     * Router errors leave status=active with enforcement_status=failed and
     * last_enforcement_error set, so the next minute's run retries and the
     * UI can surface the unconfirmed block — success is only recorded after
     * the router operations complete without throwing.
     */
    private function enforceSubscription(Subscription $subscription): string
    {
        $reason = $subscription->isExpired() ? 'time_expired' : 'data_quota_exceeded';

        $this->line("Processing [{$subscription->auth_type}] {$subscription->username} on router '{$subscription->router->name}' (Reason: {$reason})...");

        try {
            $subscription->disableOnRouter($reason);
            $subscription->refresh();

            if ($subscription->enforcement_status === Subscription::ENFORCEMENT_ENFORCED) {
                $this->info("✓ Successfully disabled {$subscription->username}.");
            } else {
                $this->warn("⏭ {$subscription->username} skipped — renewed before enforcement applied.");
            }

            return $subscription->enforcement_status === Subscription::ENFORCEMENT_ENFORCED
                ? 'enforced'
                : 'skipped';
        } catch (Exception $e) {
            $this->error("✗ Failed to disable {$subscription->username}: " . $e->getMessage());
            Log::error("Failed to disable subscription {$subscription->id} on router {$subscription->router_id}: " . $e->getMessage());

            return 'failed';
        }
    }
}
