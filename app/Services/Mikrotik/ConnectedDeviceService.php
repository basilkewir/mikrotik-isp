<?php

namespace App\Services\Mikrotik;

use App\Enums\DeviceType;
use App\Models\Device;
use App\Models\Router;

class ConnectedDeviceService
{
    /**
     * Fetch and merge every connected-device source of a router into one
     * unified record per MAC/IP address.
     */
    public function snapshot(Router $router): array
    {
        $service = $router->service();

        try {
            $source = $service->getConnectedDeviceSources();
        } finally {
            $service->disconnect();
        }

        $rows = $source['rows'];
        $devices = [];

        // ARP table: authoritative L2 presence.
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
            $devices[$key]['comment'] = $devices[$key]['comment'] ?: ($lease['comment'] ?? null);
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
            $devices[$key]['comment'] = $devices[$key]['comment'] ?: ($session['comment'] ?? null);
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
            $device['sources'] = array_values(array_unique($device['sources']));
            $device['online'] = $this->isOnline($device);
            $device['status'] = $device['online']
                ? 'online'
                : (($device['session_uptime'] ?? null) || ($device['dhcp_last_seen'] ?? null) ? 'idle' : 'offline');

            $managed = $device['mac_address']
                ? ($managedByMac[strtoupper($device['mac_address'])] ?? null)
                : null;

            $device['managed_device_id'] = $managed?->id;
            $device['managed_client_id'] = $managed?->client_id;
            $device['client_name'] = $managed?->client?->name;
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

        return $devices;
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

    private function isOnline(array $device): bool
    {
        if (in_array('hotspot', $device['sources'], true) || in_array('pppoe', $device['sources'], true)) {
            return true;
        }

        if (in_array('arp', $device['sources'], true)) {
            return $device['arp_complete'];
        }

        return ($device['dhcp_status'] ?? null) === 'bound';
    }
}
