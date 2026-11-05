<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Models\Subscription;
use App\Services\Billing\SubscriptionRenewalService;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

/**
 * A successful wallet renewal (portal button or hourly isp:renew-from-wallet)
 * must reactivate the account on the router directly: user/secret enabled,
 * EXPIRED_USERS quarantine lifted, expiry extended, money charged once.
 */
class WalletRenewalReactivationTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);

        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeSubscription(array $overrides = []): Subscription
    {
        $router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.0.2.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $client = Client::create([
            'client_code' => 'CLI-TEST-1',
            'name' => 'Test Customer',
            'balance' => 10000,
        ]);

        $plan = Plan::create([
            'name' => '30 Days',
            'code' => 'T30',
            'validity_days' => 30,
            'price' => 5000,
        ]);

        return Subscription::create(array_merge([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => 'cust1',
            'password' => 'pw',
            'assigned_ip' => '192.168.10.50',
            'status' => 'active',
            'start_at' => now()->subDays(31),
            'expires_at' => now()->subHour(),
        ], $overrides));
    }

    public function test_wallet_renewal_reactivates_a_cut_off_account(): void
    {
        $subscription = $this->makeSubscription([
            'status' => 'expired',
            'enforcement_status' => Subscription::ENFORCEMENT_ENFORCED,
            'enforced_at' => now()->subHour(),
            'expired_at' => now()->subHour(),
        ]);

        $transaction = app(SubscriptionRenewalService::class)->renewFromWallet($subscription);

        $subscription->refresh();

        // Paid state in the database.
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertNull($subscription->enforcement_status);
        $this->assertSame('5000.00', $subscription->client->refresh()->balance);
        $this->assertNotNull($transaction);

        // Router state reactivated directly: user enabled, quarantine lifted.
        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));

        $this->assertTrue(
            RouterActivityLog::where('event', 'subscription_renew')
                ->where('subscription_id', $subscription->id)
                ->exists()
        );
    }

    public function test_hourly_auto_renew_command_reactivates_the_account(): void
    {
        $subscription = $this->makeSubscription([
            'auto_renew' => true,
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('isp:renew-from-wallet')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertSame('5000.00', $subscription->client->refresh()->balance);

        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));
    }

    public function test_router_outage_keeps_the_paid_renewal_in_the_database(): void
    {
        $subscription = $this->makeSubscription(['expires_at' => now()->subHour()]);
        $this->fake->failWith = new RuntimeException('RouterOS down');

        $transaction = app(SubscriptionRenewalService::class)->renewFromWallet($subscription);

        $subscription->refresh();

        // The money is honoured even though the router call failed.
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertSame('5000.00', $subscription->client->refresh()->balance);
        $this->assertNotNull($transaction);

        // Reactivation was attempted and aborted — nothing claims success.
        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertFalse($this->fake->hasCall('enableHotspotUser'));
        $this->assertFalse(
            RouterActivityLog::where('event', 'subscription_renew')
                ->where('subscription_id', $subscription->id)
                ->exists()
        );
    }

    public function test_wallet_renewal_requires_a_priced_plan(): void
    {
        $subscription = $this->makeSubscription(['expires_at' => now()->addDays(10)]);
        $subscription->plan->update(['price' => 0]);

        $this->expectException(\RuntimeException::class);

        app(SubscriptionRenewalService::class)->renewFromWallet($subscription);
    }
}
