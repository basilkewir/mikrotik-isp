<?php

namespace App\Http\Controllers;

use App\Enums\DeviceType;
use App\Models\Client;
use App\Models\Device;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DeviceController extends Controller
{
    /**
     * Inventory: every managed device with full typology filters.
     */
    public function index(Request $request): Response
    {
        $query = Device::with(['client:id,name,client_code', 'router:id,name', 'parent:id,hostname,mac_address,type'])
            ->latest('id');

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('router_id')) {
            $query->where('router_id', $request->integer('router_id'));
        }

        if ($request->filled('q')) {
            $search = $request->string('q');
            $query->where(function ($q) use ($search) {
                $q->where('mac_address', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%")
                    ->orWhere('hostname', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        $devices = $query->paginate(50)->withQueryString();

        return Inertia::render('Inventory/Index', [
            'devices' => $devices,
            'typeCounts' => Device::query()
                ->select('type', DB::raw('COUNT(*) as total'))
                ->groupBy('type')
                ->pluck('total', 'type'),
            'routers' => Router::orderBy('name')->get(['id', 'name']),
            'clients' => Client::orderBy('name')->get(['id', 'name', 'client_code']),
            'typeOptions' => DeviceType::options(),
            'parentOptions' => Device::orderBy('type')->orderBy('hostname')->get(['id', 'type', 'hostname', 'mac_address'])
                ->map(fn (Device $d) => [
                    'id' => $d->id,
                    'label' => ($d->hostname ?: $d->mac_address) . ' · ' . ($d->deviceType()?->label() ?? $d->type),
                ]),
            'filters' => $request->only(['type', 'router_id', 'q']),
            'topology' => $this->buildTopology(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', array_column(DeviceType::cases(), 'value'))],
            'mac_address' => ['required', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'router_id' => 'nullable|exists:routers,id',
            'client_id' => 'nullable|exists:clients,id',
            'parent_device_id' => 'nullable|exists:devices,id',
            'ip_address' => 'nullable|ip',
            'ipv6_address' => 'nullable|string|max:45',
            'hostname' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:64',
            'model' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:80',
            'firmware_version' => 'nullable|string|max:50',
            'interface' => 'nullable|string|max:50',
            'management_ip' => 'nullable|ip',
            'snmp_community' => 'nullable|string|max:50',
            'signal_strength' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $type = DeviceType::from($validated['type']);

        $device = Device::create([
            ...$validated,
            'mac_address' => strtoupper($validated['mac_address']),
            'is_active' => true,
            'access_duration_days' => $type->isCustomerEdge() ? 30 : null,
            'access_expires_at' => $type->isCustomerEdge() ? now()->addDays(30) : null,
        ]);

        return back()->with('success', "Device {$device->mac_address} (" . $type->label() . ") added to inventory.");
    }

    public function update(Request $request, Device $device)
    {
        $validated = $request->validate([
            'type' => ['required', 'in:' . implode(',', array_column(DeviceType::cases(), 'value'))],
            'mac_address' => ['required', 'regex:/^(?:[0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/'],
            'router_id' => 'nullable|exists:routers,id',
            'client_id' => 'nullable|exists:clients,id',
            'parent_device_id' => 'nullable|exists:devices,id',
            'ip_address' => 'nullable|ip',
            'ipv6_address' => 'nullable|string|max:45',
            'hostname' => 'nullable|string|max:100',
            'serial_number' => 'nullable|string|max:64',
            'model' => 'nullable|string|max:100',
            'manufacturer' => 'nullable|string|max:80',
            'firmware_version' => 'nullable|string|max:50',
            'interface' => 'nullable|string|max:50',
            'management_ip' => 'nullable|ip',
            'snmp_community' => 'nullable|string|max:50',
            'signal_strength' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        if ((int) ($validated['parent_device_id'] ?? 0) === $device->id) {
            return back()->with('error', 'A device cannot be its own parent.');
        }

        $device->update([
            ...$validated,
            'mac_address' => strtoupper($validated['mac_address']),
        ]);

        return back()->with('success', "Device {$device->mac_address} updated.");
    }

    public function destroy(Device $device)
    {
        $type = $device->deviceType();

        if ($type?->isCustomerEdge()) {
            try {
                $device->removeLanAccess();
            } catch (\Exception $e) {
                $device->delete();

                return back()->with('warning', 'Device record deleted, but router cleanup failed: ' . $e->getMessage());
            }
        }

        $device->delete();

        return back()->with('success', "Device removed from inventory.");
    }

    /**
     * Minimal parent/child tree for the topology view.
     */
    private function buildTopology(): array
    {
        $nodes = Device::orderBy('type')->orderBy('hostname')->orderBy('mac_address')
            ->get(['id', 'parent_device_id', 'type', 'hostname', 'mac_address', 'ip_address', 'is_online', 'client_id'])
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'parent_device_id' => $d->parent_device_id,
                'type' => $d->type,
                'type_label' => $d->type_label,
                'name' => $d->hostname ?: $d->mac_address,
                'ip_address' => $d->ip_address,
                'is_online' => $d->is_online,
                'client_id' => $d->client_id,
            ])
            ->keyBy('id');

        $children = [];
        $roots = [];

        foreach ($nodes as $id => $node) {
            if ($node['parent_device_id'] && $nodes->has($node['parent_device_id'])) {
                $children[$node['parent_device_id']][] = $id;
            } else {
                $roots[] = $id;
            }
        }

        $build = function (int $id) use (&$build, $nodes, $children): array {
            $node = $nodes[$id];
            $node['children'] = array_map($build, $children[$id] ?? []);

            return $node;
        };

        return array_map($build, $roots);
    }
}
