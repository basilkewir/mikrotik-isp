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

        $expiredSubscriptions = Subscription::expiredOrExceeded()
            ->with(['client', 'plan', 'router'])
            ->get();

        if ($expiredSubscriptions->isEmpty()) {
            $this->info('No expired or depleted subscriptions found.');
        } else {
            $this->warn("Found {$expiredSubscriptions->count()} subscriptions to disable.");
        }

        $successCount = 0;
        $failedCount = 0;

        foreach ($expiredSubscriptions as $subscription) {
            $reason = $subscription->isExpired() ? 'time_expired' : 'data_quota_exceeded';

            $this->line("Processing [{$subscription->auth_type}] {$subscription->username} on router '{$subscription->router->name}' (Reason: {$reason})...");

            try {
                $subscription->disableOnRouter($reason);
                $this->info("✓ Successfully disabled {$subscription->username}.");
                $successCount++;
            } catch (Exception $e) {
                $this->error("✗ Failed to disable {$subscription->username}: " . $e->getMessage());
                Log::error("Failed to disable subscription {$subscription->id} on router {$subscription->router_id}: " . $e->getMessage());
                $failedCount++;
            }
        }

        $expiredDevices = Device::query()
            ->where('is_active', true)
            ->whereNotNull('access_expires_at')
            ->where('access_expires_at', '<=', now())
            ->with(['client', 'router'])
            ->get();

        foreach ($expiredDevices as $device) {
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

        $this->info("Enforcement run complete: {$successCount} access records disabled, {$failedCount} failed.");

        return self::SUCCESS;
    }
}
