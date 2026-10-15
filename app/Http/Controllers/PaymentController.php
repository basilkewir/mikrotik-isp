<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Services\Billing\WalletService;
use App\Services\Payments\MonetbilGateway;
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
    public function notify(Request $request, WalletService $wallet)
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

        // Idempotency: already settled — acknowledge and stop.
        if ($payment->status !== Payment::STATUS_PENDING) {
            return response('received');
        }

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

        if (MonetbilGateway::isSuccessStatus($status)) {
            $payment->update([
                'status' => Payment::STATUS_SUCCESS,
                'paid_at' => now(),
                'phone' => $payment->phone ?? ($payload['phone'] ?? $payload['msisdn'] ?? null),
                'operator' => $payment->operator ?? ($payload['mobile_operator_name'] ?? null),
            ]);

            $invoiceId = $payment->invoice_id;

            $wallet->credit(
                $payment->client,
                (float) $payment->amount,
                $invoiceId ? WalletTransaction::TYPE_INVOICE_PAYMENT : WalletTransaction::TYPE_TOPUP,
                $invoiceId
                    ? "Invoice #{$invoiceId} paid via Mobile Money ({$payment->payment_ref})"
                    : "Mobile Money top-up {$payment->payment_ref}",
                $payment
            );

            Log::info("Monetbil notify: payment {$paymentRef} settled, wallet credited {$payment->amount} XAF.");
        } else {
            $mapped = $status === -1 ? Payment::STATUS_CANCELLED : Payment::STATUS_FAILED;
            $payment->update(['status' => $mapped]);

            Log::info("Monetbil notify: payment {$paymentRef} ended as {$mapped} (status {$status}).");
        }

        return response('received');
    }
}
