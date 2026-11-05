<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalanceException;
use App\Models\RouterActivityLog;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Support\CalendarWindow;
use Illuminate\Support\Facades\Log;

/**
 * Single wallet-funded renewal path, shared by the hourly auto-renew
 * command and the self-care portal "Renew from wallet" button.
 *
 * Money movement and the expiry extension are each atomic and the extension
 * always recomputes its base from the freshly locked row, so a concurrent
 * webhook renewal (or enforcement pass) can never cause a double charge or
 * an extension built on a stale expiry.
 */
class SubscriptionRenewalService
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    /**
     * Charge the client's wallet for one plan period and extend the
     * subscription from the day after its current window closes.
     *
     * @param  bool  $requireExpired  when true (auto-renew job), a subscription
     *                                whose window is still in the future is skipped
     *                                instead of being charged for an early extension.
     *
     * @throws InsufficientBalanceException
     * @throws \RuntimeException when the subscription is not in a renewable state
     */
    public function renewFromWallet(Subscription $subscription, bool $requireExpired = false): WalletTransaction
    {
        $plan = $subscription->plan;
        $client = $subscription->client;

        if (!$plan || !$client || !$plan->validity_days || (float) $plan->price <= 0) {
            throw new \RuntimeException('This subscription cannot be renewed from the wallet (no priced plan with validity).');
        }

        if (!in_array($subscription->status, ['active', 'expired'], true)) {
            throw new \RuntimeException("A {$subscription->status} subscription cannot be renewed online — please contact support.");
        }

        if ($requireExpired) {
            // Recheck under a row lock BEFORE charging: a webhook or portal
            // renewal may have already covered this subscription. 'expired'
            // counts too — the 1-minute enforcement pass normally flips the
            // status before the hourly cron ever sees the subscription.
            $stillDue = $subscription->withRowLock(function (Subscription $fresh) {
                return in_array($fresh->status, ['active', 'expired'], true) && $fresh->isExpired();
            });

            if (!$stillDue) {
                throw new \RuntimeException('Subscription was already renewed by another path — no charge made.');
            }
        }

        // 1. Charge (throws InsufficientBalanceException — nothing modified yet).
        $transaction = $this->wallet->debit(
            $client,
            (float) $plan->price,
            WalletTransaction::TYPE_SUBSCRIPTION_CHARGE,
            "Wallet renewal: {$subscription->username} ({$plan->name})",
            $subscription,
        );

        // 2. Extend from the freshly locked row — each successful payment
        //    buys one plan period on top of whatever the row says right now.
        $extended = $subscription->withRowLock(function (Subscription $fresh) use ($plan) {
            $base = CalendarWindow::dayAfter($fresh->expires_at);
            $newExpiry = Subscription::calendarExpiry($base, (int) $plan->validity_days);

            $fresh->update([
                'expires_at' => $newExpiry,
                'status' => 'active',
                'enforcement_status' => null,
                'last_enforcement_error' => null,
            ]);

            return $newExpiry;
        });

        if ($extended === null) {
            // Row vanished mid-flight; the debit already happened — surface
            // loudly rather than pretending success.
            Log::error("Wallet renewal for subscription {$subscription->id}: debit applied but the subscription row disappeared before extension.");
            throw new \RuntimeException('Renewal could not be applied — please contact support with your payment reference ' . $transaction->id . '.');
        }

        // 3. Push the renewed state to the router and reactivate the account
        //    immediately: provisioning rewrites the user/secret but leaves a
        //    cut-off account disabled and assigned_ip stuck in the
        //    EXPIRED_USERS quarantine. A router outage must not undo a paid
        //    renewal: the next provisioning run (or enforcement heal)
        //    reconciles; the customer's money is already honoured in DB.
        try {
            $subscription->refresh()->provisionOnRouter();
            $subscription->applyEnabledOnRouterState();

            RouterActivityLog::create([
                'router_id' => $subscription->router_id,
                'subscription_id' => $subscription->id,
                'event' => 'subscription_renew',
                'level' => 'info',
                'details' => [
                    'username' => $subscription->username,
                    'auth_type' => $subscription->auth_type,
                    'source' => 'wallet',
                    'transaction_id' => $transaction->id,
                    'expires_at' => $subscription->expires_at?->toIso8601String(),
                ],
                'ip_address' => $subscription->assigned_ip,
            ]);
        } catch (\Exception $exception) {
            Log::warning("Subscription {$subscription->id} renewed from wallet but router re-provision failed: " . $exception->getMessage());
        }

        $subscription->refresh();

        return $transaction;
    }
}
