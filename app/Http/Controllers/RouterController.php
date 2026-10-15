<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Services\Mikrotik\RouterDataService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RouterController extends Controller
{
    public function index(RouterDataService $routerData): Response
    {
        $routers = Router::latest('id')->get()->map(function (Router $router) use ($routerData) {
            try {
                $snapshot = $routerData->snapshot($router);
                $users = $snapshot['users'];

                return [
                    ...$snapshot['router'],
                    'hotspot_users_count' => count(array_filter($users, fn (array $user) => $user['auth_type'] === 'hotspot')),
                    'pppoe_users_count' => count(array_filter($users, fn (array $user) => $user['auth_type'] === 'pppoe')),
                ];
            } catch (Exception $exception) {
                return [
                    ...$router->fresh()->toArray(),
                    'hotspot_users_count' => null,
                    'pppoe_users_count' => null,
                    'connection_error' => $exception->getMessage(),
                ];
            }
        });

        return Inertia::render('Routers/Index', [
            'routers' => $routers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'ip_address' => 'required|ip',
            'api_port' => 'required|integer|min:1|max:65535',
            'api_username' => 'required|string|max:100',
            'api_password' => 'required|string',
            'use_ssl' => 'boolean',
        ]);

        $router = Router::create($validated);

        // Immediate test & health fetch
        try {
            $health = $router->service()->checkHealth();
            $router->update([
                'is_online' => true,
                'identity' => $health['identity'],
                'uptime' => $health['uptime'],
                'ros_version' => $health['version'],
                'board_name' => $health['board_name'],
                'cpu_load' => $health['cpu_load'],
                'free_memory' => $health['free_memory'],
                'total_memory' => $health['total_memory'],
                'last_seen_at' => now(),
            ]);
            $router->service()->disconnect();
            return back()->with('success', "Router '{$router->name}' added and verified online!");
        } catch (Exception $e) {
            return back()->with('warning', "Router saved, but initial connection failed: " . $e->getMessage());
        }
    }

    public function update(Request $request, Router $router): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'ip_address' => 'required|ip',
            'api_port' => 'required|integer|min:1|max:65535',
            'api_username' => 'required|string|max:100',
            'api_password' => 'nullable|string',
            'use_ssl' => 'boolean',
        ]);

        if (empty($validated['api_password'])) {
            unset($validated['api_password']);
        }

        $router->update($validated);

        try {
            $health = $router->service()->checkHealth();
            $router->update([
                'is_online' => true,
                'identity' => $health['identity'],
                'uptime' => $health['uptime'],
                'ros_version' => $health['version'],
                'board_name' => $health['board_name'],
                'cpu_load' => $health['cpu_load'],
                'free_memory' => $health['free_memory'],
                'total_memory' => $health['total_memory'],
                'last_seen_at' => now(),
            ]);
            $router->service()->disconnect();

            return back()->with('success', "Router '{$router->name}' updated and verified online!");
        } catch (Exception $e) {
            $router->update(['is_online' => false]);

            return back()->with('warning', "Router updated, but connection failed: " . $e->getMessage());
        }
    }

    /**
     * Return all Hotspot / PPPoE profiles ("plans") configured on the router.
     */
    public function profiles(Router $router)
    {
        try {
            $service = $router->service();

            try {
                $profiles = $service->getRouterProfiles();
            } finally {
                $service->disconnect();
            }

            return response()->json([
                'router' => $router->only(['id', 'name', 'ip_address']),
                'hotspot_profiles' => $profiles['hotspot_profiles'],
                'pppoe_profiles' => $profiles['pppoe_profiles'],
            ]);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * Read-only status of the ISP allow-list firewall policy on this router.
     */
    public function firewallPolicy(Router $router)
    {
        try {
            $service = $router->service();

            try {
                $state = $service->getFirewallPolicyState();
            } finally {
                $service->disconnect();
            }

            return response()->json($state);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * Install the default-deny (allow-list) firewall policy on the router.
     */
    public function installFirewallPolicy(Request $request, Router $router)
    {
        $validated = $request->validate([
            'wan_interface' => 'nullable|string|max:50',
            'seed' => 'boolean',
        ]);

        try {
            $service = $router->service();

            try {
                $wan = $validated['wan_interface'] ?: $service->detectWanInterface();

                if (!$wan) {
                    throw new Exception('Could not detect the WAN interface from the default route. Enter it manually.');
                }

                $seed = ($validated['seed'] ?? true)
                    ? array_merge(
                        $service->getReachableArpAddresses(),
                        Device::where('router_id', $router->id)->pluck('ip_address')->filter()->all()
                    )
                    : [];

                $result = $service->installFirewallPolicy($wan, $seed);
            } finally {
                $service->disconnect();
            }

            RouterActivityLog::create([
                'router_id' => $router->id,
                'event' => 'firewall_policy_install',
                'level' => 'warning',
                'details' => $result,
            ]);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    /**
     * Remove the ISP firewall policy rules (allow-list entries are kept).
     */
    public function removeFirewallPolicy(Router $router)
    {
        try {
            $service = $router->service();

            try {
                $result = $service->removeFirewallPolicy();
            } finally {
                $service->disconnect();
            }

            RouterActivityLog::create([
                'router_id' => $router->id,
                'event' => 'firewall_policy_remove',
                'level' => 'warning',
                'details' => $result,
            ]);

            return response()->json($result);
        } catch (Exception $e) {
            return response()->json(['message' => $e->getMessage()], 502);
        }
    }

    public function testConnection(Router $router)
    {
        try {
            $health = $router->service()->checkHealth();
            $router->update([
                'is_online' => true,
                'last_seen_at' => now(),
                'cpu_load' => $health['cpu_load'],
                'uptime' => $health['uptime'],
            ]);
            $router->service()->disconnect();

            return back()->with('success', "Connected to {$health['identity']} (Board: {$health['board_name']}, ROS: {$health['version']}, CPU: {$health['cpu_load']}%)");
        } catch (Exception $e) {
            $router->update(['is_online' => false]);
            return back()->with('error', "Connection failed: " . $e->getMessage());
        }
    }

    public function destroy(Router $router): RedirectResponse
    {
        $router->delete();
        return back()->with('success', 'Router removed successfully.');
    }
}
