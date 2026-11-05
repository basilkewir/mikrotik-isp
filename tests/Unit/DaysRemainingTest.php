<?php

namespace Tests\Unit;

use App\Support\DaysRemaining;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class DaysRemainingTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_it_reports_days_in_english_and_french(): void
    {
        $now = Carbon::parse('2026-11-04 09:00:00');

        $result = DaysRemaining::for(Carbon::parse('2026-11-07 23:59:59'), $now);

        $this->assertSame(3, $result['days']);
        $this->assertFalse($result['expired']);
        $this->assertSame('3 days left', $result['en']);
        $this->assertSame('3 jours restants', $result['fr']);
    }

    public function test_singular_and_last_day_wordings(): void
    {
        $now = Carbon::parse('2026-11-04 09:00:00');

        $tomorrow = DaysRemaining::for(Carbon::parse('2026-11-05 23:59:59'), $now);
        $this->assertSame('1 day left', $tomorrow['en']);
        $this->assertSame('1 jour restant', $tomorrow['fr']);

        $today = DaysRemaining::for(Carbon::parse('2026-11-04 23:59:59'), $now);
        $this->assertSame(0, $today['days']);
        $this->assertSame('Last day today', $today['en']);
        $this->assertSame("Dernier jour aujourd'hui", $today['fr']);
    }

    public function test_a_past_expiry_is_expired(): void
    {
        $now = Carbon::parse('2026-11-04 09:00:00');

        $result = DaysRemaining::for(Carbon::parse('2026-11-03 23:59:59'), $now);

        $this->assertTrue($result['expired']);
        $this->assertSame('Expired', $result['en']);
        $this->assertSame('Expiré', $result['fr']);
    }

    public function test_no_expiry_means_no_time_limit(): void
    {
        $result = DaysRemaining::for(null);

        $this->assertSame('No time limit', $result['en']);
        $this->assertSame('Sans limite de durée', $result['fr']);
    }
}
