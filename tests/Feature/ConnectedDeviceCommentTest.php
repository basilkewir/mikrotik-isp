<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Device;
use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Services\Mikrotik\ConnectedDeviceService;
use App\Services\Mikrotik\MikrotikService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

class ConnectedDeviceCommentTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;
    private Router $router;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);

        $this->router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.0.2.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $client = Client::create([
            'client_code' => 'CLI-CMT-1',
            'name' => 'Test Customer',
        ]);

        $this->device = Device::create([
            'client_id' => $client->id,
            'router_id' => $this->router->id,
            'mac_address' => 'AA:BB:CC:DD:EE:FF',
            'ip_address' => '192.168.1.10',
            'type' => 'cpe',
            'comment' => 'Old note',
        ]);
    }

    public function test_comment_is_written_to_the_router_and_mirrored_to_the_inventory(): void
    {
        cache()->put("mikrotik:devices:{$this->router->id}", [['key' => 'stale-row']], 60);

        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => 'aa:bb:cc:dd:ee:ff',
            'comment' => 'Security camera',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $call = $this->fake->callsNamed('setDeviceComment')[0][1];
        $this->assertSame('AA:BB:CC:DD:EE:FF', $call['macAddress']);
        $this->assertSame('Security camera', $call['comment']);

        $this->assertSame('Security camera', $this->device->fresh()->comment);

        // The 30s snapshot must be flushed or the new comment stays invisible.
        $this->assertFalse(cache()->has("mikrotik:devices:{$this->router->id}"));
        $this->assertFalse(cache()->has("mikrotik:devices:{$this->router->id}:last"));

        $this->assertTrue(
            RouterActivityLog::where('router_id', $this->router->id)
                ->where('event', 'device_comment_update')
                ->exists()
        );
    }

    public function test_clearing_the_comment_clears_the_inventory_copy(): void
    {
        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => $this->device->mac_address,
            'comment' => '  ',
        ]);

        $response->assertSessionHas('success');
        $this->assertNull($this->device->fresh()->comment);
    }

    public function test_block_button_isolates_both_ip_and_mac(): void
    {
        $response = $this->post(route('connected-devices.block'), [
            'router_id' => $this->router->id,
            'ip_address' => $this->device->ip_address,
            'mac_address' => $this->device->mac_address,
        ]);

        $response->assertSessionHas('success');
        $this->assertTrue($this->fake->hasCall('isolateIp'));
        $this->assertTrue($this->fake->hasCall('isolateMac'));
        $this->assertSame(
            'AA:BB:CC:DD:EE:FF',
            $this->fake->callsNamed('isolateMac')[0][1]['mac']
        );
    }

    public function test_live_snapshot_bypasses_cached_traffic_values(): void
    {
        $service = app(ConnectedDeviceService::class);
        $service->snapshot($this->router);

        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [],
                'dhcp' => [],
                'hotspot_active' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $this->device->ip_address,
                    'user' => 'customer',
                    'bytes-in' => '1048576',
                    'bytes-out' => '2097152',
                ]],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $devices = $service->snapshot($this->router, 30, true);

        $this->assertCount(2, $this->fake->callsNamed('getConnectedDeviceSources'));
        $this->assertSame(1048576, $devices[0]['bytes_in']);
        $this->assertSame(2097152, $devices[0]['bytes_out']);
    }

    public function test_static_cpe_traffic_comes_from_its_simple_queue(): void
    {
        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $this->device->ip_address,
                    'complete' => 'true',
                ]],
                'dhcp' => [],
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [[
                    'name' => 'ISP-CPE-1',
                    'target' => $this->device->ip_address . '/32',
                    'bytes' => '3145728/4194304',
                ]],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $devices = app(ConnectedDeviceService::class)->snapshot($this->router, 30, true);

        $this->assertSame(3145728, $devices[0]['bytes_in']);
        $this->assertSame(4194304, $devices[0]['bytes_out']);
        $this->assertContains('queue', $devices[0]['sources']);
    }

    public function test_simple_queue_speed_cap_is_exposed_on_the_device(): void
    {
        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $this->device->ip_address,
                    'complete' => 'true',
                ]],
                'dhcp' => [],
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [[
                    'name' => 'ISP-CPE-1',
                    'target' => $this->device->ip_address . '/32',
                    'max-limit' => '5M/10M',
                    'bytes' => '0/0',
                ]],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $devices = app(ConnectedDeviceService::class)->snapshot($this->router, 30, true);

        $this->assertSame('5M/10M', $devices[0]['queue_max_limit']);
    }

    public function test_real_time_rates_are_computed_from_counter_deltas_between_samples(): void
    {
        $service = app(ConnectedDeviceService::class);
        $queue = fn (string $in, string $out) => [
            'arp' => [[
                'mac-address' => $this->device->mac_address,
                'address' => $this->device->ip_address,
                'complete' => 'true',
            ]],
            'dhcp' => [],
            'hotspot_active' => [],
            'hotspot_hosts' => [],
            'ppp_active' => [],
            'simple_queues' => [[
                'name' => 'ISP-CPE-1',
                'target' => $this->device->ip_address . '/32',
                'max-limit' => '0/0',
                'bytes' => "{$in}/{$out}",
            ]],
            'neighbors' => [],
        ];

        $this->fake->deviceSources = ['rows' => $queue('1000', '2000'), 'errors' => []];
        $first = $service->snapshot($this->router, 30, true);

        // First sample has nothing to compare against.
        $this->assertNull($first[0]['rate_in']);
        $this->assertTrue($first[0]['counter_available']);

        usleep(200_000);
        $this->fake->deviceSources = ['rows' => $queue('501000', '802000'), 'errors' => []];
        $second = $service->snapshot($this->router, 30, true);

        $this->assertGreaterThan(0, $second[0]['rate_in']);
        $this->assertGreaterThan(0, $second[0]['rate_out']);
        // Out delta (800 KB) is larger than in delta (500 KB) over the same interval.
        $this->assertGreaterThan($second[0]['rate_in'], $second[0]['rate_out']);
    }

    public function test_status_requires_live_evidence_not_a_stale_arp_or_lease(): void
    {
        $rows = fn (array $arp, array $dhcp = []) => [
            'rows' => [
                'arp' => $arp,
                'dhcp' => $dhcp,
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];
        $arpEntry = fn (string $status, string $complete) => [[
            'mac-address' => $this->device->mac_address,
            'address' => $this->device->ip_address,
            'status' => $status,
            'complete' => $complete,
        ]];
        $service = app(ConnectedDeviceService::class);

        // stale keeps complete=true in RouterOS: must not count as online.
        $this->fake->deviceSources = $rows($arpEntry('stale', 'true'));
        $this->assertSame('offline', $service->snapshot($this->router, 30, true)[0]['status']);

        $this->fake->deviceSources = $rows($arpEntry('reachable', 'true'));
        $this->assertSame('online', $service->snapshot($this->router, 30, true)[0]['status']);

        // A valid DHCP lease without any live ARP entry is idle, never online.
        $this->fake->deviceSources = $rows([], [[
            'mac-address' => $this->device->mac_address,
            'address' => $this->device->ip_address,
            'status' => 'bound',
            'last-seen' => '1h28m36s',
        ]]);
        $device = $service->snapshot($this->router, 30, true)[0];
        $this->assertFalse($device['online']);
        $this->assertSame('idle', $device['status']);
    }

    public function test_static_ip_device_is_online_when_it_answers_a_router_ping(): void
    {
        $ip = $this->device->ip_address;
        cache()->forget("mikrotik:ping:{$this->router->id}:{$ip}");

        $staticArp = fn (string $status) => [
            'rows' => [
                'arp' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $ip,
                    'status' => $status,
                    'complete' => 'true',
                ]],
                'dhcp' => [], // static IP: no lease
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];
        $service = app(ConnectedDeviceService::class);

        // Silent ARP + no reply: offline, and the ping was attempted.
        $this->fake->deviceSources = $staticArp('stale');
        $this->fake->pingResults = [$ip => false];
        $device = $service->snapshot($this->router, 30, true)[0];
        $this->assertSame('offline', $device['status']);
        $this->assertTrue($this->fake->hasCall('pingReachable'));

        // Same silent ARP, but the device answers the ping: online.
        cache()->forget("mikrotik:ping:{$this->router->id}:{$ip}");
        $this->fake->calls = [];
        $this->fake->pingResults = [$ip => true];
        $device = $service->snapshot($this->router, 30, true)[0];
        $this->assertSame('online', $device['status']);
        $this->assertContains('ping', $device['sources']);
    }

    public function test_router_ping_is_cached_so_the_live_page_does_not_ping_every_poll(): void
    {
        $ip = $this->device->ip_address;
        cache()->forget("mikrotik:ping:{$this->router->id}:{$ip}");

        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $ip,
                    'status' => 'stale',
                ]],
                'dhcp' => [],
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];
        $this->fake->pingResults = [$ip => true];
        $service = app(ConnectedDeviceService::class);

        $service->snapshot($this->router, 30, true);
        $service->snapshot($this->router, 30, true);

        $this->assertCount(1, $this->fake->callsNamed('pingReachable'));
    }

    public function test_device_without_a_simple_queue_has_no_speed_cap(): void
    {
        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $this->device->ip_address,
                    'complete' => 'true',
                ]],
                'dhcp' => [],
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $devices = app(ConnectedDeviceService::class)->snapshot($this->router, 30, true);

        $this->assertNull($devices[0]['queue_max_limit']);
    }

    public function test_live_endpoint_returns_current_traffic_counters_as_json(): void
    {
        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [],
                'dhcp' => [],
                'hotspot_active' => [[
                    'mac-address' => $this->device->mac_address,
                    'address' => $this->device->ip_address,
                    'user' => 'customer',
                    'bytes-in' => '4096',
                    'bytes-out' => '8192',
                ]],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $response = $this->getJson(route('connected-devices.live'));

        $response->assertOk()
            ->assertJsonPath('devices.0.bytes_in', 4096)
            ->assertJsonPath('devices.0.bytes_out', 8192);
    }

    public function test_comment_without_a_mac_or_ip_is_rejected_before_touching_the_router(): void
    {
        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'comment' => 'Nowhere to attach this',
        ]);

        $response->assertSessionHas('error');
        $this->assertFalse($this->fake->hasCall('setDeviceComment'));
        $this->assertSame('Old note', $this->device->fresh()->comment);
    }

    public function test_router_failure_is_surfaced_as_an_error_flash(): void
    {
        $this->fake->failWith = new RuntimeException('RouterOS down');

        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => $this->device->mac_address,
            'comment' => 'Security camera',
        ]);

        $response->assertSessionHas('error', fn (string $message) => str_contains($message, 'RouterOS down'));
        $this->assertSame('Old note', $this->device->fresh()->comment);
        $this->assertFalse(RouterActivityLog::where('event', 'device_comment_update')->exists());
    }

    public function test_lease_rejection_is_reported_as_a_warning(): void
    {
        $this->fake->commentResult = [
            'updated' => ['dhcp_lease'],
            'absent' => [],
            'errors' => ['dhcp_lease' => 'cannot change dynamic item'],
        ];

        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => $this->device->mac_address,
            'comment' => 'Security camera',
        ]);

        $response->assertSessionHas('warning', fn (string $message) => str_contains($message, 'dhcp_lease'));
        $this->assertSame('Security camera', $this->device->fresh()->comment);
        $this->assertTrue(RouterActivityLog::where('event', 'device_comment_update')->exists());
    }

    public function test_missing_lease_produces_a_warning(): void
    {
        $this->fake->commentResult = [
            'updated' => [],
            'absent' => ['dhcp_lease'],
            'errors' => [],
        ];

        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => $this->device->mac_address,
            'comment' => 'Security camera',
        ]);

        $response->assertSessionHas('warning', fn (string $message) => str_contains($message, 'No DHCP lease found'));
        $this->assertFalse(RouterActivityLog::where('event', 'device_comment_update')->exists());
    }

    public function test_lease_write_refusal_is_reported_as_an_error(): void
    {
        $this->fake->commentResult = [
            'updated' => [],
            'absent' => [],
            'errors' => ['dhcp_lease' => 'cannot change dynamic item'],
        ];

        $response = $this->post(route('connected-devices.comment'), [
            'router_id' => $this->router->id,
            'mac_address' => $this->device->mac_address,
            'comment' => 'Security camera',
        ]);

        $response->assertSessionHas('error', fn (string $message) => str_contains($message, 'cannot change dynamic item'));
        $this->assertFalse(RouterActivityLog::where('event', 'device_comment_update')->exists());
    }

    public function test_router_comments_are_merged_and_ownership_tags_are_hidden(): void
    {
        $this->fake->deviceSources = [
            'rows' => [
                'arp' => [
                    [
                        'mac-address' => 'AA:BB:CC:DD:EE:FF',
                        'address' => '192.168.1.10',
                        'interface' => 'bridge-lan',
                        'status' => 'complete',
                        'complete' => 'true',
                    ],
                    [
                        'mac-address' => '99:88:77:66:55:44',
                        'address' => '192.168.1.40',
                    ],
                ],
                'dhcp' => [
                    [
                        'mac-address' => 'AA:BB:CC:DD:EE:FF',
                        'address' => '192.168.1.10',
                        'host-name' => 'gateway',
                        'comment' => 'device:7|client:3|AA:BB:CC:DD:EE:FF — Front camera',
                    ],
                ],
                'hotspot_active' => [
                    [
                        'mac-address' => '99:88:77:66:55:44',
                        'address' => '192.168.1.40',
                        'user' => 'cust1',
                        'comment' => 'Client: Test Customer [Sub #1]',
                    ],
                ],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];

        $devices = collect(app(ConnectedDeviceService::class)->snapshot($this->router))
            ->keyBy('mac_address');

        // The platform's ownership tag never reaches the operator.
        $this->assertSame('Front camera', $devices['AA:BB:CC:DD:EE:FF']['comment']);

        // Session comments (mirrored from the hotspot user) still surface.
        $this->assertSame('Client: Test Customer [Sub #1]', $devices['99:88:77:66:55:44']['comment']);
    }
}
