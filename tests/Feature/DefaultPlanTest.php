<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

class DefaultPlanTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;
    private Router $router;
    private Client $client;

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

        Carbon::setTestNow(Carbon::parse('2026-11-04 12:00:00', config('app.timezone')));

        $this->router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.0.2.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $this->client = Client::create(['client_code' => 'CLI-DEF-1', 'name' => 'Default Customer']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(string $code, ?int $validityDays): Plan
    {
        return Plan::create([
            'name' => $code,
            'code' => $code,
            'validity_days' => $validityDays,
            'price' => 0,
            'shared_users' => 1,
            'hotspot_profile' => 'default',
            'is_active' => true,
        ]);
    }

    public function test_new_subscription_without_a_plan_uses_the_default_30_day_plan(): void
    {
        $default = $this->plan('DEF30', 30);
        $default->makeDefault();
        $this->plan('OTHER7', 7);

        $this->post(route('subscriptions.store'), [
            'client_id' => $this->client->id,
            'router_id' => $this->router->id,
            'auth_type' => 'hotspot',
            'username' => 'newcust',
            'password' => 'secret-pw',
        ])->assertSessionHas('success');

        $subscription = Subscription::where('username', 'newcust')->firstOrFail();
        $this->assertSame($default->id, $subscription->plan_id);
        // 30 calendar days, day 1 = Nov 4 -> through end of Dec 3.
        $this->assertSame('2026-12-03', $subscription->expires_at->format('Y-m-d'));
    }

    public function test_only_one_plan_is_the_default_at_a_time(): void
    {
        $first = $this->plan('FIRST30', 30);
        $second = $this->plan('SECOND30', 30);

        $first->makeDefault();
        $second->makeDefault();

        $this->assertSame(1, Plan::where('is_default', true)->count());
        $this->assertSame($second->id, Plan::defaultPlan()?->id);
    }

    public function test_a_plan_not_valid_for_30_days_cannot_be_the_default(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->plan('UNLIMITED', null)->makeDefault();
    }

    public function test_store_without_a_plan_fails_cleanly_when_no_default_is_set(): void
    {
        $this->plan('NOTDEFAULT', 30);

        $this->post(route('subscriptions.store'), [
            'client_id' => $this->client->id,
            'router_id' => $this->router->id,
            'auth_type' => 'hotspot',
            'username' => 'nodefault',
            'password' => 'secret-pw',
        ])->assertSessionHas('error');

        $this->assertFalse(Subscription::where('username', 'nodefault')->exists());
    }
}
