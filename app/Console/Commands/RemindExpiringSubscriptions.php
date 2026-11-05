<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\SubscriptionReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'isp:remind-expiring';
    protected $description = 'Record expiry reminders for subscriptions ending within 3 days or 1 day (surfaced in the self-care portal)';

    /** @var array<string, int> reminder type => hours remaining at which it fires */
    private const THRESHOLDS = [
        SubscriptionReminder::TYPE_3D => 72,
        SubscriptionReminder::TYPE_1D => 24,
    ];

    public function handle(): int
    {
        $issued = 0;

        Subscription::query()
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addHours(max(array_values(self::THRESHOLDS))))
            ->with(['client', 'plan', 'router'])
            ->chunkById(100, function ($subscriptions) use (&$issued) {
                foreach ($subscriptions as $subscription) {
                    $issued += $this->issueFor($subscription);
                }
            });

        $this->info("Reminders issued this run: {$issued}.");

        return self::SUCCESS;
    }

    /**
     * Issue every threshold reminder this subscription currently qualifies
     * for. Idempotent per (subscription, threshold, expiry window): the
     * unique key on subscription_reminders makes a repeated run a no-op, and
     * a renewal that moves expires_at changes the window_key so the next
     * window gets its own fresh reminders.
     */
    private function issueFor(Subscription $subscription): int
    {
        $issued = 0;
        $hoursLeft = now()->diffInHours($subscription->expires_at, false);
        $windowKey = $subscription->expires_at->format('Y-m-d');

        foreach (self::THRESHOLDS as $type => $hours) {
            if ($hoursLeft > $hours) {
                continue;
            }

            $exists = $subscription->reminders()
                ->where('reminder_type', $type)
                ->where('window_key', $windowKey)
                ->exists();

            if ($exists) {
                continue;
            }

            $subscription->reminders()->create([
                'client_id' => $subscription->client_id,
                'reminder_type' => $type,
                'window_key' => $windowKey,
                'expires_at_snapshot' => $subscription->expires_at,
                'sent_at' => now(),
            ]);

            $issued++;

            Log::info('Expiry reminder issued', [
                'subscription_id' => $subscription->id,
                'username' => $subscription->username,
                'type' => $type,
                'expires_at' => $subscription->expires_at->toIso8601String(),
            ]);
        }

        return $issued;
    }
}
