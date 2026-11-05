<?php

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Models\Client;
use App\Models\Device;
use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Services\Mikrotik\ConnectedDeviceService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ConnectedDeviceController extends Controller
{
    /**
     * Isolation/quarantine address-list used for quick block/unblock.
     * Defaults to the platform's own list (never the foreign blocked-devices).
     */
    private const BLOCK_LIST = 'EXPIRED_USERS';

    public function index(Request $request, ConnectedDeviceService $devices): Response
    {
        $payload = $this->connectedSnapshot($request, $devices);

        return Inertia::render('ConnectedDevices/Index', [
            ...$payload,
            'routers' => Router::latest('id')->get()->map(fn (Router $router) => $router->only(['id', 'name'])),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'client_code']),
            'typeOptions' => DeviceType::options(),
            'filters' => $request->only(['q', 'router_id', 'status', 'source', 'typology', 'managed']),
        ]);
    }

    public function live(Request $request, ConnectedDeviceService $devices): JsonResponse
    {
        return response()->json($this->connectedSnapshot($request->merge(['live' => true]), $devices));
    }

    /**
     * Build a fresh connected-device payload for the page or live JSON poll.
     *
     * @return array{devices: array<int, array>, routerErrors: array<int, array>, fetchedAt: string}
     */
    private function connectedSnapshot(Request $request, ConnectedDeviceService $devices): array
    {
        $connected = [];
        $routerErrors = [];

        foreach (Router::latest('id')->get() as $router) {
            try {
                $blocked = $this->blockedState($router);
                $blockedIps = $blocked['ips'];
                $blockedMacs = $blocked['macs'];

                foreach ($devices->snapshot($router, 30, $request->boolean('live')) as $device) {
                    $device['is_blocked'] = ($device['ip_address']
                            && in_array($device['ip_address'], $blockedIps, true))
                        || ($device['mac_address']
                            && in_array(strtoupper($device['mac_address']), $blockedMacs, true));
                    $connected[] = $device;
                }
            } catch (Exception $exception) {
                $routerErrors[] = [
                    'router_name' => $router->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return [
            'devices' => $connected,
            'routerErrors' => $routerErrors,
            'fetchedAt' => now()->toIso8601String(),
        ];
    }

    /**
     * Onboard a live connected device into the managed inventory.
     * Assigns a client + typology, provisions MAC authorization for customer-edge types.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'mac_address' => ['required', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'type' => ['required', 'in:' . implode(',', array_column(DeviceType::cases(), 'value'))],
            'client_id' => 'nullable|exists:clients,id',
            'ip_address' => 'nullable|ip',
            'hostname' => 'nullable|string|max:100',
            'comment' => 'nullable|string|max:150',
            'access_duration_days' => 'nullable|integer|min:1|max:3650',
            'access_expires_at' => 'nullable|date',
        ]);

        $mac = strtoupper($validated['mac_address']);

        if (Device::where('router_id', $validated['router_id'])->where('mac_address', $mac)->exists()) {
            return back()->with('error', "Device {$mac} is already in the inventory for this router.");
        }

        $type = DeviceType::from($validated['type']);
        $isCustomerEdge = $type->isCustomerEdge();
        [$days, $expiresAt] = Device::resolveAccessExpiry(
            !empty($validated['access_duration_days']) ? (int) $validated['access_duration_days'] : null,
            $validated['access_expires_at'] ?? null,
            $isCustomerEdge,
        );

        $device = Device::create([
            'router_id' => $validated['router_id'],
            'client_id' => $validated['client_id'] ?? null,
            'mac_address' => $mac,
            'ip_address' => $validated['ip_address'] ?? null,
            'hostname' => $validated['hostname'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'type' => $type->value,
            'is_active' => true,
            'access_duration_days' => $days,
            'access_expires_at' => $expiresAt,
        ]);

        if ($isCustomerEdge && $device->router) {
            try {
                $device->provisionLanAccess();
            } catch (Exception $exception) {
                return back()->with('warning', "Device {$mac} added, but router provisioning failed: " . $exception->getMessage());
            }
        }

        $suffix = $device->access_expires_at
            ? ($days !== null
                ? " Access set to {$days} days (expires {$device->access_expires_at->toDateTimeString()})."
                : " Access expires {$device->access_expires_at->toDateTimeString()}.")
            : '';

        return back()->with('success', "Device {$mac} onboarded as " . $type->label() . '.' . $suffix);
    }

    /**
     * Immediately cut a device off: kick sessions and isolate IP, MAC, and
     * bridged traffic so a DHCP renewal or device restart cannot reconnect.
     */
    public function block(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'ip_address' => 'nullable|ip',
            'mac_address' => ['nullable', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'user' => 'nullable|string|max:100',
            'protocol' => 'nullable|in:hotspot,pppoe',
        ]);

        $router = Router::findOrFail($validated['router_id']);
        try {
            $service = $router->service();
        } catch (Exception $exception) {
            return back()->with('error', 'Could not reach the router: ' . $exception->getMessage());
        }

        try {
            $kicked = $this->kickSessions($service, $validated);

            if (!empty($validated['ip_address'])) {
                $service->isolateIp($validated['ip_address'], self::BLOCK_LIST, 'ISP-BLOCKED');
            }

            // MAC rule too: the kick makes the client re-login with a new
            // DHCP lease, which an IP-only block would no longer match.
            if (!empty($validated['mac_address'])) {
                $service->isolateMac($validated['mac_address']);
            }

            $managed = $this->managedLanDevice($router, $validated);
            if ($managed !== null) {
                $managed->disableLanAccess();
            }
        } finally {
            $service->disconnect();
        }

        $this->forgetBlockedCache($router);

        $target = $validated['ip_address'] ?? $validated['mac_address'] ?? $validated['user'] ?? 'device';
        $message = "Blocked {$target}.";
        if ($kicked > 0) {
            $message .= " Kicked {$kicked} active session(s).";
        }

        return back()->with('success', $message);
    }

    /**
     * Lift a block: remove the IP from the isolation list.
     */
    public function unblock(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'ip_address' => 'required_without:mac_address|nullable|ip',
            'mac_address' => ['required_without:ip_address', 'nullable', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
        ]);

        $router = Router::findOrFail($validated['router_id']);
        try {
            $service = $router->service();
        } catch (Exception $exception) {
            return back()->with('error', 'Could not reach the router: ' . $exception->getMessage());
        }

        try {
            if (!empty($validated['ip_address'])) {
                $service->unfreezeIp($validated['ip_address'], self::BLOCK_LIST);
            }

            if (!empty($validated['mac_address'])) {
                $service->unisolateMac($validated['mac_address']);
            }

            $managed = $this->managedLanDevice($router, $validated);
            if ($managed !== null) {
                $managed->provisionLanAccess();
            }
        } finally {
            $service->disconnect();
        }

        $this->forgetBlockedCache($router);

        $target = $validated['ip_address'] ?? $validated['mac_address'];

        return back()->with('success', "Unblocked {$target}.");
    }

    /**
     * Drop the cached blocked-IP list so the very next page render reflects
     * the change — otherwise the Blocked badge (and Unblock button) linger
     * for up to 30s and make the action look like it did nothing.
     */
    private function forgetBlockedCache(Router $router): void
    {
        cache()->forget("mikrotik:blocked:{$router->id}");
        cache()->forget("mikrotik:blocked:{$router->id}:last");
    }

    private function managedLanDevice(Router $router, array $data): ?Device
    {
        return Device::query()
            ->where('router_id', $router->id)
            ->whereIn('type', ['cpe', 'bridge_client', 'onu'])
            ->where(function ($query) use ($data): void {
                if (!empty($data['mac_address'])) {
                    $query->whereIn('mac_address', [
                        strtoupper($data['mac_address']),
                        strtolower($data['mac_address']),
                    ]);
                }

                if (!empty($data['ip_address'])) {
                    $query->orWhere('ip_address', $data['ip_address']);
                }
            })
            ->first();
    }

    /**
     * Disconnect a live hotspot / PPPoE session without blocking the device.
     */
    public function kick(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'user' => 'required|string|max:100',
            'protocol' => 'required|in:hotspot,pppoe',
        ]);

        $router = Router::findOrFail($validated['router_id']);
        try {
            $service = $router->service();
        } catch (Exception $exception) {
            return back()->with('error', 'Could not reach the router: ' . $exception->getMessage());
        }

        try {
            $kicked = $this->kickSessions($service, $validated);
        } finally {
            $service->disconnect();
        }

        return back()->with(
            $kicked > 0 ? 'success' : 'warning',
            $kicked > 0
                ? "Kicked {$kicked} session(s) for {$validated['user']}."
                : "No active {$validated['protocol']} session found for {$validated['user']}."
        );
    }

    /**
     * Apply (or update) a simple queue rate limit on a device IP.
     */
    public function queue(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'ip_address' => 'required|ip',
            'upload' => 'required|string|max:20',
            'download' => 'required|string|max:20',
        ]);

        $router = Router::findOrFail($validated['router_id']);
        try {
            $service = $router->service();
        } catch (Exception $exception) {
            return back()->with('error', 'Could not reach the router: ' . $exception->getMessage());
        }

        try {
            $limit = $validated['upload'] . '/' . $validated['download'];
            $service->setSimpleQueue('ISP-' . $validated['ip_address'], $validated['ip_address'], $limit);
        } finally {
            $service->disconnect();
        }

        return back()->with('success', "Speed limit {$limit} applied to {$validated['ip_address']}.");
    }

    /**
     * Save an operator comment onto the device's DHCP lease — the only
     * RouterOS record of a live client with a writable comment — and mirror
     * it onto the managed inventory row when one exists.
     *
     * Ownership tags on platform-created leases are preserved: only the
     * human-readable note is replaced.
     */
    public function comment(Request $request, ConnectedDeviceService $devices): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'mac_address' => ['nullable', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'ip_address' => 'nullable|ip',
            'comment' => 'nullable|string|max:150',
        ]);

        $mac = !empty($validated['mac_address']) ? strtoupper($validated['mac_address']) : null;
        $ip = $validated['ip_address'] ?? null;
        $comment = trim($validated['comment'] ?? '');

        if ($mac === null && $ip === null) {
            return back()->with('error', 'A MAC or IP address is required to comment on a device.');
        }

        $router = Router::findOrFail($validated['router_id']);
        try {
            $service = $router->service();
        } catch (Exception $exception) {
            return back()->with('error', 'Could not reach the router: ' . $exception->getMessage());
        }

        try {
            $result = $service->setDeviceComment($mac, $ip, $comment);
        } catch (Exception $exception) {
            return back()->with('error', 'Failed to save comment: ' . $exception->getMessage());
        } finally {
            $service->disconnect();
        }

        // Mirror the plain note onto the managed copy (the router may carry
        // an ownership tag prefix the operator should never see).
        $managed = Device::where('router_id', $router->id)
            ->when($mac !== null, fn ($query) => $query->whereIn('mac_address', [$mac, strtolower($mac)]))
            ->first();
        $managed?->update(['comment' => $comment !== '' ? $comment : null]);

        $target = $mac ?? $ip;
        $suffix = $this->commentErrorSuffix($result);

        if ($result['updated'] === []) {
            if ($result['errors'] !== []) {
                return back()->with('error', "Failed to save the comment on {$target}." . $suffix);
            }

            return back()->with(
                'warning',
                "No DHCP lease found for {$target} — the comment was not written to the router."
                . ($managed !== null ? ' Kept as an inventory note only.' : '')
            );
        }

        RouterActivityLog::create([
            'router_id' => $router->id,
            'event' => 'device_comment_update',
            'level' => $suffix === '' ? 'info' : 'warning',
            'details' => [
                'device_id' => $managed?->id,
                'mac_address' => $mac,
                'ip_address' => $ip,
                'comment' => $comment,
                'targets' => $result,
            ],
            'ip_address' => $ip,
        ]);

        $devices->forgetSnapshot($router);

        $targets = implode(', ', $result['updated']);
        $message = $comment === ''
            ? "Comment cleared on {$target} ({$targets})."
            : "Comment saved on {$target} ({$targets}).";

        return back()->with($suffix === '' ? 'success' : 'warning', $message . $suffix);
    }

    /**
     * Human-readable tail listing the router targets that refused the write.
     */
    private function commentErrorSuffix(array $result): string
    {
        if (empty($result['errors'])) {
            return '';
        }

        $parts = [];
        foreach ($result['errors'] as $target => $message) {
            $parts[] = "{$target}: {$message}";
        }

        return ' Not updated — ' . implode('; ', $parts) . '.';
    }

    /**
     * Renew (re-grant) access for a managed customer-edge device.
     * Optionally override the access duration (in days) or set an absolute
     * expiry datetime — the absolute date always wins.
     */
    public function renew(Request $request, Device $device): RedirectResponse
    {
        $validated = $request->validate([
            'access_duration_days' => 'nullable|integer|min:1|max:3650',
            'access_expires_at' => 'nullable|date',
        ]);

        $days = !empty($validated['access_duration_days']) ? (int) $validated['access_duration_days'] : null;
        $expiresAt = !empty($validated['access_expires_at'])
            ? Carbon::parse($validated['access_expires_at'])
            : null;

        try {
            $device->renewLanAccess($days, $expiresAt);

            $fresh = $device->fresh();

            return back()->with('success', $expiresAt
                ? "Access renewed — {$device->mac_address} valid until {$fresh->access_expires_at->toDateTimeString()}."
                : "Access renewed for " . ($days ?? $fresh->access_duration_days) . " days — {$device->mac_address} valid until {$fresh->access_expires_at->toDateString()}.");
        } catch (Exception $exception) {
            return back()->with('error', "Failed to renew access: " . $exception->getMessage());
        }
    }

    /**
     * Disable access for a managed device (keeps the inventory record).
     */
    public function disable(Device $device): RedirectResponse
    {
        try {
            $device->disableLanAccess();

            return back()->with('success', "Access disabled for {$device->mac_address}.");
        } catch (Exception $exception) {
            return back()->with('error', "Failed to disable access: " . $exception->getMessage());
        }
    }

    /**
     * Kick hotspot/pppoe sessions for the given user + protocol.
     *
     * @return int number of sessions disconnected
     */
    private function kickSessions($service, array $data): int
    {
        if (empty($data['user'])) {
            return 0;
        }

        return match ($data['protocol'] ?? 'hotspot') {
            'pppoe' => $service->kickPppoeActive($data['user']),
            default => $service->kickHotspotActive($data['user']),
        };
    }

    /**
     * IPs and MACs currently isolated on the router (used to flag blocked
     * rows). Cached for 30s like the device snapshot: this opens its own
     * router connection, and during login backoff it would otherwise cost a
     * login probe on every page load. block()/unblock() forget the cache so
     * the badge always reflects the click immediately.
     *
     * @return array{ips: array<int, string>, macs: array<int, string>}
     */
    private function blockedState(Router $router): array
    {
        $cacheKey = "mikrotik:blocked:{$router->id}";
        $state = $this->normalizeBlockedState(cache()->get($cacheKey));

        if ($state !== null) {
            return $state;
        }

        try {
            $service = $router->service();

            try {
                $ips = array_values(array_filter(array_map(
                    fn (array $row): ?string => $row['address'] ?? null,
                    $service->listAddressList(self::BLOCK_LIST)
                )));
                $macs = $service->listIsolatedMacs();
            } finally {
                $service->disconnect();
            }

            $state = ['ips' => $ips, 'macs' => $macs];

            cache()->put($cacheKey, $state, 30);
            cache()->put($cacheKey . ':last', $state, 3600);

            return $state;
        } catch (Exception $exception) {
            return $this->normalizeBlockedState(cache()->get($cacheKey . ':last')) ?? ['ips' => [], 'macs' => []];
        }
    }

    /**
     * Accept the current {ips, macs} shape and the legacy plain IP list.
     *
     * @return array{ips: array<int, string>, macs: array<int, string>}|null
     */
    private function normalizeBlockedState(mixed $cached): ?array
    {
        if (!is_array($cached) || $cached === []) {
            return null;
        }

        if (array_key_exists('ips', $cached)) {
            return ['ips' => $cached['ips'], 'macs' => $cached['macs'] ?? []];
        }

        // Legacy shape: a flat list of IPs.
        return ['ips' => $cached, 'macs' => []];
    }
}
