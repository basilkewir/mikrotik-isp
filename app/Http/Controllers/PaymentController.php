<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\Payments\MonetbilGateway;
use App\Services\Payments\PaymentSettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly MonetbilGateway $gateway)
    {
    }

    public function index(Request $request): Response
    {
        $query = Payment::with(['client:id,name,client_code', 'invoice:id,number'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('payment_ref', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('operator_transaction_id', 'like', "%{$search}%");
            });
        }

        return Inertia::render('Payments/Index', [
            'payments' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['status', 'search']),
            'configured' => $this->gateway->isConfigured(),
        ]);
    }

    /**
     * Start a Mobile Money wallet top-up: creates a pending payment and
     * redirects the operator to the Monetbil hosted widget.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'amount' => 'required|integer|min:1|max:1000000',
            'phone' => 'nullable|string|max:30',
            'operator' => 'nullable|in:' . implode(',', array_keys(MonetbilGateway::OPERATORS)),
        ]);

        $client = Client::findOrFail($validated['client_id']);

        try {
            $result = $this->gateway->initiate(
                $client,
                (float) $validated['amount'],
                $validated['phone'] ?? null,
                $validated['operator'] ?? null,
                'Wallet top-up via Mobile Money'
            );
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->away($result['payment_url']);
    }

    /**
     * Customer lands here after the widget closes (return_url).
     */
    public function returnTo(Request $request, Payment $payment): RedirectResponse
    {
        // Captive-portal signup: the customer has no self-care session —
        // send them straight back to the hotspot login page so they can
        // connect (setting hotspot.portal_base_url, e.g. http://10.5.50.1).
        if ($payment->subscription_id && !auth('client')->check()) {
            $subscription = $payment->subscription;
            $portalBase = (string) Setting::getValue('hotspot.portal_base_url', '');

            if ($subscription && $subscription->auth_type === 'hotspot' && $portalBase !== '') {
                return redirect()->away(
                    rtrim($portalBase, '/') . '/login.html?paid=1&user=' . urlencode($subscription->username)
                );
            }
        }

        // Renewals belong to the self-care portal — the client is logged in
        // there, not as an admin.
        if ($payment->subscription_id || auth('client')->check()) {
            return redirect()->route('portal.dashboard')
                ->with('info', "Payment {$payment->payment_ref} started — your service is re-enabled automatically once Mobile Money confirms it.");
        }

        return redirect()->route('clients.show', $payment->client_id)
            ->with('info', "Payment {$payment->payment_ref} initiated — the wallet will be credited once Monetbil confirms the transaction.");
    }

    /**
     * Monetbil server-to-server notification (notify_url).
     *
     * Security: the signature is verified, and the status is re-checked
     * against Monetbil's checkPayment API before any money is credited.
     * Idempotent: a payment_ref is only ever credited once.
     */
    public function notify(Request $request, PaymentSettlementService $settlement)
    {
        // Monetbil may deliver via GET or POST.
        $payload = array_merge($request->query(), $request->post());

        if (!$this->gateway->verifyWebhookSignature($payload)) {
            Log::warning('Monetbil notify: invalid signature', ['payload' => $payload]);

            return response('Error: Invalid signature', 403);
        }

        $paymentRef = (string) ($payload['payment_ref'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');

        $payment = Payment::where('payment_ref', $paymentRef)->first();

        if (!$payment) {
            Log::warning('Monetbil notify: unknown payment_ref', ['payment_ref' => $paymentRef]);

            return response('received');
        }

        $subscription = $payment->subscription_id ? $payment->subscription : null;

        // Idempotency: a settled payment is only re-processed when a renewal
        // still needs to reach the router (the webhook can arrive while the
        // router is down; Monetbil then retries).
        if ($payment->status !== Payment::STATUS_PENDING) {
            $activationPending = $subscription
                && $payment->status === Payment::STATUS_SUCCESS
                && ($subscription->status !== 'active' || $subscription->isExpired());

            if (!$activationPending) {
                return response('received');
            }
        } else {
            // Trust nothing from the webhook body: ask Monetbil directly.
            try {
                $status = $this->gateway->checkTransactionStatus($transactionId);
            } catch (\RuntimeException $exception) {
                Log::error("Monetbil notify: status check failed for {$paymentRef}: " . $exception->getMessage());

                // 500 so Monetbil retries the notification later.
                return response('Status check failed', 500);
            }

            $payment->update([
                'operator_transaction_id' => $transactionId,
                'raw_payload' => array_merge($payment->raw_payload ?? [], ['webhook' => $payload]),
            ]);

            if (!MonetbilGateway::isSuccessStatus($status)) {
                $mapped = $status === -1 ? Payment::STATUS_CANCELLED : Payment::STATUS_FAILED;
                $payment->update(['status' => $mapped]);

                Log::info("Monetbil notify: payment {$paymentRef} ended as {$mapped} (status {$status}).");

                return response('received');
            }

            $payment->update([
                'status' => Payment::STATUS_SUCCESS,
                'paid_at' => now(),
                'phone' => $payment->phone ?? ($payload['phone'] ?? $payload['msisdn'] ?? null),
                'operator' => $payment->operator ?? ($payload['mobile_operator_name'] ?? null),
            ]);
        }

        // Gateway-specific verification ends here; settlement itself is
        // gateway-agnostic (PaymentSettlementService): subscription payments
        // renew + reactivate on the router, wallet payments credit the ledger
        // and cascade into armed auto-renewals.
        $settlement->settle($payment);

        return response('received');
    }
}
