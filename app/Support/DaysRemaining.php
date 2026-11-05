<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Days left on an access window, counted in calendar days and worded in
 * English and French.
 *
 * "0 days" means today is the last day (access ends at 23:59:59 tonight);
 * "1 day" means tomorrow is the last day. Expiry on an earlier calendar day
 * is reported as expired.
 */
class DaysRemaining
{
    /**
     * @return array{days: int, expired: bool, en: string, fr: string}
     */
    public static function for(?Carbon $expiresAt, ?Carbon $now = null): array
    {
        $now = ($now ?? Carbon::now())->copy();

        if ($expiresAt === null) {
            return ['days' => PHP_INT_MAX, 'expired' => false, 'en' => 'No time limit', 'fr' => 'Sans limite de durée'];
        }

        $today = $now->copy()->startOfDay();
        $lastDay = $expiresAt->copy()->startOfDay();
        $days = (int) $today->diffInDays($lastDay, false);

        if ($days < 0 || ($days === 0 && $now->greaterThan($expiresAt))) {
            return ['days' => 0, 'expired' => true, 'en' => 'Expired', 'fr' => 'Expiré'];
        }

        if ($days === 0) {
            return [
                'days' => 0,
                'expired' => false,
                'en' => 'Last day today',
                'fr' => "Dernier jour aujourd'hui",
            ];
        }

        return [
            'days' => $days,
            'expired' => false,
            'en' => $days === 1 ? '1 day left' : "{$days} days left",
            'fr' => $days === 1 ? '1 jour restant' : "{$days} jours restants",
        ];
    }
}
