<?php

namespace App\Services\Mikrotik;

use App\Models\Device;

/**
 * MAC Address Authorization for devices connected directly to the router
 * (outside the Hotspot user database) — Ethernet CPEs, ONUs, private SSID.
 *
 * Three cooperating control points per device:
 *
 *  1. L2 MAC gate   — /interface/bridge/filter drop rule matched on
 *                     src-mac-address. Instant cut, independent of IP.
 *  2. Allow-list    — /ip/firewall address-list "active-clients". The
 *                     industry-standard allow-list used by ISP billing
 *                     systems; enforced by the optional default-deny
 *                     firewall policy (Router > Firewall Policy).
 *  3. Static lease  — /ip/dhcp-server lease pinning IP to MAC so the
 *                     IP-based allow-list always maps to the customer.
 */
class MacAuthManager
{
    public const ALLOW_LIST = MikrotikService::ALLOW_LIST;

    public function __construct(private readonly MikrotikService $service)
    {
    }

    /**
     * Grant internet access to a direct/LAN device.
     *
     * @return array audit trail of every RouterOS action performed
     */
    public function activate(Device $device): array
    {
        $actions = [];

        if ($device->ip_address) {
            $actions['address_list'] = $this->service->addAllowListEntry(
                $device->ip_address,
                $this->comment($device)
            );

            $actions['dhcp_lease'] = $this->service->ensureStaticLease(
                $device->mac_address,
                $device->ip_address,
                $this->comment($device)
            );
            $actions['traffic_queue'] = $this->service->ensureTrafficQueue(
                'ISP-CPE-' . $device->id,
                $device->ip_address
            );
        } else {
            $actions['address_list'] = 'skipped (no IP address on device)';
            $actions['dhcp_lease'] = 'skipped (no IP address on device)';
        }

        // Lift the L2 MAC gate (rule exists but stays disabled = allowed).
        $this->service->setLanDeviceAccess($device, enabled: true);
        $actions['mac_gate'] = 'allowed';

        return $actions;
    }

    /**
     * Cut internet access from a direct/LAN device instantly.
     *
     * @return array audit trail of every RouterOS action performed
     */
    public function deactivate(Device $device): array
    {
        $actions = [];

        // Engage the L2 MAC gate first — cut is immediate.
        $this->service->setLanDeviceAccess($device, enabled: false);
        $actions['mac_gate'] = 'blocked';

        if ($device->ip_address) {
            $actions['address_list'] = $this->service->removeAllowListEntry($device->ip_address);
            $actions['dhcp_lease'] = 'kept (reservation preserved for re-activation)';
        } else {
            $actions['address_list'] = 'skipped (no IP address on device)';
            $actions['dhcp_lease'] = 'skipped (no IP address on device)';
        }

        return $actions;
    }

    /**
     * Full teardown used when a device record is deleted: gate rule,
     * allow-list entry and our static lease reservation.
     *
     * @return array audit trail of every RouterOS action performed
     */
    public function purge(Device $device): array
    {
        $actions = [];

        $this->service->removeLanDeviceAccess($device);
        $actions['mac_gate'] = 'removed';

        if ($device->ip_address) {
            $actions['address_list'] = $this->service->removeAllowListEntry($device->ip_address);
        }

        if ($device->mac_address) {
            $actions['dhcp_lease'] = $this->service->removeStaticLease($device->mac_address);
        }

        return $actions;
    }

    private function comment(Device $device): string
    {
        return "device:{$device->id}|client:{$device->client_id}|{$device->mac_address}";
    }
}
