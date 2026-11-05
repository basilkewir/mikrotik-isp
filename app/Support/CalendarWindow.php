<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Calendar-day access windows.
 *
 * Access is measured in whole days of the calendar, never in accumulated
 * hours: a 3-day window that starts on Oct 17 runs through the end of Oct 19
 * and is cut at 00:00 on Oct 20. The day the window starts counts as day 1.
 */
class CalendarWindow
{
    /**
     * End of the calendar window lasting $days days and starting on $from.
     */
    public static function endOf(int $days, ?Carbon $from = null): Carbon
    {
        $from = $from ?? Carbon::now();

        return $from->copy()->startOfDay()->addDays(max(1, $days) - 1)->endOfDay();
    }

    /**
     * Day after a previous window closes — the first day of a renewal that
     * must neither overlap nor leave a gap behind it.
     */
    public static function dayAfter(?Carbon $previousExpiry, ?Carbon $from = null): Carbon
    {
        if ($previousExpiry && $previousExpiry->isFuture()) {
            return $previousExpiry->copy()->addDay();
        }

        return ($from ?? Carbon::now())->copy();
    }
}
