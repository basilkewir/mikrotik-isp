<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Plan;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices)
    {
    }

    public function index(Request $request): Response
    {
        $query = Invoice::with(['client:id,name,client_code', 'items'])->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }

        return Inertia::render('Invoices/Index', [
            'invoices' => $query->paginate(25)->withQueryString(),
            'filters' => $request->only(['status', 'client_id']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'client_code']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'items' => 'required|array|min:1|max:20',
            'items.*.description' => 'required|string|max:255',
            'items.*.amount' => 'required|numeric|min:0.01|max:999999999',
        ]);

        try {
            $invoice = $this->invoices->create(
                Client::findOrFail($validated['client_id']),
                $validated['items'],
                $validated['period_start'] ?? null,
                $validated['period_end'] ?? null,
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} created ({$invoice->amount} XAF).");
    }

    /**
     * Pay an invoice directly from the client wallet.
     */
    public function pay(Request $request, Invoice $invoice): RedirectResponse
    {
        try {
            $transaction = $this->invoices->payFromWallet($invoice);
        } catch (InsufficientBalanceException | \RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Invoice %s paid from wallet (%.2f XAF). Balance: %.2f XAF.',
            $invoice->number,
            abs($transaction->amount),
            $transaction->balance_after
        ));
    }

    public function cancel(Invoice $invoice): RedirectResponse
    {
        try {
            $this->invoices->cancel($invoice);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} cancelled.");
    }
}
