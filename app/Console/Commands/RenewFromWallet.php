<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionRenewalService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewFromWallet extends Command
{
    protected $signature = 'isp:renew-from-wallet';
    protected $description = 'Auto-renew expired subscriptions from the client wallet when auto_renew is enabled';

    public function handle(SubscriptionRenewalService $renewals): int
    {
        $candidates = Subscription::query()
            ->whereIn('status', ['active', 'expired'])
            ->where('auto_renew', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['client', 'plan', 'router'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No auto-renew candidates found.');

            return self::SUCCESS;
        }

        $this->info("Found {$candidates->count()} subscriptions to auto-renew.");

        $renewed = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($candidates as $subscription) {
            try {
                // requireExpired=true: the service rechecks under a row lock
                // that the window is still lapsed BEFORE charging, so a
                // webhook that renewed the same sub is never double-charged.
                $transaction = $renewals->renewFromWallet($subscription, requireExpired: true);

                $this->info(sprintf(
                    '✓ %s renewed until %s (%.2f XAF, balance %.2f).',
                    $subscription->username,
                    $subscription->expires_at->toDateTimeString(),
                    abs($transaction->amount),
                    $transaction->balance_after
                ));
                $renewed++;
            } catch (InsufficientBalanceException $exception) {
                $this->warn(sprintf(
                    '✗ %s skipped: insufficient balance (%.2f XAF needed, %.2f available).',
                    $subscription->username,
                    $exception->requested,
                    $exception->balance
                ));
                $skipped++;
            } catch (\RuntimeException $exception) {
                // Not renewable / already renewed elsewhere — informational.
                $this->warn("⏭ {$subscription->username} skipped: " . $exception->getMessage());
                $skipped++;
            } catch (Exception $exception) {
                $this->error("✗ {$subscription->username} failed: " . $exception->getMessage());
                Log::error("Auto-renew failed for subscription {$subscription->id}: " . $exception->getMessage());
                $failed++;
            }
        }

        $this->info("Wallet renewal run complete: {$renewed} renewed, {$skipped} skipped, {$failed} failed.");

        return self::SUCCESS;
    }
}
