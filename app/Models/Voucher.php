<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Voucher extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'code',
        'plan_id',
        'value',
        'status',
        'batch_name',
        'redeemed_by',
        'redeemed_at',
        'created_by',
    ];

    protected $casts = [
        'value' => 'decimal:2',
        'redeemed_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function redeemer(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'redeemed_by');
    }
}
