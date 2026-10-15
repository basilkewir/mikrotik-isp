<?php

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Models\Client;
use App\Models\Device;
use App\Models\Router;
use App\Services\Mikrotik\ConnectedDeviceService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $routers = Router::latest('id')->get();

        $connected = [];
        $routerErrors = [];

        foreach ($routers as $router) {
            try {
                $blockedIps = $this->blockedIps($router);

                foreach ($devices->snapshot($router) as $device) {
                    $device['is_blocked'] = $device['ip_address']
                        && in_array($device['ip_address'], $blockedIps, true);
                    $connected[] = $device;
                }
            } catch (Exception $exception) {
                $routerErrors[] = [
                    'router_name' => $router->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        return Inertia::render('ConnectedDevices/Index', [
            'devices' => $connected,
            'routers' => $routers->map(fn (Router $router) => $router->only(['id', 'name'])),
            'routerErrors' => $routerErrors,
            'fetchedAt' => now()->toIso8601String(),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'client_code']),
            'typeOptions' => DeviceType::options(),
            'filters' => $request->only(['q', 'router_id', 'status', 'source', 'typology', 'managed']),
        ]);
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
        ]);

        $mac = strtoupper($validated['mac_address']);

        if (Device::where('router_id', $validated['router_id'])->where('mac_address', $mac)->exists()) {
            return back()->with('error', "Device {$mac} is already in the inventory for this router.");
        }

        $type = DeviceType::from($validated['type']);

        $device = Device::create([
            'router_id' => $validated['router_id'],
            'client_id' => $validated['client_id'] ?? null,
            'mac_address' => $mac,
            'ip_address' => $validated['ip_address'] ?? null,
            'hostname' => $validated['hostname'] ?? null,
            'comment' => $validated['comment'] ?? null,
            'type' => $type->value,
            'is_active' => true,
            'access_duration_days' => $type->isCustomerEdge() ? 30 : null,
            'access_expires_at' => $type->isCustomerEdge() ? now()->addDays(30) : null,
        ]);

        if ($type->isCustomerEdge() && $device->router) {
            try {
                $device->provisionLanAccess();
            } catch (Exception $exception) {
                return back()->with('warning', "Device {$mac} added, but router provisioning failed: " . $exception->getMessage());
            }
        }

        return back()->with('success', "Device {$mac} onboarded as " . $type->label() . '.');
    }

    /**
     * Immediately cut a device off: kick any live session and isolate its IP.
     */
    public function block(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'ip_address' => 'nullable|ip',
            'user' => 'nullable|string|max:100',
            'protocol' => 'nullable|in:hotspot,pppoe',
        ]);

        $router = Router::findOrFail($validated['router_id']);
        $service = $router->service();

        try {
            $kicked = $this->kickSessions($service, $validated);

            if (!empty($validated['ip_address'])) {
                $service->isolateIp($validated['ip_address'], self::BLOCK_LIST, 'ISP-BLOCKED');
            }
        } finally {
            $service->disconnect();
        }

        $target = $validated['ip_address'] ?? $validated['user'] ?? 'device';
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
            'ip_address' => 'required|ip',
        ]);

        $router = Router::findOrFail($validated['router_id']);
        $service = $router->service();

        try {
            $service->unfreezeIp($validated['ip_address'], self::BLOCK_LIST);
        } finally {
            $service->disconnect();
        }

        return back()->with('success', "Unblocked {$validated['ip_address']}.");
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
        $service = $router->service();

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
        $service = $router->service();

        try {
            $limit = $validated['upload'] . '/' . $validated['download'];
            $service->setSimpleQueue('ISP-' . $validated['ip_address'], $validated['ip_address'], $limit);
        } finally {
            $service->disconnect();
        }

        return back()->with('success', "Speed limit {$limit} applied to {$validated['ip_address']}.");
    }

    /**
     * Renew (re-grant) access for a managed customer-edge device.
     */
    public function renew(Device $device): RedirectResponse
    {
        try {
            $device->renewLanAccess();

            return back()->with('success', "Access renewed for {$device->mac_address} until {$device->fresh()->access_expires_at->toDateTimeString()}.");
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
     * IPs currently on the router's isolation list (used to flag blocked rows).
     *
     * @return array<int, string>
     */
    private function blockedIps(Router $router): array
    {
        try {
            $service = $router->service();

            try {
                $rows = $service->listAddressList(self::BLOCK_LIST);
            } finally {
                $service->disconnect();
            }

            return array_values(array_filter(array_map(
                fn (array $row): ?string => $row['address'] ?? null,
                $rows
            )));
        } catch (Exception $exception) {
            return [];
        }
    }
}
