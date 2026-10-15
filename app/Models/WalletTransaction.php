<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    public const TYPE_TOPUP = 'topup';
    public const TYPE_VOUCHER = 'voucher';
    public const TYPE_MANUAL_CREDIT = 'manual_credit';
    public const TYPE_MANUAL_DEBIT = 'manual_debit';
    public const TYPE_SUBSCRIPTION_CHARGE = 'subscription_charge';
    public const TYPE_INVOICE_PAYMENT = 'invoice_payment';
    public const TYPE_REFUND = 'refund';

    protected $fillable = [
        'client_id',
        'amount',
        'balance_after',
        'type',
        'reference_type',
        'reference_id',
        'description',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function isCredit(): bool
    {
        return $this->amount > 0;
    }
}
