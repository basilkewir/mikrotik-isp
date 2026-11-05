<?php

namespace App\Services\Payments;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\WalletTransaction;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Billing\WalletService;
use App\Support\CalendarWindow;
use Illuminate\Support\Facades\Log;

/**
 * Gateway-agnostic settlement of a confirmed payment.
 *
 * Every payment gateway (Monetbil today, others later — see
 * PaymentGatewayInterface) verifies the webhook and re-checks the
 * transaction status on its own terms, then hands the Payment row to
 * settle(). From there the behaviour is identical for every gateway:
 *
 *  - subscription payments extend the calendar window and reactivate the
 *    account on the router immediately (provision, enable, lift quarantine);
 *  - wallet payments credit the ledger and cascade into any armed
 *    (auto_renew) subscriptions that are due, so a topped-up wallet never
 *    leaves a customer waiting for the hourly cron.
 */
class PaymentSettlementService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SubscriptionRenewalService $renewals,
    ) {
    }

    public function settle(Payment $payment): void
    {
        $subscription = $payment->subscription_id
            ? $payment->subscription()->with(['plan', 'router'])->first()
            : null;

        if ($subscription) {
            $this->renewSubscription($payment, $subscription);

            return;
        }

        $this->creditWallet($payment);
        $this->cascadeAutoRenewals($payment->client);
    }

    /**
     * Extend the calendar window from the day after the current one and push
     * the account back onto the router.
     *
     * Provision first: if the router is unreachable the whole step fails
     * before anything else changes, so the gateway's retry still sees a
     * pending activation (the caller keeps the payment in a re-runnable
     * state and answers the webhook with an error).
     */
    private function renewSubscription(Payment $payment, Subscription $subscription): void
    {
        $days = (int) ($subscription->plan?->validity_days ?? 0);

        // Compute the new expiry under a row lock (serialises against a
        // concurrent enforcement pass) — but never hold the lock across the
        // RouterOS API calls below.
        $newExpiry = $days > 0
            ? $subscription->withRowLock(function (Subscription $fresh) use ($days) {
                return Subscription::calendarExpiry(
                    CalendarWindow::dayAfter($fresh->expires_at),
                    $days
                );
            })
            : null;

        $subscription->provisionOnRouter();
        $subscription->activateOnRouter($newExpiry, false);

        Log::info("Payment {$payment->payment_ref} ({$payment->gateway}) renewed subscription #{$subscription->id} ({$subscription->username}) through {$subscription->expires_at}.");
    }

    private function creditWallet(Payment $payment): void
    {
        $invoiceId = $payment->invoice_id;
        $gateway = $payment->gateway ?: 'gateway';

        $this->wallet->credit(
            $payment->client,
            (float) $payment->amount,
            $invoiceId ? WalletTransaction::TYPE_INVOICE_PAYMENT : WalletTransaction::TYPE_TOPUP,
            $invoiceId
                ? "Invoice #{$invoiceId} paid via {$gateway} ({$payment->payment_ref})"
                : "Wallet top-up via {$gateway} {$payment->payment_ref}",
            $payment
        );

        Log::info("Payment {$payment->payment_ref} settled, wallet credited {$payment->amount} XAF.");
    }

    /**
     * A fresh wallet credit may immediately cover an armed renewal that is
     * due — renew those now (portal or admin top-up → service back on
     * without waiting for isp:renew-from-wallet).
     *
     * Never throws: settlement of the payment itself already succeeded and
     * must not be undone by a failed renewal attempt.
     */
    private function cascadeAutoRenewals(Client $client): void
    {
        $due = Subscription::query()
            ->where('client_id', $client->id)
            ->where('auto_renew', true)
            ->whereIn('status', ['active', 'expired'])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->with(['plan', 'router'])
            ->get();

        foreach ($due as $subscription) {
            try {
                $this->renewals->renewFromWallet($subscription, requireExpired: true);

                Log::info("Auto-renew cascade renewed subscription #{$subscription->id} ({$subscription->username}) right after wallet payment.");
            } catch (InsufficientBalanceException|\RuntimeException $exception) {
                Log::info("Auto-renew cascade skipped subscription #{$subscription->id}: " . $exception->getMessage());
            } catch (\Exception $exception) {
                Log::warning("Auto-renew cascade failed for subscription #{$subscription->id}: " . $exception->getMessage());
            }
        }
    }
}
