<?php

namespace App\Console\Commands;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RenewFromWallet extends Command
{
    protected $signature = 'isp:renew-from-wallet';
    protected $description = 'Auto-renew expired subscriptions from the client wallet when auto_renew is enabled';

    public function handle(WalletService $wallet): int
    {
        $candidates = Subscription::query()
            ->where('status', 'active')
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
            $plan = $subscription->plan;
            $client = $subscription->client;

            if (!$plan || !$client || !$plan->validity_days) {
                $skipped++;
                continue;
            }

            try {
                $transaction = $wallet->debit(
                    $client,
                    (float) $plan->price,
                    WalletTransaction::TYPE_SUBSCRIPTION_CHARGE,
                    "Auto-renew subscription {$subscription->username} ({$plan->name})",
                    $subscription
                );

                $base = $subscription->expires_at->isFuture() ? $subscription->expires_at : now();
                $subscription->update([
                    'expires_at' => $base->copy()->addDays((int) $plan->validity_days),
                    'status' => 'active',
                ]);

                try {
                    $subscription->provisionOnRouter();
                } catch (Exception $exception) {
                    Log::warning("Subscription {$subscription->id} renewed in DB but router re-provision failed: " . $exception->getMessage());
                }

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
