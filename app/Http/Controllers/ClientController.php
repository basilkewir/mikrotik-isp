<?php

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Models\Client;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Router;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        $query = Client::withCount(['subscriptions', 'devices'])->latest('id');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('client_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $clients = $query->paginate(15)->withQueryString();

        return Inertia::render('Clients/Index', [
            'clients' => $clients,
            'filters' => [
                'search' => $request->query('search'),
            ],
        ]);
    }

    /**
     * Import hotspot/PPPoE users from every router into local clients,
     * plans and subscriptions (idempotent — safe to run repeatedly).
     */
    public function sync(): RedirectResponse
    {
        try {
            $result = Artisan::call('isp:sync-router-users');
            $output = Artisan::output();

            return back()->with('success', 'Router sync complete. ' . preg_replace('/\s+/', ' ', trim($output)));
        } catch (Exception $exception) {
            return back()->with('error', 'Router sync failed: ' . $exception->getMessage());
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);

        $validated['client_code'] = Client::generateClientCode();
        $validated['status'] = 'active';

        $client = Client::create($validated);

        return redirect()->route('clients.show', $client->id)
            ->with('success', "Subscriber {$client->name} ({$client->client_code}) created successfully.");
    }

    public function show(Client $client): Response
    {
        $client->load([
            'subscriptions' => fn($q) => $q->with(['plan', 'router'])->latest('id'),
            'devices' => fn($q) => $q->with('router')->latest('id'),
            'walletTransactions' => fn($q) => $q->latest('id')->limit(20),
        ]);

        $routers = Router::where('is_online', true)->get(['id', 'name', 'ip_address']);
        $plans = Plan::where('is_active', true)->get(['id', 'name', 'price', 'type', 'service_type', 'validity_days', 'data_limit_mb']);

        $customerEdge = array_filter(DeviceType::cases(), fn (DeviceType $t) => $t->isCustomerEdge());

        return Inertia::render('Clients/Show', [
            'client' => $client,
            'routers' => $routers,
            'plans' => $plans,
            'deviceTypeOptions' => array_map(fn (DeviceType $t) => $t->label(), array_values($customerEdge)),
        ]);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'balance' => 'nullable|numeric|min:0|max:999999999',
            'status' => 'required|in:active,suspended,inactive',
            'portal_password' => 'nullable|string|min:6|max:100',
        ]);

        $client->update([
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'balance' => $validated['balance'] ?? 0,
            'status' => $validated['status'],
        ]);

        if (!empty($validated['portal_password'])) {
            $client->update([
                'portal_password' => \Illuminate\Support\Facades\Hash::make($validated['portal_password']),
                'portal_password_set_at' => now(),
            ]);
        }

        return back()->with('success', "Subscriber {$client->name} updated successfully.");
    }

    public function destroy(Client $client): RedirectResponse
    {
        $failures = [];

        foreach ($client->subscriptions as $subscription) {
            if (!$subscription->router) {
                continue;
            }

            try {
                $service = $subscription->router->service();

                if ($subscription->auth_type === 'hotspot') {
                    $service->removeHotspotUser($subscription->username);
                } elseif ($subscription->auth_type === 'pppoe') {
                    $service->removePppoeSecret($subscription->username);
                }

                if ($subscription->assigned_ip) {
                    $service->unfreezeIp($subscription->assigned_ip);
                }

                $service->disconnect();
            } catch (Exception $exception) {
                $failures[] = "{$subscription->username}: {$exception->getMessage()}";
            }
        }

        foreach ($client->devices as $device) {
            if (!$device->router) {
                continue;
            }

            try {
                $device->removeLanAccess();
            } catch (Exception $exception) {
                $failures[] = "{$device->mac_address}: {$exception->getMessage()}";
            }
        }

        $client->delete();

        if ($failures) {
            return back()->with('warning', 'Subscriber deleted, but some router records could not be cleaned up: ' . implode(' | ', $failures));
        }

        return back()->with('success', "Subscriber {$client->client_code} and all related records deleted.");
    }

    public function storeDevice(Client $client, Request $request): RedirectResponse
    {
        $customerEdgeTypes = implode(',', array_map(
            fn (DeviceType $t) => $t->value,
            array_filter(DeviceType::cases(), fn (DeviceType $t) => $t->isCustomerEdge())
        ));

        $validated = $request->validate([
            'router_id' => 'required|exists:routers,id',
            'mac_address' => ['required', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'ip_address' => 'nullable|ip',
            'hostname' => 'nullable|string|max:100',
            'type' => 'nullable|in:' . $customerEdgeTypes,
            'serial_number' => 'nullable|string|max:64',
            'model' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:80',
            'notes' => 'nullable|string',
            'access_duration_days' => 'required|integer|min:1|max:3650',
        ]);

        $device = $client->devices()->create([
            ...$validated,
            'mac_address' => strtoupper($validated['mac_address']),
            'type' => $validated['type'] ?? DeviceType::Cpe->value,
            'is_active' => true,
            'access_expires_at' => Carbon::now()->addDays((int) $validated['access_duration_days']),
            'comment' => 'Managed LAN access for ' . $client->name,
        ]);

        try {
            $device->provisionLanAccess();
        } catch (Exception $exception) {
            $device->update(['is_active' => false]);

            return back()->with('error', "LAN device saved but could not configure RouterOS bridge filtering: " . $exception->getMessage());
        }

        return back()->with('success', "LAN access provisioned for {$device->hostname} until {$device->access_expires_at->toDateTimeString()}.");
    }

    public function updateDevice(Device $device, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'hostname' => 'nullable|string|max:100',
            'ip_address' => 'nullable|ip',
            'access_duration_days' => 'required|integer|min:1|max:3650',
            'renew' => 'boolean',
        ]);

        $data = [
            'hostname' => $validated['hostname'] ?? null,
            'ip_address' => $validated['ip_address'] ?? null,
            'access_duration_days' => (int) $validated['access_duration_days'],
        ];

        if (!empty($validated['renew'])) {
            $data['access_expires_at'] = Carbon::now()->addDays((int) $validated['access_duration_days']);
        }

        $oldIp = $device->ip_address;
        $device->update($data);

        // Re-sync the router when the IP changed (allow-list entry and
        // static lease are keyed by IP/MAC).
        if ($device->wasChanged('ip_address')) {
            try {
                if ($oldIp && $device->router) {
                    $service = $device->router->service();

                    try {
                        $service->removeAllowListEntry($oldIp);
                    } finally {
                        $service->disconnect();
                    }
                }

                $device->provisionLanAccess();
            } catch (Exception $exception) {
                return back()->with('warning', "Device updated, but the router allow-list could not be re-synced: " . $exception->getMessage());
            }
        }

        return back()->with('success', "LAN device {$device->mac_address} updated" . (!empty($validated['renew']) ? " and access reset to {$device->access_expires_at->toDateTimeString()}." : '.'));
    }

    public function destroyDevice(Device $device): RedirectResponse
    {
        try {
            $device->removeLanAccess();
        } catch (Exception $exception) {
            $device->delete();

            return back()->with('warning', 'Device record deleted, but the router could not be cleaned up: ' . $exception->getMessage());
        }

        $device->delete();

        return back()->with('success', "LAN device {$device->mac_address} removed (gate rule, allow-list entry and static lease cleaned up).");
    }

    public function disableDevice(Device $device): RedirectResponse
    {
        try {
            $device->disableLanAccess();

            return back()->with('success', "LAN access disabled for {$device->mac_address}.");
        } catch (Exception $exception) {
            return back()->with('error', "Failed to disable LAN access for {$device->mac_address}: " . $exception->getMessage());
        }
    }

    public function enableDevice(Device $device): RedirectResponse
    {
        try {
            $device->renewLanAccess();

            return back()->with('success', "LAN access renewed for {$device->mac_address} until {$device->fresh()->access_expires_at->toDateTimeString()}.");
        } catch (Exception $exception) {
            return back()->with('error', "Failed to renew LAN access for {$device->mac_address}: " . $exception->getMessage());
        }
    }
}
