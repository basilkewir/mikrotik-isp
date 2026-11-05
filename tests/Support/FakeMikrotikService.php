<?php

namespace Tests\Support;

use App\Models\Router;
use App\Services\Mikrotik\MikrotikService;

/**
 * In-memory MikrotikService double: records every router operation and can
 * simulate failures or concurrent renewals, without opening a socket.
 */
class FakeMikrotikService extends MikrotikService
{
    /** @var array<int, array{0: string, 1: array}> */
    public array $calls = [];

    /** Executed inside disableHotspotUser, after recording — simulates a renewal landing mid-enforcement. */
    public ?\Closure $beforeDisable = null;

    public ?\RuntimeException $failWith = null;

    /** Result returned by setDeviceComment — tests can simulate absent/failed targets. */
    public array $commentResult = ['updated' => ['dhcp_lease'], 'absent' => [], 'errors' => []];

    /** Connected-device source payload returned by getConnectedDeviceSources. */
    public ?array $deviceSources = null;

    private ?Router $targetRouter = null;

    public function __construct(?Router $router = null)
    {
        // Intentionally skip the parent constructor: no TCP connection.
        $this->targetRouter = $router;
    }

    public function callsNamed(string $method): array
    {
        return array_values(array_filter($this->calls, fn (array $call) => $call[0] === $method));
    }

    public function hasCall(string $method): bool
    {
        return $this->callsNamed($method) !== [];
    }

    private function record(string $method, array $args = []): void
    {
        $this->calls[] = [$method, $args];
    }

    public function disableHotspotUser(string $username): void
    {
        $this->record('disableHotspotUser', compact('username'));

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        if ($this->beforeDisable !== null) {
            $callback = $this->beforeDisable;
            $this->beforeDisable = null;
            $callback();
        }
    }

    public function enableHotspotUser(string $username): void
    {
        $this->record('enableHotspotUser', compact('username'));
    }

    public function disablePppoeSecret(string $username): void
    {
        $this->record('disablePppoeSecret', compact('username'));

        if ($this->failWith !== null) {
            throw $this->failWith;
        }
    }

    public function enablePppoeSecret(string $username): void
    {
        $this->record('enablePppoeSecret', compact('username'));
    }

    public function isolateIp(string $ip, string $listName = 'EXPIRED_USERS', ?string $comment = null): void
    {
        $this->record('isolateIp', compact('ip', 'listName', 'comment'));
    }

    public function isolateMac(string $mac): void
    {
        $this->record('isolateMac', compact('mac'));
    }

    public function setLanDeviceAccess(\App\Models\Device $device, bool $enabled): void
    {
        $this->record('setLanDeviceAccess', compact('device', 'enabled'));
    }

    public function addAllowListEntry(string $address, string $comment, string $list = 'active-clients'): string
    {
        $this->record('addAllowListEntry', compact('address', 'comment', 'list'));

        return 'added';
    }

    public function removeAllowListEntry(string $address, string $list = 'active-clients'): string
    {
        $this->record('removeAllowListEntry', compact('address', 'list'));

        return 'removed';
    }

    public function ensureStaticLease(string $macAddress, string $ipAddress, string $comment): string
    {
        $this->record('ensureStaticLease', compact('macAddress', 'ipAddress', 'comment'));

        return 'existing';
    }

    public function unisolateMac(string $mac): void
    {
        $this->record('unisolateMac', compact('mac'));
    }

    public function listAddressList(string $list = 'active-clients'): array
    {
        $this->record('listAddressList', compact('list'));

        return [];
    }

    public function listIsolatedMacs(): array
    {
        $this->record('listIsolatedMacs');

        return [];
    }

    public function unfreezeIp(string $ip, string $listName = 'EXPIRED_USERS'): void
    {
        $this->record('unfreezeIp', compact('ip', 'listName'));
    }

    public function kickHotspotActive(string $username): int
    {
        $this->record('kickHotspotActive', compact('username'));

        return 0;
    }

    public function setDeviceComment(?string $macAddress, ?string $ipAddress, string $comment): array
    {
        $this->record('setDeviceComment', compact('macAddress', 'ipAddress', 'comment'));

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->commentResult;
    }

    public function getConnectedDeviceSources(): array
    {
        $this->record('getConnectedDeviceSources');

        return $this->deviceSources ?? [
            'rows' => [
                'arp' => [],
                'dhcp' => [],
                'hotspot_active' => [],
                'hotspot_hosts' => [],
                'ppp_active' => [],
                'simple_queues' => [],
                'neighbors' => [],
            ],
            'errors' => [],
        ];
    }

    /** address => bool answered by pingReachable; unknown addresses do not reply. */
    public array $pingResults = [];

    public function pingReachable(string $address): bool
    {
        $this->record('pingReachable', compact('address'));

        return $this->pingResults[$address] ?? false;
    }

    public function setSimpleQueue(string $name, string $targetIp, string $maxLimit): array
    {
        $this->record('setSimpleQueue', compact('name', 'targetIp', 'maxLimit'));

        return [];
    }

    public function ensureTrafficQueue(string $name, string $targetIp): string
    {
        $this->record('ensureTrafficQueue', compact('name', 'targetIp'));

        return 'created';
    }

    public function ensureAutoReconnectProfile(string $profile, ?string $cookieTimeout = null): void
    {
        $this->record('ensureAutoReconnectProfile', compact('profile', 'cookieTimeout'));
    }

    public function ensureHotspotProfileDeviceCap(string $profile, int $sharedUsers): string
    {
        $this->record('ensureHotspotProfileDeviceCap', compact('profile', 'sharedUsers'));

        return 'updated';
    }

    public function ensureHotspotServerAuth(?string $serverProfile = null): void
    {
        $this->record('ensureHotspotServerAuth', compact('serverProfile'));
    }

    public function createOrUpdateHotspotUser(
        string $username,
        string $password,
        string $profile = 'default',
        ?string $comment = null,
        ?string $limitUptime = null,
        ?int $limitBytesTotal = null,
        ?int $sharedUsers = null
    ): array {
        $this->record('createOrUpdateHotspotUser', compact('username', 'password', 'profile'));

        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return [];
    }

    public function createOrUpdatePppoeSecret(
        string $username,
        string $password,
        string $profile = 'default',
        ?string $remoteAddress = null,
        ?string $comment = null
    ): array {
        $this->record('createOrUpdatePppoeSecret', compact('username', 'password', 'profile'));

        return [];
    }

    public function disconnect(): void
    {
        $this->record('disconnect');
    }
}
