<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Models\SubscriptionBoundDevice;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

class EnforcementRaceTest extends TestCase
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

    private function makeExpiredHotspotSubscription(): Subscription
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
        ]);

        $plan = Plan::create([
            'name' => '30 Days',
            'code' => 'T30',
            'validity_days' => 30,
            'price' => 5000,
        ]);

        return Subscription::create([
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
        ]);
    }

    public function test_expired_subscription_is_enforced_on_the_router_and_tracked(): void
    {
        $subscription = $this->makeExpiredHotspotSubscription();

        $bound = $subscription->boundDevices()->create([
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'bound_at' => now()->subDays(30),
            'last_seen_at' => now(),
            'is_active' => true,
        ]);

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame('expired', $subscription->status);
        $this->assertSame(Subscription::ENFORCEMENT_ENFORCED, $subscription->enforcement_status);
        $this->assertNotNull($subscription->expired_at);
        $this->assertNotNull($subscription->enforced_at);
        $this->assertTrue($this->fake->hasCall('disableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('isolateIp'));
        $this->assertFalse($bound->refresh()->is_active);
    }

    public function test_renewal_during_enforcement_heals_instead_of_blocking(): void
    {
        $subscription = $this->makeExpiredHotspotSubscription();

        // Simulate a payment webhook landing while the router call is in flight.
        $this->fake->beforeDisable = function () use ($subscription) {
            $subscription->fresh()->update([
                'expires_at' => now()->addDays(30)->endOfDay(),
                'status' => 'active',
            ]);
        };

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();

        $this->assertSame('active', $subscription->status);
        $this->assertNull($subscription->enforcement_status);
        $this->assertTrue($subscription->expires_at->isFuture());

        // The account was disabled mid-flight, then restored.
        $this->assertTrue($this->fake->hasCall('disableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
    }

    public function test_router_failure_is_recorded_and_retried_next_run(): void
    {
        $subscription = $this->makeExpiredHotspotSubscription();
        $this->fake->failWith = new RuntimeException('RouterOS down');

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();

        // Not reported as enforced: status untouched, failure visible.
        $this->assertSame('active', $subscription->status);
        $this->assertSame(Subscription::ENFORCEMENT_FAILED, $subscription->enforcement_status);
        $this->assertStringContainsString('RouterOS down', (string) $subscription->last_enforcement_error);

        // Retry succeeds once the router is reachable again.
        $this->fake->failWith = null;
        $this->fake->calls = [];

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertSame(Subscription::ENFORCEMENT_ENFORCED, $subscription->enforcement_status);
    }

    public function test_healthy_active_subscription_is_left_alone(): void
    {
        $subscription = $this->makeExpiredHotspotSubscription();
        $subscription->update(['expires_at' => now()->addDays(10)]);

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertFalse($this->fake->hasCall('disableHotspotUser'));
    }

    public function test_data_quota_exceeded_is_enforced_too(): void
    {
        $subscription = $this->makeExpiredHotspotSubscription();
        $subscription->update([
            'expires_at' => now()->addDays(10),
            'data_quota_bytes' => 1000,
            'data_used_bytes' => 1500,
        ]);

        $this->artisan('isp:enforce-quotas')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertTrue($this->fake->hasCall('disableHotspotUser'));
    }
}
