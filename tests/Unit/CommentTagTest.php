<?php

namespace Tests\Unit;

use App\Services\Mikrotik\MikrotikService;
use PHPUnit\Framework\TestCase;

class CommentTagTest extends TestCase
{
    private const TAG = 'device:7|client:3|AA:BB:CC:DD:EE:FF';

    public function test_untagged_comment_is_replaced_wholesale(): void
    {
        $this->assertSame('Front camera', MikrotikService::composeComment('old note', 'Front camera'));
        $this->assertSame('Front camera', MikrotikService::composeComment(null, 'Front camera'));
    }

    public function test_ownership_tag_survives_a_new_note(): void
    {
        $this->assertSame(
            self::TAG . ' — Front camera',
            MikrotikService::composeComment(self::TAG, 'Front camera')
        );
    }

    public function test_previous_note_is_replaced_but_the_tag_stays(): void
    {
        $this->assertSame(
            self::TAG . ' — Kitchen AP',
            MikrotikService::composeComment(self::TAG . ' — Old note', 'Kitchen AP')
        );
    }

    public function test_clearing_a_note_keeps_the_ownership_tag(): void
    {
        $this->assertSame(self::TAG, MikrotikService::composeComment(self::TAG . ' — Old note', '   '));
        $this->assertSame('', MikrotikService::composeComment('old note', ''));
    }

    public function test_display_hides_the_ownership_tag(): void
    {
        $this->assertSame('Front camera', MikrotikService::displayComment(self::TAG . ' — Front camera'));
        $this->assertNull(MikrotikService::displayComment(self::TAG));
        $this->assertNull(MikrotikService::displayComment('   '));
        $this->assertNull(MikrotikService::displayComment(null));
    }

    public function test_display_keeps_plain_comments_untouched(): void
    {
        $this->assertSame('uplink switch', MikrotikService::displayComment('uplink switch'));
        $this->assertSame('-5V sensor', MikrotikService::displayComment('-5V sensor'));
    }

    public function test_tag_survives_a_round_trip_through_compose_and_display(): void
    {
        $stored = MikrotikService::composeComment(self::TAG, 'Security camera');

        $this->assertSame('Security camera', MikrotikService::displayComment($stored));
        $this->assertSame(
            self::TAG . ' — Next note',
            MikrotikService::composeComment($stored, 'Next note')
        );
    }
}
