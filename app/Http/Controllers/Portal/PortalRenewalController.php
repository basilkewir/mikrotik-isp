<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Payments\MonetbilGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Subscriber-initiated subscription renewal: charge the plan price through
 * the Monetbil widget or directly from the wallet; the calendar window
 * extends from the day after the current one closes.
 */
class PortalRenewalController extends Controller
{
    public function __construct(
        private readonly MonetbilGateway $gateway,
        private readonly SubscriptionRenewalService $renewals,
    ) {
    }

    public function renew(Request $request, Subscription $subscription): RedirectResponse
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        abort_unless($subscription->client_id === $client->id, 404);

        $price = $subscription->plan?->price;

        if (!$price || (float) $price <= 0) {
            return redirect()->route('portal.dashboard')
                ->with('error', 'This service cannot be renewed online — please contact support.');
        }

        if (!$this->gateway->isConfigured()) {
            return redirect()->route('portal.dashboard')
                ->with('error', 'Mobile Money payments are not available right now — please contact support.');
        }

        try {
            $result = $this->gateway->initiate(
                $client,
                (float) $price,
                $request->string('phone')->toString() ?: null,
                $request->string('operator')->toString() ?: null,
                "Renew {$subscription->plan->name} ({$subscription->username})",
                null,
                $subscription->id,
            );
        } catch (\RuntimeException $exception) {
            return redirect()->route('portal.dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->away($result['payment_url']);
    }

    /**
     * Pay for the next period straight from the wallet balance — no
     * Mobile Money prompt. Also serves "pay in advance": renewing early
     * stacks the new calendar window on top of the remaining days.
     */
    public function renewFromWallet(Subscription $subscription): RedirectResponse
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        abort_unless($subscription->client_id === $client->id, 404);

        try {
            $transaction = $this->renewals->renewFromWallet($subscription);
        } catch (\App\Exceptions\InsufficientBalanceException $exception) {
            return redirect()->route('portal.dashboard')->with(
                'error',
                sprintf(
                    'Not enough wallet balance: %s needed, %s available. Top up your wallet first.',
                    number_format($exception->requested, 0),
                    number_format($exception->balance, 0),
                )
            );
        } catch (\RuntimeException $exception) {
            return redirect()->route('portal.dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->route('portal.dashboard')->with(
            'success',
            sprintf(
                'Renewed from wallet (%s XAF). Your service now runs until %s.',
                number_format(abs($transaction->amount), 0),
                $subscription->expires_at?->format('d M Y H:i'),
            )
        );
    }

    /**
     * Let the client arm/disarm automatic wallet renewal for this
     * subscription (processed hourly by isp:renew-from-wallet).
     */
    public function toggleAutoRenew(Request $request, Subscription $subscription): RedirectResponse
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        abort_unless($subscription->client_id === $client->id, 404);

        $validated = $request->validate([
            'auto_renew' => 'required|boolean',
        ]);

        $subscription->update(['auto_renew' => (bool) $validated['auto_renew']]);

        return redirect()->route('portal.dashboard')->with(
            'success',
            $subscription->auto_renew
                ? "Auto-renew enabled: {$subscription->username} will be renewed from your wallet automatically when it expires."
                : "Auto-renew disabled for {$subscription->username}."
        );
    }
}
