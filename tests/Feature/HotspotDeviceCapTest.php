<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

class HotspotDeviceCapTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);
    }

    private function makeHotspotSubscription(array $planOverrides = []): Subscription
    {
        $router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.0.2.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $client = Client::create([
            'client_code' => 'CLI-CAP-1',
            'name' => 'Cap Customer',
        ]);

        $plan = Plan::create(array_merge([
            'name' => '30 Days Shared',
            'code' => 'T30S',
            'validity_days' => 30,
            'price' => 5000,
            'hotspot_profile' => 'hsprof-cap',
            'shared_users' => 3,
        ], $planOverrides));

        return Subscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => 'capuser',
            'password' => 'pw',
            'assigned_ip' => '192.168.10.60',
            'status' => 'active',
            'start_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function test_provisioning_pushes_the_plan_device_cap_to_the_hotspot_profile(): void
    {
        $subscription = $this->makeHotspotSubscription();

        $subscription->provisionOnRouter();

        $this->assertTrue($this->fake->hasCall('ensureHotspotProfileDeviceCap'));

        $call = $this->fake->callsNamed('ensureHotspotProfileDeviceCap')[0][1];
        $this->assertSame('hsprof-cap', $call['profile']);
        $this->assertSame(3, $call['sharedUsers']);
    }

    public function test_a_one_device_cap_is_still_applied(): void
    {
        $subscription = $this->makeHotspotSubscription(['shared_users' => 1]);

        $subscription->provisionOnRouter();

        $call = $this->fake->callsNamed('ensureHotspotProfileDeviceCap')[0][1] ?? null;
        $this->assertNotNull($call);
        $this->assertSame(1, $call['sharedUsers']);

        // The rest of the hotspot provisioning still runs.
        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertTrue($this->fake->hasCall('ensureAutoReconnectProfile'));
        $this->assertTrue($this->fake->hasCall('ensureHotspotServerAuth'));
    }
}
