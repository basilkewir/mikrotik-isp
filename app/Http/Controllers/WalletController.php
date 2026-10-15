<?php

namespace App\Http\Controllers;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Client;
use App\Services\Billing\WalletService;
use App\Services\Billing\VoucherService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * Redeem a voucher code and credit the client wallet.
     */
    public function redeem(Request $request, Client $client, VoucherService $vouchers): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:30',
        ]);

        try {
            $transaction = $vouchers->redeem($validated['code'], $client);
        } catch (\RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Voucher redeemed: +%.2f XAF. New balance: %.2f XAF.',
            $transaction->amount,
            $transaction->balance_after
        ));
    }

    /**
     * Manual wallet top-up from the admin panel.
     */
    public function topup(Request $request, Client $client, WalletService $wallet): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'description' => 'nullable|string|max:255',
        ]);

        $transaction = $wallet->credit(
            $client,
            (float) $validated['amount'],
            \App\Models\WalletTransaction::TYPE_MANUAL_CREDIT,
            $validated['description'] ?? 'Manual top-up (admin)',
        );

        return back()->with('success', sprintf(
            'Wallet topped up: +%.2f XAF. New balance: %.2f XAF.',
            $transaction->amount,
            $transaction->balance_after
        ));
    }

    /**
     * Manual wallet debit (adjustment / correction).
     */
    public function debit(Request $request, Client $client, WalletService $wallet): RedirectResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:1|max:999999999',
            'description' => 'nullable|string|max:255',
        ]);

        try {
            $transaction = $wallet->debit(
                $client,
                (float) $validated['amount'],
                \App\Models\WalletTransaction::TYPE_MANUAL_DEBIT,
                $validated['description'] ?? 'Manual debit (admin)',
            );
        } catch (InsufficientBalanceException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', sprintf(
            'Wallet debited: -%.2f XAF. New balance: %.2f XAF.',
            abs($transaction->amount),
            $transaction->balance_after
        ));
    }
}
