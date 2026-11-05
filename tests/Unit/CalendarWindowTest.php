<?php

namespace Tests\Unit;

use App\Models\Subscription;
use App\Support\CalendarWindow;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class CalendarWindowTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_thirty_day_window_counts_the_start_day_as_day_one(): void
    {
        $start = Carbon::parse('2026-10-18 10:00:00');

        $end = CalendarWindow::endOf(30, $start);

        $this->assertSame('2026-11-16', $end->toDateString());
        $this->assertSame('23:59:59', $end->format('H:i:s'));
    }

    public function test_single_day_window_closes_the_same_day(): void
    {
        $end = CalendarWindow::endOf(1, Carbon::parse('2026-10-18 00:30:00'));

        $this->assertSame('2026-10-18', $end->toDateString());
    }

    public function test_calendar_expiry_matches_end_of_window(): void
    {
        $expiry = Subscription::calendarExpiry(Carbon::parse('2026-10-18 10:00:00'), 7);

        $this->assertSame('2026-10-24', $expiry->toDateString());
    }

    public function test_leap_day_is_preserved_in_a_february_window(): void
    {
        $end = CalendarWindow::endOf(2, Carbon::parse('2028-02-28 09:00:00'));

        $this->assertSame('2028-02-29', $end->toDateString());
    }

    public function test_day_after_continues_from_a_future_expiry(): void
    {
        $previous = Carbon::parse('2026-10-20 23:59:59');

        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        $this->assertSame(
            '2026-10-21',
            CalendarWindow::dayAfter($previous)->toDateString()
        );
    }

    public function test_day_after_falls_back_to_now_once_the_window_lapsed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-25 08:00:00'));

        $previous = Carbon::parse('2026-10-20 23:59:59');

        $this->assertSame(
            '2026-10-25',
            CalendarWindow::dayAfter($previous)->toDateString()
        );
    }

    public function test_renewal_chains_do_not_overlap_or_leave_a_gap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));

        $first = CalendarWindow::endOf(3, Carbon::parse('2026-10-17 08:00:00'));
        $second = CalendarWindow::endOf(3, CalendarWindow::dayAfter($first));

        // First window: Oct 17–19. Renewal starts Oct 20 and runs Oct 20–22.
        $this->assertSame('2026-10-19', $first->toDateString());
        $this->assertSame('2026-10-22', $second->toDateString());
    }
}
