<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

/**
 * Creating a new subscription must create the subscriber's account on the
 * router in the same step (hotspot user or PPPoE secret, plus the plan's
 * profile hardening), and a router outage must leave the DB row saved with
 * a visible warning instead of a hard failure.
 */
class NewSubscriptionAccountTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(\App\Models\User::create([
            'name' => 'Operator',
            'email' => 'operator@test.local',
            'password' => bcrypt('secret'),
        ]));

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);

        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Client, 1: Plan, 2: Router} */
    private function makeCatalog(): array
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
            'name' => 'Monthly 3000',
            'code' => 'M30-3000',
            'validity_days' => 30,
            'price' => 3000,
            'hotspot_profile' => 'hsprof-monthly',
            'shared_users' => 2,
        ]);

        return [$client, $plan, $router];
    }

    public function test_new_hotspot_subscription_creates_the_user_account_on_the_router(): void
    {
        [$client, $plan, $router] = $this->makeCatalog();

        $response = $this->post(route('subscriptions.store'), [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => 'cust-new',
            'password' => 'secret-pw',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $subscription = Subscription::where('username', 'cust-new')->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertSame($client->id, $subscription->client_id);
        $this->assertSame($plan->id, $subscription->plan_id);

        // Monthly = 30 calendar days, day 1 = today → through end of Nov 16.
        $this->assertSame('2026-11-16', $subscription->expires_at->format('Y-m-d'));

        // The account exists on the router with the plan's profile and cap.
        $create = $this->fake->callsNamed('createOrUpdateHotspotUser')[0][1] ?? null;
        $this->assertNotNull($create);
        $this->assertSame('cust-new', $create['username']);
        $this->assertSame('secret-pw', $create['password']);
        $this->assertSame('hsprof-monthly', $create['profile']);

        $cap = $this->fake->callsNamed('ensureHotspotProfileDeviceCap')[0][1] ?? null;
        $this->assertNotNull($cap);
        $this->assertSame('hsprof-monthly', $cap['profile']);
        $this->assertSame(2, $cap['sharedUsers']);

        $this->assertTrue($this->fake->hasCall('ensureAutoReconnectProfile'));
        $this->assertTrue($this->fake->hasCall('ensureHotspotServerAuth'));
    }

    public function test_new_pppoe_subscription_creates_the_secret_on_the_router(): void
    {
        [$client, $plan, $router] = $this->makeCatalog();

        $response = $this->post(route('subscriptions.store'), [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'pppoe',
            'username' => 'ppp-new',
            'password' => 'secret-pw',
            'assigned_ip' => '192.168.10.60',
        ]);

        $response->assertSessionHas('success');

        $subscription = Subscription::where('username', 'ppp-new')->firstOrFail();
        $this->assertSame('pppoe', $subscription->auth_type);

        $create = $this->fake->callsNamed('createOrUpdatePppoeSecret')[0][1] ?? null;
        $this->assertNotNull($create);
        $this->assertSame('ppp-new', $create['username']);
        $this->assertFalse($this->fake->hasCall('createOrUpdateHotspotUser'));
    }

    public function test_router_outage_saves_the_subscription_with_a_warning(): void
    {
        [$client, $plan, $router] = $this->makeCatalog();
        $this->fake->failWith = new \RuntimeException('RouterOS down');

        $response = $this->post(route('subscriptions.store'), [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => 'cust-outage',
            'password' => 'secret-pw',
        ]);

        $response->assertSessionHas('warning');

        $subscription = Subscription::where('username', 'cust-outage')->firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
    }
}
