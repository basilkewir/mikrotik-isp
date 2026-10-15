<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class InvoiceService
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    /**
     * Create an invoice with line items. Number format: INV-YYYYMM-xxxx.
     */
    public function create(
        Client $client,
        array $items,
        ?string $periodStart = null,
        ?string $periodEnd = null,
        ?int $subscriptionId = null,
    ): Invoice {
        if (empty($items)) {
            throw new \InvalidArgumentException('An invoice needs at least one line item.');
        }

        return DB::transaction(function () use ($client, $items, $periodStart, $periodEnd, $subscriptionId) {
            $invoice = Invoice::create([
                'client_id' => $client->id,
                'subscription_id' => $subscriptionId,
                'number' => $this->nextNumber(),
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'amount' => 0,
                'status' => Invoice::STATUS_UNPAID,
            ]);

            $total = 0;

            foreach ($items as $item) {
                $amount = round((float) $item['amount'], 2);
                $total += $amount;

                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => (string) $item['description'],
                    'amount' => number_format($amount, 2, '.', ''),
                ]);
            }

            $invoice->update(['amount' => number_format($total, 2, '.', '')]);

            return $invoice->fresh('items');
        });
    }

    /**
     * Pay an unpaid invoice from the client wallet.
     *
     * @throws InsufficientBalanceException
     */
    public function payFromWallet(Invoice $invoice): WalletTransaction
    {
        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new \RuntimeException("Invoice {$invoice->number} is already paid.");
        }

        if ($invoice->status !== Invoice::STATUS_UNPAID) {
            throw new \RuntimeException("Invoice {$invoice->number} is {$invoice->status} and cannot be paid.");
        }

        return DB::transaction(function () use ($invoice) {
            $transaction = $this->wallet->debit(
                $invoice->client,
                (float) $invoice->amount,
                WalletTransaction::TYPE_INVOICE_PAYMENT,
                "Invoice {$invoice->number} paid from wallet",
                $invoice
            );

            $invoice->update([
                'status' => Invoice::STATUS_PAID,
                'paid_at' => now(),
            ]);

            return $transaction;
        });
    }

    public function cancel(Invoice $invoice): void
    {
        if ($invoice->status === Invoice::STATUS_PAID) {
            throw new \RuntimeException("Invoice {$invoice->number} is already paid and cannot be cancelled.");
        }

        $invoice->update(['status' => Invoice::STATUS_CANCELLED]);
    }

    private function nextNumber(): string
    {
        $prefix = 'INV-' . now()->format('YM') . '-';

        $last = Invoice::where('number', 'like', $prefix . '%')
            ->orderByDesc('number')
            ->value('number');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return $prefix . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
