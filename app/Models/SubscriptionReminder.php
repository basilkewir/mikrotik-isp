<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reminder that a subscriber's access window is ending (or that a new
 * window needs attention). One row per (subscription, threshold, expiry
 * window) — a renewal that moves expires_at opens a fresh window_key and
 * therefore a fresh set of reminders.
 *
 * "Sending" is currently portal-side (the dashboard renders these as a
 * persistent notice) because imported clients have no email/phone on file;
 * an SMS/email channel can later deliver the same rows.
 */
class SubscriptionReminder extends Model
{
    public const TYPE_3D = '3d';
    public const TYPE_1D = '1d';

    protected $table = 'subscription_reminders';

    protected $fillable = [
        'subscription_id',
        'client_id',
        'reminder_type',
        'window_key',
        'expires_at_snapshot',
        'sent_at',
    ];

    protected $casts = [
        'expires_at_snapshot' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Human-readable notice text for the portal, in English and French.
     *
     * @return array{en: string, fr: string}
     */
    public function message(): array
    {
        $plan = $this->subscription?->plan?->name ?? 'your internet service';
        $planFr = $this->subscription?->plan?->name ?? 'votre service internet';
        $date = $this->expires_at_snapshot?->format('d M Y') ?? '';

        return match ($this->reminder_type) {
            self::TYPE_1D => [
                'en' => "Your {$plan} expires tomorrow ({$date}). Renew now to stay online without interruption.",
                'fr' => "Votre {$planFr} expire demain ({$date}). Renouvelez maintenant pour rester en ligne sans interruption.",
            ],
            default => [
                'en' => "Your {$plan} expires in 3 days ({$date}). Renew or top up your wallet to stay online.",
                'fr' => "Votre {$planFr} expire dans 3 jours ({$date}). Renouvelez ou rechargez votre portefeuille pour rester en ligne.",
            ],
        };
    }
}
