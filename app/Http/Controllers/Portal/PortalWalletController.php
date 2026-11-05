<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Payments\MonetbilGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Self-care wallet top-up via Monetbil Mobile Money. The webhook
 * (PaymentController::notify) credits the wallet once the operator confirms
 * — nothing is credited from the browser's claims.
 */
class PortalWalletController extends Controller
{
    public function __construct(private readonly MonetbilGateway $gateway)
    {
    }

    public function topup(Request $request): RedirectResponse
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        if (!$this->gateway->isConfigured()) {
            return redirect()->route('portal.dashboard')
                ->with('error', 'Mobile Money payments are not available right now — please contact support.');
        }

        $validated = $request->validate([
            'amount' => 'required|integer|min:100|max:1000000',
            'phone' => 'nullable|string|max:30',
            'operator' => 'nullable|in:' . implode(',', array_keys(MonetbilGateway::OPERATORS)),
        ]);

        try {
            $result = $this->gateway->initiate(
                $client,
                (float) $validated['amount'],
                $validated['phone'] ?: null,
                $validated['operator'] ?: null,
                'Wallet top-up via Mobile Money',
            );
        } catch (\RuntimeException $exception) {
            return redirect()->route('portal.dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->away($result['payment_url']);
    }
}
