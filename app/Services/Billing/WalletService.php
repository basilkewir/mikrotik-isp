<?php

namespace App\Services\Billing;

use App\Exceptions\InsufficientBalanceException;
use App\Models\Client;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WalletService
{
    /**
     * Credit the wallet (top-up, voucher, manual credit, refund, …).
     *
     * Always writes a ledger row with the resulting balance_after.
     */
    public function credit(
        Client $client,
        float $amount,
        string $type,
        string $description,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Wallet credit amount must be positive.');
        }

        return DB::transaction(function () use ($client, $amount, $type, $description, $reference, $createdBy) {
            /** @var Client $locked */
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $balanceAfter = number_format(round((float) $locked->balance + $amount, 2), 2, '.', '');

            $locked->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'client_id' => $locked->id,
                'amount' => number_format($amount, 2, '.', ''),
                'balance_after' => $balanceAfter,
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'created_by' => $createdBy,
            ]);
        });
    }

    /**
     * Debit the wallet (subscription charge, invoice payment, manual debit).
     *
     * @throws InsufficientBalanceException when the balance cannot cover the debit
     */
    public function debit(
        Client $client,
        float $amount,
        string $type,
        string $description,
        ?Model $reference = null,
        ?int $createdBy = null,
    ): WalletTransaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('Wallet debit amount must be positive.');
        }

        return DB::transaction(function () use ($client, $amount, $type, $description, $reference, $createdBy) {
            /** @var Client $locked */
            $locked = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();
            $current = round((float) $locked->balance, 2);

            if ($current < round($amount, 2)) {
                throw new InsufficientBalanceException(
                    $locked->id,
                    $locked->client_code,
                    $current,
                    $amount
                );
            }

            $balanceAfter = number_format(round($current - $amount, 2), 2, '.', '');
            $locked->update(['balance' => $balanceAfter]);

            return WalletTransaction::create([
                'client_id' => $locked->id,
                'amount' => number_format(-$amount, 2, '.', ''),
                'balance_after' => $balanceAfter,
                'type' => $type,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'created_by' => $createdBy,
            ]);
        });
    }
}
