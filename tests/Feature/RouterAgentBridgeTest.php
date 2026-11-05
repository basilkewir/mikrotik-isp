<?php

namespace Tests\Feature;

use App\Models\Router;
use App\Models\RouterAgentCommand;
use App\Models\RouterSession;
use App\Services\Mikrotik\RouterAgentQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouterAgentBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Router $router;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->router = Router::create([
            'name' => 'Main',
            'ip_address' => '192.168.20.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $this->token = $this->router->ensureAgentToken();
    }

    public function test_a_bad_token_is_rejected(): void
    {
        $this->postJson('/api/router-agent/sync', [], ['Authorization' => 'Bearer wrong'])
            ->assertStatus(401);
    }

    public function test_push_updates_status_and_stores_sessions(): void
    {
        $response = $this->post('/api/router-agent/sync', [
            'identity' => 'FASTLINK',
            'board_name' => 'RB951Ui-2HnD',
            'version' => '7.24.2',
            'uptime' => '15h',
            'cpu_load' => 33,
            'free_memory' => 100,
            'total_memory' => 200,
            'sessions' => "hotspot|vicky|192.168.10.10|AA:BB:CC:DD:EE:FF|1h|500|900\npppoe|dide|192.168.10.11||2h|100|200",
        ], ['Authorization' => 'Bearer ' . $this->token]);

        $response->assertOk();

        $fresh = $this->router->fresh();
        $this->assertSame('FASTLINK', $fresh->identity);
        $this->assertTrue($fresh->is_online);
        $this->assertNotNull($fresh->agent_last_seen_at);

        $this->assertSame(2, RouterSession::where('router_id', $this->router->id)->count());
        $session = RouterSession::where('username', 'vicky')->firstOrFail();
        $this->assertSame(500, $session->bytes_in);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $session->mac_address);
    }

    public function test_stale_sessions_are_dropped_on_the_next_push(): void
    {
        $push = fn (string $sessions) => $this->post('/api/router-agent/sync', [
            'sessions' => $sessions,
        ], ['Authorization' => 'Bearer ' . $this->token]);

        $push("hotspot|a|10.0.0.1|AA:AA:AA:AA:AA:AA|1h|1|1\nhotspot|b|10.0.0.2|BB:BB:BB:BB:BB:BB|1h|1|1");
        $this->assertSame(2, RouterSession::count());

        // b disconnects: only a is reported now.
        $push('hotspot|a|10.0.0.1|AA:AA:AA:AA:AA:AA|2h|5|5');
        $this->assertSame(1, RouterSession::count());
        $this->assertSame('a', RouterSession::first()->username);
    }

    public function test_queued_command_is_delivered_then_confirmed(): void
    {
        app(RouterAgentQueue::class)->disableHotspotUser($this->router, 'vicky');

        // First push: command comes back as a plain line and is marked delivered.
        $first = $this->post('/api/router-agent/sync', [
            'sessions' => '',
        ], ['Authorization' => 'Bearer ' . $this->token]);

        $first->assertOk();
        $this->assertSame('disable_hotspot_user|vicky', trim($first->getContent()));

        $command = RouterAgentCommand::firstOrFail();
        $this->assertSame(RouterAgentCommand::STATUS_DELIVERED, $command->status);

        // Second push with no new commands: nothing returned.
        $second = $this->post('/api/router-agent/sync', [
            'sessions' => '',
        ], ['Authorization' => 'Bearer ' . $this->token]);
        $this->assertSame('', trim($second->getContent()));
    }
}
