<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A command queued for a router that the platform cannot reach directly.
 * The router agent pulls pending commands and reports the result.
 */
class RouterAgentCommand extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'router_id',
        'command',
        'payload',
        'status',
        'result',
        'queued_at',
        'delivered_at',
        'finished_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'queued_at' => 'datetime',
        'delivered_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }
}
