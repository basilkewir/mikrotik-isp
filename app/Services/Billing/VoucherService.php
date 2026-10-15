<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Voucher;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherService
{
    public function __construct(private readonly WalletService $wallet)
    {
    }

    /**
     * Generate a batch of vouchers.
     *
     * @param int $count     Number of vouchers (1–500)
     * @param int $value     XAF value credited to the wallet per redemption
     * @param ?int $planId   Optional plan attached to the voucher (informational)
     * @return Voucher[] Generated vouchers
     */
    public function generateBatch(int $count, int $value, ?int $planId = null, ?string $batchName = null, int $length = 10): array
    {
        if ($count < 1 || $count > 500) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 500 vouchers.');
        }

        if ($value <= 0) {
            throw new \InvalidArgumentException('Voucher value must be positive.');
        }

        if ($planId !== null && !Plan::whereKey($planId)->exists()) {
            throw new \InvalidArgumentException("Plan {$planId} does not exist.");
        }

        $batchName = $batchName ?: 'BATCH-' . now()->format('Ymd-His');
        $vouchers = [];
        $attempts = 0;

        while (count($vouchers) < $count) {
            if (++$attempts > $count * 10) {
                throw new \RuntimeException('Could not generate unique voucher codes — try again.');
            }

            $code = strtoupper(Str::random($length));

            // Avoid ambiguous characters for operator readability.
            $code = strtr($code, ['0' => 'Q', 'O' => 'P', '1' => 'I', 'L' => 'M']);

            if (Voucher::where('code', $code)->exists()) {
                continue;
            }

            $vouchers[] = Voucher::create([
                'code' => $code,
                'plan_id' => $planId,
                'value' => number_format($value, 2, '.', ''),
                'status' => Voucher::STATUS_ACTIVE,
                'batch_name' => $batchName,
            ]);
        }

        return $vouchers;
    }

    /**
     * Redeem a voucher code for a client: credits the wallet with a ledger row.
     */
    public function redeem(string $code, Client $client): WalletTransaction
    {
        $code = strtoupper(trim($code));

        return DB::transaction(function () use ($code, $client) {
            /** @var Voucher|null $voucher */
            $voucher = Voucher::where('code', $code)->lockForUpdate()->first();

            if (!$voucher) {
                throw new \RuntimeException("Voucher {$code} not found.");
            }

            if ($voucher->status !== Voucher::STATUS_ACTIVE) {
                throw new \RuntimeException("Voucher {$code} is {$voucher->status} and cannot be redeemed.");
            }

            $transaction = $this->wallet->credit(
                $client,
                (float) $voucher->value,
                WalletTransaction::TYPE_VOUCHER,
                "Voucher {$voucher->code} redeemed" . ($voucher->batch_name ? " ({$voucher->batch_name})" : ''),
                $voucher
            );

            $voucher->update([
                'status' => Voucher::STATUS_REDEEMED,
                'redeemed_by' => $client->id,
                'redeemed_at' => now(),
            ]);

            return $transaction;
        });
    }
}
