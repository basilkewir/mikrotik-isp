<?php

namespace App\Services\Mikrotik;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\Router;
use Exception;
use Illuminate\Support\Facades\Cache;
use Throwable;

class ConnectedDeviceService
{
    /**
     * RouterOS ARP states that mean the address answered recently or is being verified.
     * `permanent` is excluded: static entries are configuration, not presence.
     */
    private const LIVE_ARP_STATES = ['reachable', 'delay', 'probe'];

    /** ARP states that are silent enough to be worth an active ping (when no DHCP lease exists). */
    private const PING_STATES = ['stale', 'failed', 'incomplete'];

    private const PING_TTL = 60;

    private const PING_BUDGET = 10;

    /**
     * Live connected-device snapshot with a short cache and single-flight
     * lock — page loads must never block on a slow RouterOS login (see
     * RouterDataService::snapshot for the same pattern).
     */
    public function snapshot(Router $router, int $ttl = 30, bool $fresh = false): array
    {
        $cacheKey = "mikrotik:devices:{$router->id}";
        $lastKey = "{$cacheKey}:last";

        if (!$fresh && ($cached = cache()->get($cacheKey))) {
            return $cached;
        }

        $lock = Cache::lock("{$cacheKey}:lock", 90);

        if (!$lock->get()) {
            for ($i = 0; $i < 50; $i++) {
                usleep(500_000);
                if ($cached = cache()->get($cacheKey)) {
                    return $cached;
                }
            }

            if ($last = cache()->get($lastKey)) {
                return $last;
            }

            throw new Exception("A live device scan of {$router->name} is already running — please retry in a moment.");
        }

        try {
            $devices = $this->collect($router);

            cache()->put($cacheKey, $devices, $ttl);
            cache()->put($lastKey, $devices, 3600);

            return $devices;
        } catch (Throwable $exception) {
            if ($last = cache()->get($lastKey)) {
                return $last;
            }

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    /**
     * Drop the live snapshot so the very next page render reflects a
     * router-side change immediately — otherwise a saved comment (or any
     * other edit) stays invisible for up to 30s and looks like it failed.
     */
    public function forgetSnapshot(Router $router): void
    {
        cache()->forget("mikrotik:devices:{$router->id}");
        cache()->forget("mikrotik:devices:{$router->id}:last");
    }

    /**
     * Fetch and merge every connected-device source of a router into one
     * unified record per MAC/IP address.
     */
    private function collect(Router $router): array
    {
        $service = $router->service();

        try {
            $source = $service->getConnectedDeviceSources();
            $pings = $this->probeSilentHosts($service, $source['rows'], $router);
        } finally {
            $service->disconnect();
        }

        $rows = $source['rows'];
        $devices = [];

        // ARP table: authoritative L2 presence (no comments — RouterOS ARP
        // entries don't have a comment property).
        foreach ($rows['arp'] as $arp) {
            $mac = strtoupper((string) ($arp['mac-address'] ?? ''));
            $ip = (string) ($arp['address'] ?? '');

            if ($mac === '' && $ip === '') {
                continue;
            }

            $key = $this->resolveKey($devices, $mac, $ip, $router);
            $devices[$key]['sources'][] = 'arp';
            $devices[$key]['interface'] = $devices[$key]['interface'] ?: ($arp['interface'] ?? null);
            $devices[$key]['arp_status'] = $arp['status'] ?? null;
            $devices[$key]['arp_complete'] = ($arp['complete'] ?? 'false') === 'true';
            $devices[$key]['raw']['arp'] = $arp;
        }

        // DHCP leases: hostnames, lease state, per-lease rate limits.
        foreach ($rows['dhcp'] as $lease) {
            $mac = strtoupper((string) (($lease['active-mac-address'] ?? '') ?: ($lease['mac-address'] ?? '')));
            $ip = (string) (($lease['active-address'] ?? '') ?: ($lease['address'] ?? ''));

            if ($mac === '' && $ip === '') {
                continue;
            }

            $key = $this->resolveKey($devices, $mac, $ip, $router);
            $devices[$key]['ip_address'] = $devices[$key]['ip_address'] ?: ($ip ?: null);
            $devices[$key]['mac_address'] = $devices[$key]['mac_address'] ?: ($mac ?: null);
            $devices[$key]['hostname'] = $devices[$key]['hostname'] ?: ($lease['host-name'] ?? null);
            $devices[$key]['dhcp_status'] = $lease['status'] ?? null;
            $devices[$key]['dhcp_expires'] = $lease['expires-after'] ?? null;
            $devices[$key]['dhcp_last_seen'] = $lease['last-seen'] ?? null;
            $devices[$key]['dhcp_rate_limit'] = $lease['rate-limit'] ?? null;
            $devices[$key]['dhcp_server'] = $lease['server'] ?? null;
            $devices[$key]['comment'] = $devices[$key]['comment']
                ?: MikrotikService::displayComment($lease['comment'] ?? null);
            $devices[$key]['blocked'] = ($lease['blocked'] ?? 'false') === 'true';
            $devices[$key]['dhcp_disabled'] = ($lease['disabled'] ?? 'false') === 'true';
            $devices[$key]['class_id'] = $lease['class-id'] ?? null;
            $devices[$key]['sources'][] = 'dhcp';
            $devices[$key]['raw']['dhcp'] = $lease;
        }

        // Hotspot host table: authorised/bypassed state of captive-portal clients.
        foreach ($rows['hotspot_hosts'] as $host) {
            $mac = strtoupper((string) ($host['mac-address'] ?? ''));
            $ip = (string) ($host['address'] ?? '');

            if ($mac === '' && $ip === '') {
                continue;
            }

            $key = $this->resolveKey($devices, $mac, $ip, $router);
            $devices[$key]['interface'] = $devices[$key]['interface'] ?: ($host['server'] ?? null);
            $devices[$key]['host_authorized'] = ($host['authorized'] ?? 'false') === 'true';
            $devices[$key]['host_bypassed'] = ($host['bypassed'] ?? 'false') === 'true';
            $devices[$key]['host_uptime'] = $host['uptime'] ?? null;
            $devices[$key]['host_found_by'] = $host['found-by'] ?? null;
            $devices[$key]['sources'][] = 'hotspot_host';
            $devices[$key]['raw']['hotspot_host'] = $host;
        }

        // Hotspot active sessions: logged-in users, traffic counters.
        foreach ($rows['hotspot_active'] as $session) {
            $mac = strtoupper((string) ($session['mac-address'] ?? ''));
            $ip = (string) ($session['address'] ?? '');

            if ($mac === '' && $ip === '') {
                continue;
            }

            $key = $this->resolveKey($devices, $mac, $ip, $router);
            $devices[$key]['user'] = $session['user'] ?? null;
            $devices[$key]['session_uptime'] = $session['uptime'] ?? null;
            $devices[$key]['session_time_left'] = $session['session-time-left'] ?? null;
            $devices[$key]['idle_time'] = $session['idle-time'] ?? null;
            $devices[$key]['login_by'] = $session['login-by'] ?? null;
            $devices[$key]['bytes_in'] = (int) ($session['bytes-in'] ?? 0);
            $devices[$key]['bytes_out'] = (int) ($session['bytes-out'] ?? 0);
            $devices[$key]['packets_in'] = (int) ($session['packets-in'] ?? 0);
            $devices[$key]['packets_out'] = (int) ($session['packets-out'] ?? 0);
            $devices[$key]['comment'] = $devices[$key]['comment']
                ?: MikrotikService::displayComment($session['comment'] ?? null);
            $devices[$key]['sources'][] = 'hotspot';
            $devices[$key]['raw']['hotspot'] = $session;
        }

        // PPPoE active sessions.
        foreach ($rows['ppp_active'] as $session) {
            $ip = (string) ($session['address'] ?? '');
            $mac = strtoupper((string) ($session['caller-id'] ?? ''));
            $key = $mac !== '' || $ip !== ''
                ? $this->resolveKey($devices, $mac, $ip, $router)
                : $this->createKey($devices, 'ppp:' . ($session['name'] ?? ''), $mac, $ip, $router);

            $devices[$key]['user'] = $session['name'] ?? null;
            $devices[$key]['session_uptime'] = $session['uptime'] ?? null;
            $devices[$key]['bytes_in'] = (int) ($session['bytes-in'] ?? 0);
            $devices[$key]['bytes_out'] = (int) ($session['bytes-out'] ?? 0);
            $devices[$key]['packets_in'] = (int) ($session['packets-in'] ?? 0);
            $devices[$key]['packets_out'] = (int) ($session['packets-out'] ?? 0);
            $devices[$key]['ppp_service'] = $session['service'] ?? null;
            $devices[$key]['ppp_remote_address'] = $session['remote-address'] ?? null;
            $devices[$key]['sources'][] = 'pppoe';
            $devices[$key]['raw']['pppoe'] = $session;
        }

        // Simple queues are the RouterOS traffic source for static CPEs.
        // Match an existing device by target IP; queue rows must not create
        // phantom devices in the connected-device table.
        foreach ($rows['simple_queues'] ?? [] as $queue) {
            $target = trim((string) ($queue['target'] ?? ''));
            $ip = preg_replace('/\/32$/', '', $target);
            if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
                continue;
            }

            foreach ($devices as $key => &$device) {
                if (($device['ip_address'] ?? null) !== $ip) {
                    continue;
                }

                [$bytesIn, $bytesOut] = array_pad(
                    array_map('intval', explode('/', (string) ($queue['bytes'] ?? '0/0'))),
                    2,
                    0
                );
                $device['bytes_in'] = $bytesIn;
                $device['bytes_out'] = $bytesOut;
                $device['traffic_source'] = 'simple_queue';
                $device['queue_max_limit'] = ($queue['max-limit'] ?? '') !== '' ? $queue['max-limit'] : null;
                $device['raw']['simple_queue'] = $queue;
                $device['sources'][] = 'queue';
                break;
            }
            unset($device);
        }

        // LLDP/CDP neighbors: network gear discovered on the LAN.
        foreach ($rows['neighbors'] as $neighbor) {
            $mac = strtoupper((string) ($neighbor['mac-address'] ?? ''));
            $ip = (string) ($neighbor['address'] ?? '');

            if ($mac === '' && $ip === '') {
                continue;
            }

            $key = $this->resolveKey($devices, $mac, $ip, $router);
            $devices[$key]['neighbor_identity'] = $neighbor['identity'] ?? null;
            $devices[$key]['neighbor_platform'] = $neighbor['platform'] ?? null;
            $devices[$key]['neighbor_interface'] = $neighbor['interface-name'] ?? null;
            $devices[$key]['sources'][] = 'neighbor';
            $devices[$key]['raw']['neighbor'] = $neighbor;
        }

        // Link against the device inventory (by MAC on this router) and
        // attach a typology classification to every record.
        $managedByMac = Device::with('client:id,name')
            ->where('router_id', $router->id)
            ->whereIn('mac_address', array_values(array_filter(array_column($devices, 'mac_address'))))
            ->get()
            ->keyBy(fn (Device $device): string => strtoupper((string) $device->mac_address));

        foreach ($devices as &$device) {
            $device['ping_reachable'] = $device['ip_address'] !== null
                && ($pings[$device['ip_address']] ?? false) === true;
            if ($device['ping_reachable']) {
                $device['sources'][] = 'ping';
            }
            $device['sources'] = array_values(array_unique($device['sources']));
            $device['online'] = $this->isOnline($device);
            // idle = holds a valid DHCP lease but is not currently seen; offline = neither.
            $device['status'] = $device['online']
                ? 'online'
                : (($device['dhcp_status'] ?? null) === 'bound' ? 'idle' : 'offline');

            $managed = $device['mac_address']
                ? ($managedByMac[strtoupper($device['mac_address'])] ?? null)
                : null;

            $device['managed_device_id'] = $managed?->id;
            $device['managed_client_id'] = $managed?->client_id;
            $device['client_name'] = $managed?->client?->name;
            $device['access_duration_days'] = $managed?->access_duration_days;
            $device['access_expires_at'] = $managed?->access_expires_at?->toIso8601String();
            $device['access_is_active'] = $managed?->is_active;
            $device['access_expired'] = $managed ? $managed->isAccessExpired() : null;
            $device['typology'] = $managed?->type ?? $this->heuristicType($device);

            $type = DeviceType::tryFrom($device['typology']);
            $device['typology_label'] = $type?->label() ?? DeviceType::Unknown->label();
            $device['typology_color'] = $type?->color() ?? DeviceType::Unknown->color();
        }
        unset($device);

        usort($devices, function (array $a, array $b): int {
            if ($a['online'] !== $b['online']) {
                return $a['online'] ? -1 : 1;
            }

            return [$a['router_name'], $a['ip_address'], $a['mac_address']]
                <=> [$b['router_name'], $b['ip_address'], $b['mac_address']];
        });

        $this->applyRates($router, $devices);

        return $devices;
    }

    /**
     * Ping ARP-silent addresses that have no DHCP lease (typically static-IP
     * devices such as client bridges). Returns ip => reachable for this poll.
     *
     * Results are cached per IP for PING_TTL seconds and at most PING_BUDGET
     * pings run per poll, so the live page never waits on a long sweep.
     */
    private function probeSilentHosts(MikrotikService $service, array $rows, Router $router): array
    {
        $leased = [];
        foreach ($rows['dhcp'] ?? [] as $lease) {
            foreach (['address', 'active-address'] as $field) {
                if (!empty($lease[$field])) {
                    $leased[(string) $lease[$field]] = true;
                }
            }
        }

        $results = [];
        $budget = self::PING_BUDGET;

        foreach ($rows['arp'] ?? [] as $arp) {
            $ip = (string) ($arp['address'] ?? '');

            if ($ip === '' || isset($leased[$ip]) || !in_array($arp['status'] ?? null, self::PING_STATES, true)) {
                continue;
            }

            $cacheKey = "mikrotik:ping:{$router->id}:{$ip}";
            if (($cached = cache()->get($cacheKey)) !== null) {
                $results[$ip] = $cached;
                continue;
            }

            if ($budget-- <= 0) {
                continue;
            }

            $results[$ip] = $service->pingReachable($ip);
            cache()->put($cacheKey, $results[$ip], self::PING_TTL);
        }

        return $results;
    }

    /**
     * Real-time bandwidth: difference between this sample's byte counters and
     * the previous sample (stored per router), divided by the elapsed time.
     * The live page polls every few seconds, so each poll is one sample.
     *
     * Devices with no byte counter source (no simple queue, hotspot session or
     * PPP session) get null rates and counter_available=false.
     */
    private function applyRates(Router $router, array &$devices): void
    {
        $stateKey = "mikrotik:devices:{$router->id}:rates";
        $now = microtime(true);
        $previous = cache()->get($stateKey, ['at' => null, 'counters' => []]);
        $elapsed = $previous['at'] !== null ? $now - $previous['at'] : 0.0;
        $counters = [];

        foreach ($devices as &$device) {
            $id = $device['mac_address'] ?: $device['ip_address'] ?: $device['key'];
            $counterAvailable = $device['traffic_source'] !== null
                || in_array('hotspot', $device['sources'], true)
                || in_array('pppoe', $device['sources'], true);

            $device['counter_available'] = $counterAvailable;
            $device['rate_in'] = null;
            $device['rate_out'] = null;
            $counters[$id] = [(int) $device['bytes_in'], (int) $device['bytes_out']];

            $prev = $previous['counters'][$id] ?? null;

            // Skip: no counter, first sample, no time elapsed, or counter reset (router reboot / session restart).
            if (!$counterAvailable || $prev === null || $elapsed <= 0.0) {
                continue;
            }
            if ($device['bytes_in'] < $prev[0] || $device['bytes_out'] < $prev[1]) {
                continue;
            }

            $device['rate_in'] = (int) round(($device['bytes_in'] - $prev[0]) / $elapsed);
            $device['rate_out'] = (int) round(($device['bytes_out'] - $prev[1]) / $elapsed);
        }
        unset($device);

        cache()->put($stateKey, ['at' => $now, 'counters' => $counters], 3600);
    }

    /**
     * Find the record matching this MAC or IP, creating it when new.
     */
    private function resolveKey(array &$devices, string $mac, string $ip, Router $router): string
    {
        foreach ($devices as $key => $device) {
            $sameMac = $mac !== '' && strtoupper((string) ($device['mac_address'] ?? '')) === $mac;
            $sameIp = $ip !== '' && (string) ($device['ip_address'] ?? '') === $ip;

            if ($sameMac || $sameIp) {
                return $key;
            }
        }

        $identity = $mac !== '' ? strtoupper($mac) : 'ip:' . $ip;

        return $this->createKey($devices, $identity, $mac, $ip, $router);
    }

    private function createKey(array &$devices, string $identity, string $mac, string $ip, Router $router): string
    {
        $key = $identity;
        $suffix = 1;

        while (isset($devices[$key])) {
            $key = $identity . '#' . $suffix++;
        }

        $devices[$key] = [
            'key' => $key,
            'router_id' => $router?->id,
            'router_name' => $router?->name,
            'mac_address' => $mac ?: null,
            'ip_address' => $ip ?: null,
            'hostname' => null,
            'interface' => null,
            'comment' => null,
            'user' => null,
            'sources' => [],
            'session_uptime' => null,
            'session_time_left' => null,
            'idle_time' => null,
            'login_by' => null,
            'bytes_in' => 0,
            'bytes_out' => 0,
            'packets_in' => 0,
            'packets_out' => 0,
            'traffic_source' => null,
            'queue_max_limit' => null,
            'ping_reachable' => false,
            'arp_status' => null,
            'arp_complete' => false,
            'dhcp_status' => null,
            'dhcp_expires' => null,
            'dhcp_last_seen' => null,
            'dhcp_rate_limit' => null,
            'dhcp_server' => null,
            'dhcp_disabled' => false,
            'blocked' => false,
            'class_id' => null,
            'host_authorized' => null,
            'host_bypassed' => null,
            'host_uptime' => null,
            'host_found_by' => null,
            'ppp_service' => null,
            'ppp_remote_address' => null,
            'neighbor_identity' => null,
            'neighbor_platform' => null,
            'neighbor_interface' => null,
            'managed_device_id' => null,
            'managed_client_id' => null,
            'client_name' => null,
            'access_duration_days' => null,
            'access_expires_at' => null,
            'access_is_active' => null,
            'access_expired' => null,
            'typology' => 'unknown',
            'typology_label' => 'Unknown / Guest',
            'typology_color' => 'zinc',
            'online' => false,
            'status' => 'offline',
            'raw' => [],
        ];

        return $key;
    }

    /**
     * Best-effort typology guess for devices not present in the inventory.
     */
    private function heuristicType(array $device): string
    {
        if (in_array('hotspot', $device['sources'], true)) {
            return DeviceType::HotspotClient->value;
        }

        if (in_array('pppoe', $device['sources'], true)) {
            return DeviceType::Cpe->value;
        }

        if (in_array('neighbor', $device['sources'], true)) {
            return DeviceType::AccessPoint->value;
        }

        $reachable = ($device['dhcp_status'] ?? null) === 'bound' || ($device['arp_complete'] ?? false);

        return $reachable ? DeviceType::Cpe->value : DeviceType::Unknown->value;
    }

    /**
     * Online = proof the device is on the wire right now:
     *  - a live hotspot or PPPoE session, or
     *  - an ARP entry in a live state. RouterOS keeps `complete=true` on
     *    `stale` entries, so the complete flag alone is not evidence, or
     *  - a router ping reply (static-IP devices with no DHCP lease).
     *
     * A DHCP lease alone is not proof (leases outlive power-off), so it is
     * handled as "idle" in the status, never as online.
     */
    private function isOnline(array $device): bool
    {
        if (in_array('hotspot', $device['sources'], true) || in_array('pppoe', $device['sources'], true)) {
            return true;
        }

        // Static-IP devices have no lease; a successful router ping is direct proof.
        if ($device['ping_reachable'] ?? false) {
            return true;
        }

        return in_array($device['arp_status'] ?? null, self::LIVE_ARP_STATES, true);
    }
}
