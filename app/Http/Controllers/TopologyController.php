<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Router;
use App\Services\Mikrotik\ConnectedDeviceService;
use App\Services\Mikrotik\RouterDataService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Whole-network topology: router -> L2 segments -> managed devices -> live
 * clients, as one parent-linked node graph the frontend lays out as a diagram.
 */
class TopologyController extends Controller
{
    /** Hard cap on live endpoint nodes so one busy router cannot flood the canvas. */
    private const MAX_ENDPOINTS = 50;

    public function index(
        Request $request,
        RouterDataService $routerData,
        ConnectedDeviceService $deviceService,
    ): Response {
        $graph = $this->buildGraph($routerData, $deviceService, 30);

        return Inertia::render('Topology/Index', [
            'nodes' => array_values($graph['nodes']),
            'stats' => $graph['stats'],
            'errors' => $graph['errors'],
            'fetchedAt' => now()->toIso8601String(),
            'filters' => $request->only(['kind']),
            'defaultPollSeconds' => 2,
        ]);
    }

    /**
     * Polled by the page: same graph, but the live sources are read on every
     * call (no 30s cache) so device presence and traffic update in near
     * real time. The L2 structure itself stays cached — it barely moves.
     */
    public function live(RouterDataService $routerData, ConnectedDeviceService $deviceService): JsonResponse
    {
        // The device scan is the expensive part (~2s for 266 records); a 3s
        // cache means fast poll intervals reuse it while interfaces and
        // traffic are still read fresh every time.
        $graph = $this->buildGraph($routerData, $deviceService, 3);

        return response()->json([
            'nodes' => array_values($graph['nodes']),
            'stats' => $graph['stats'],
            'errors' => $graph['errors'],
            'fetchedAt' => now()->toIso8601String(),
        ]);
    }

    private function buildGraph(
        RouterDataService $routerData,
        ConnectedDeviceService $deviceService,
        int $deviceTtl,
    ): array {
        $nodes = [];
        $errors = [];
        $deviceCounts = ['devices' => 0, 'endpoints' => 0, 'grouped' => 0, 'routers' => 0];
        $aggregateRates = ['rx' => 0.0, 'tx' => 0.0];

        $routers = Router::orderBy('id')->get();
        $l2ByRouter = [];
        $leasesByRouter = [];
        $linkNodeByRouter = [];
        $macIndex = [];

        foreach ($routers as $router) {
            $deviceCounts['routers']++;
            $routerId = 'router:' . $router->id;

            $snapshot = null;
            try {
                $snapshot = $routerData->snapshot($router);
            } catch (Exception $exception) {
                $errors[] = ['source' => $router->name, 'message' => $exception->getMessage()];
            }

            $health = $snapshot['health'] ?? null;
            $identity = $health['identity'] ?? ($router->identity ?: $router->name);

            $nodes[$routerId] = [
                'id' => $routerId,
                'parent' => null,
                'kind' => 'router',
                'label' => $identity,
                'sublabel' => $router->ip_address,
                'status' => ($snapshot['stale'] ?? false) ? 'stale' : ($router->is_online ? 'online' : 'offline'),
                'meta' => $this->compact([
                    'Identity' => $identity,
                    'Name' => $router->name,
                    'API endpoint' => $router->ip_address . ':' . $router->api_port . ($router->use_ssl ? ' (SSL)' : ''),
                    'Board' => $health['board_name'] ?? $router->board_name,
                    'RouterOS' => $health['version'] ?? $router->ros_version,
                    'CPU load' => ($health['cpu_load'] ?? $router->cpu_load) !== null
                        ? ($health['cpu_load'] ?? $router->cpu_load) . '%' : null,
                    'Memory' => isset($health['free_memory'], $health['total_memory'])
                        ? $this->humanBytes($health['free_memory']) . ' free / ' . $this->humanBytes($health['total_memory'])
                        : ($router->free_memory && $router->total_memory
                            ? $this->humanBytes($router->free_memory) . ' free / ' . $this->humanBytes($router->total_memory)
                            : null),
                    'Free disk' => isset($health['free_hdd']) ? $this->humanBytes($health['free_hdd']) : null,
                    'Uptime' => $health['uptime'] ?? $router->uptime,
                    'Last seen' => optional($router->last_seen_at)->format('Y-m-d H:i:s'),
                    'Status' => ($snapshot['stale'] ?? false) ? 'stale (cache)' : ($router->is_online ? 'online' : 'offline'),
                ]),
            ];

            // ---- L2 structure -------------------------------------------------
            $topology = null;
            try {
                $key = 'mikrotik:topology:' . $router->id;
                $topology = Cache::remember($key, now()->addSeconds(30), fn () => $router->service()->getTopologySources());
            } catch (Exception $exception) {
                $errors[] = ['source' => $router->name . ' (L2)', 'message' => $exception->getMessage()];
            }

            $rows = $topology['rows'] ?? [];
            $addressing = $this->addressing($rows);
            $addresses = $addressing['addresses'];

            // Retained for the address-ownership pass after every node exists.
            $leasesByRouter[$router->id] = $addressing['leases'];

            // Raw interface rows — bridges are interfaces too, so their MAC/MTU/
            // byte counters live here rather than in the bridge print.
            $ifaceRows = [];
            foreach ($rows['interfaces'] ?? [] as $iface) {
                $name = (string) ($iface['name'] ?? '');
                if ($name !== '') {
                    $ifaceRows[$name] = $iface;
                }
            }

            $bridgeIdByOsId = [];
            $bridgeIds = [];

            foreach ($rows['bridges'] ?? [] as $bridge) {
                $name = (string) ($bridge['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $id = 'bridge:' . $router->id . ':' . $name;
                $bridgeIdByOsId[$bridge['.id'] ?? $name] = $id;
                $bridgeIds[$name] = $id;

                $meta = $this->linkMeta($ifaceRows[$name] ?? null, $addresses[$name] ?? []);
                $meta['Protocol'] = $bridge['protocol-mode'] ?? 'rstp';
                $meta += $addressing['services'][$name] ?? [];

                $nodes[$id] = [
                    'id' => $id,
                    'parent' => $routerId,
                    'kind' => 'bridge',
                    'label' => $name,
                    'sublabel' => trim(
                        ($bridge['protocol-mode'] ?? 'rstp')
                        . ($addresses[$name][0] ?? null ? ' · ' . $addresses[$name][0] : ''),
                        ' · '
                    ),
                    'status' => ($bridge['disabled'] ?? 'false') === 'true' ? 'disabled' : 'online',
                    'meta' => $meta,
                ];
            }

            // Which interface is swallowed by which bridge. Some RouterOS builds
            // report the bridge name in `bridge=`, others the .id — accept both.
            $portParent = [];
            $portsByBridge = [];
            foreach ($rows['bridge_ports'] ?? [] as $port) {
                $bridgeNode = $bridgeIds[(string) ($port['bridge'] ?? '')]
                    ?? $bridgeIdByOsId[(string) ($port['bridge'] ?? '')]
                    ?? null;
                if ($bridgeNode && !empty($port['interface'])) {
                    $portParent[(string) $port['interface']] = $bridgeNode;
                    $portsByBridge[$bridgeNode][] = (string) $port['interface'];
                }
            }

            foreach ($portsByBridge as $bridgeNode => $ports) {
                if (isset($nodes[$bridgeNode])) {
                    $nodes[$bridgeNode]['sublabel'] .= ' · ' . count($ports) . ' port(s)';
                    $nodes[$bridgeNode]['meta']['Ports'] = implode(', ', $ports);
                }
            }

            $interfaceNames = [];
            foreach ($rows['interfaces'] ?? [] as $iface) {
                $name = (string) ($iface['name'] ?? '');
                if ($name === '' || $name === 'lo' || isset($bridgeIds[$name])) {
                    continue;
                }

                $interfaceNames[$name] = true;
                $id = 'iface:' . $router->id . ':' . $name;

                $meta = $this->linkMeta($iface, $addresses[$name] ?? []);
                if (isset($portParent[$name])) {
                    $meta['Bridge'] = substr($portParent[$name], strlen('bridge:' . $router->id . ':'));
                }
                $meta += $addressing['services'][$name] ?? [];

                $running = $this->isTrue($iface['running'] ?? 'false');
                $nodes[$id] = [
                    'id' => $id,
                    'parent' => $portParent[$name] ?? $routerId,
                    'kind' => 'interface',
                    'label' => $name,
                    'sublabel' => trim(
                        ($iface['type'] ?? 'ether')
                        . ($running ? ' · up' : ' · down')
                        . ($addresses[$name][0] ?? null ? ' · ' . $addresses[$name][0] : ''),
                        ' · '
                    ),
                    'status' => ($iface['disabled'] ?? 'false') === 'true'
                        ? 'disabled'
                        : ($running ? 'online' : 'offline'),
                    'meta' => $meta,
                ];
            }

            // Retained so managed devices can be hung off the right segment
            // after every router's L2 structure has been read.
            $linkNodes = [];
            foreach ($bridgeIds as $name => $bridgeNode) {
                $linkNodes[$name] = $bridgeNode;
            }
            foreach (array_keys($interfaceNames) as $name) {
                $linkNodes[$name] = 'iface:' . $router->id . ':' . $name;
            }
            $linkNodeByRouter[$router->id] = $linkNodes;

            $l2ByRouter[$router->id] = [
                'router_id' => $routerId,
                'interface_names' => $interfaceNames,
                'bridge_ids' => $bridgeIds,
                'port_parent' => $portParent,
            ];

            // ---- Live endpoints ------------------------------------------------
            $endpoints = [];
            try {
                $endpoints = $deviceService->snapshot($router, $deviceTtl);
            } catch (Exception $exception) {
                $errors[] = ['source' => $router->name . ' (live)', 'message' => $exception->getMessage()];
            }

            $candidates = [];
            foreach ($endpoints as $endpoint) {
                if (!empty($endpoint['managed_device_id'])) {
                    continue;
                }
                if (($endpoint['mac_address'] ?? '') === '') {
                    continue;
                }
                $candidates[] = $endpoint;
            }

            $deviceCounts['endpoints'] = count($candidates);
            $visible = array_slice($candidates, 0, self::MAX_ENDPOINTS);
            $grouped = max(0, count($candidates) - count($visible));

            foreach ($visible as $endpoint) {
                $iface = (string) ($endpoint['interface'] ?? '');
                $parent = null;

                if ($iface !== '') {
                    $parent = $portParent[$iface]
                        ?? $bridgeIds[$iface]
                        ?? (isset($interfaceNames[$iface]) ? 'iface:' . $router->id . ':' . $iface : null);
                }

                if (!$parent || !isset($nodes[$parent])) {
                    $parent = $routerId;
                }

                $id = 'live:' . $router->id . ':' . strtolower($endpoint['mac_address']);
                $nodes[$id] = [
                    'id' => $id,
                    'parent' => $parent,
                    'kind' => 'endpoint',
                    'label' => $endpoint['hostname']
                        ?: ($endpoint['client_name'] ?? null)
                        ?: ($endpoint['user'] ?? null)
                        ?: strtoupper($endpoint['mac_address']),
                    'sublabel' => trim(
                        ($endpoint['ip_address'] ? $endpoint['ip_address'] : '')
                        . ($endpoint['user'] ? ' · ' . $endpoint['user'] : ''),
                        ' · '
                    ),
                    'status' => $endpoint['status'] ?? 'offline',
                    'meta' => $this->compact([
                        'MAC' => strtoupper($endpoint['mac_address']),
                        'IPv4' => $endpoint['ip_address'] ?? null,
                        'Hostname' => $endpoint['hostname'] ?? null,
                        'Client' => $endpoint['client_name'] ?? null,
                        'Hotspot user' => $endpoint['user'] ?? null,
                        'Login by' => $endpoint['login_by'] ?? null,
                        'Interface' => $endpoint['interface'] ?? null,
                        'Seen via' => !empty($endpoint['sources']) ? implode(', ', $endpoint['sources']) : null,
                        'Session uptime' => $endpoint['session_uptime'] ?? null,
                        'Session time left' => $endpoint['session_time_left'] ?? null,
                        'Idle time' => $endpoint['idle_time'] ?? null,
                        'Bytes in' => !empty($endpoint['bytes_in']) ? $this->humanBytes($endpoint['bytes_in']) : null,
                        'Bytes out' => !empty($endpoint['bytes_out']) ? $this->humanBytes($endpoint['bytes_out']) : null,
                        'Packets in' => !empty($endpoint['packets_in']) ? $endpoint['packets_in'] : null,
                        'Packets out' => !empty($endpoint['packets_out']) ? $endpoint['packets_out'] : null,
                        'ARP status' => $endpoint['arp_status'] ?? null,
                        'ARP complete' => isset($endpoint['arp_complete']) ? $this->boolLabel($endpoint['arp_complete']) : null,
                        'DHCP server' => $endpoint['dhcp_server'] ?? null,
                        'DHCP status' => $endpoint['dhcp_status'] ?? null,
                        'DHCP expires' => $endpoint['dhcp_expires'] ?? null,
                        'DHCP last seen' => $endpoint['dhcp_last_seen'] ?? null,
                        'Rate limit' => $endpoint['dhcp_rate_limit'] ?? null,
                        'Hotspot authorized' => isset($endpoint['host_authorized']) ? $this->boolLabel($endpoint['host_authorized']) : null,
                        'Hotspot bypassed' => isset($endpoint['host_bypassed']) ? $this->boolLabel($endpoint['host_bypassed']) : null,
                        'Host uptime' => $endpoint['host_uptime'] ?? null,
                        'Found by' => $endpoint['host_found_by'] ?? null,
                        'PPPoE service' => $endpoint['ppp_service'] ?? null,
                        'PPPoE remote address' => $endpoint['ppp_remote_address'] ?? null,
                        'Neighbor identity' => $endpoint['neighbor_identity'] ?? null,
                        'Neighbor platform' => $endpoint['neighbor_platform'] ?? null,
                        'Blocked' => !empty($endpoint['blocked']) ? 'yes' : null,
                        'Category' => $endpoint['typology_label'] ?? null,
                        'Access expires' => $endpoint['access_expires_at'] ?? null,
                    ]),
                ];

                $macIndex[strtolower($endpoint['mac_address'])] = [
                    'label' => $nodes[$id]['label'],
                    'kind' => 'endpoint',
                    'ip' => $endpoint['ip_address'] ?? null,
                ];
            }

            if ($grouped > 0) {
                $deviceCounts['grouped'] = $grouped;
                $nodes['live:' . $router->id . ':more'] = [
                    'id' => 'live:' . $router->id . ':more',
                    'parent' => $routerId,
                    'kind' => 'endpoint',
                    'label' => "+{$grouped} more live device(s)",
                    'sublabel' => 'not drawn individually',
                    'status' => 'muted',
                    'meta' => [],
                ];
            }

            $routerRates = $this->attachTraffic($nodes, $router, $candidates);
            $aggregateRates['rx'] += $routerRates['rx'];
            $aggregateRates['tx'] += $routerRates['tx'];
        }

        // ---- Managed inventory devices --------------------------------------
        $devices = Device::with('client:id,name')->orderBy('id')->get();
        $deviceLabels = $devices->mapWithKeys(
            fn ($d) => [$d->id => $d->hostname ?: strtoupper((string) $d->mac_address)]
        );
        $routerNames = $routers->mapWithKeys(fn ($r) => [$r->id => $r->name]);

        foreach ($devices as $device) {
            $deviceCounts['devices']++;
            $l2 = $l2ByRouter[$device->router_id] ?? null;
            $id = 'device:' . $device->id;
            $parent = null;

            if ($device->parent_device_id) {
                $parent = 'device:' . $device->parent_device_id;
            } elseif ($l2 && $device->interface) {
                $parent = $l2['port_parent'][$device->interface]
                    ?? $l2['bridge_ids'][$device->interface]
                    ?? (isset($l2['interface_names'][$device->interface])
                        ? 'iface:' . $device->router_id . ':' . $device->interface
                        : null);
            }

            if (!$parent) {
                $parent = $l2['router_id'] ?? null;
            }

            $nodes[$id] = [
                'id' => $id,
                'parent' => $parent,
                'kind' => 'device',
                'label' => $deviceLabels[$device->id] ?? $id,
                'sublabel' => trim(
                    ($device->ip_address ? $device->ip_address . ' · ' : '')
                    . ($device->type ?: 'device')
                    . ($device->client?->name ? ' · ' . $device->client->name : ''),
                    ' · '
                ),
                'status' => $device->is_online ? 'online' : ($device->is_active ? 'offline' : 'disabled'),
                'meta' => $this->compact([
                    'MAC' => strtoupper((string) $device->mac_address),
                    'IPv4' => $device->ip_address,
                    'IPv6' => $device->ipv6_address,
                    'Hostname' => $device->hostname,
                    'Client' => $device->client?->name,
                    'Type' => $device->type,
                    'Serial' => $device->serial_number,
                    'Model' => $device->model,
                    'Manufacturer' => $device->manufacturer,
                    'Firmware' => $device->firmware_version,
                    'Interface' => $device->interface,
                    'Management IP' => $device->management_ip,
                    'Router' => $routerNames[$device->router_id] ?? null,
                    'Parent device' => $device->parent_device_id
                        ? ($deviceLabels[$device->parent_device_id] ?? '#' . $device->parent_device_id)
                        : null,
                    'Active' => $this->boolLabel($device->is_active),
                    'Online' => $this->boolLabel($device->is_online),
                    'Last seen' => optional($device->last_seen_at)->format('Y-m-d H:i:s'),
                    'Signal' => $device->signal_strength !== null ? $device->signal_strength . ' dBm' : null,
                    'Device uptime' => $device->uptime_seconds
                        ? $this->humanDuration($device->uptime_seconds)
                        : null,
                    'Access duration' => $device->access_duration_days
                        ? $device->access_duration_days . ' day(s)'
                        : null,
                    'Access expires' => optional($device->access_expires_at)->format('Y-m-d H:i:s'),
                    'Comment' => $device->comment,
                    'Notes' => $device->notes,
                ]),
            ];

            $macIndex[strtolower((string) $device->mac_address)] = [
                'label' => $deviceLabels[$device->id],
                'kind' => 'device',
                'ip' => $device->ip_address,
            ];
        }

        // ---- Address ownership: which device holds which address ----------
        foreach ($leasesByRouter as $routerKey => $leasesByIface) {
            foreach ($leasesByIface as $ifaceName => $leases) {
                $nodeId = $linkNodeByRouter[$routerKey][$ifaceName] ?? null;
                if (!$nodeId || !isset($nodes[$nodeId])) {
                    continue;
                }

                $shown = [];
                foreach ($leases as $lease) {
                    $mac = strtolower((string) ($lease['mac-address'] ?? ''));
                    $known = $macIndex[$mac] ?? null;
                    $holder = $known
                        ? $known['label'] . ' (' . strtoupper($mac) . ')'
                        : strtoupper($mac);

                    $shown[] = ($lease['address'] ?? '') . ' — ' . $holder
                        . (($lease['status'] ?? '') !== '' ? ' [' . $lease['status'] . ']' : '');
                }

                $nodes[$nodeId]['meta']['DHCP leases'] = $this->capList($shown, 25);
            }
        }

        // Every device under a link, with the addresses it holds.
        $childrenOf = [];
        foreach ($nodes as $node) {
            if ($node['parent'] !== null) {
                $childrenOf[$node['parent']][] = $node['id'];
            }
        }

        $descendants = function (string $id) use (&$descendants, $childrenOf): array {
            $found = [];
            foreach ($childrenOf[$id] ?? [] as $child) {
                $found[] = $child;
                $found = array_merge($found, $descendants($child));
            }

            return $found;
        };

        foreach (array_keys($nodes) as $id) {
            if (!in_array($nodes[$id]['kind'], ['bridge', 'interface'], true)) {
                continue;
            }

            $holders = [];
            foreach ($descendants($id) as $descendantId) {
                $descendant = $nodes[$descendantId];
                $ip = $descendant['meta']['IPv4'] ?? null;
                if ($ip === null || $ip === '' || $ip === 'none assigned') {
                    continue;
                }

                $holders[] = (is_array($ip) ? implode(', ', $ip) : $ip)
                    . ' — ' . $descendant['label']
                    . (($descendant['meta']['MAC'] ?? null) ? ' (' . $descendant['meta']['MAC'] . ')' : '')
                    . ' [' . $descendant['kind'] . ']';
            }

            if ($holders) {
                $nodes[$id]['meta']['Devices with addresses'] = $this->capList($holders, 25);
            }
        }

        // Drop edges that point at nodes which never materialised.
        foreach ($nodes as $id => $node) {
            if ($node['parent'] !== null && !isset($nodes[$node['parent']])) {
                $nodes[$id]['parent'] = null;
            }
        }

        $stats = [
            'routers' => $deviceCounts['routers'],
            'bridges' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'bridge')),
            'interfaces' => count(array_filter($nodes, fn ($n) => $n['kind'] === 'interface')),
            'devices' => $deviceCounts['devices'],
            'endpoints' => $deviceCounts['endpoints'],
            'online' => count(array_filter($nodes, fn ($n) => $n['status'] === 'online')),
            'offline' => count(array_filter($nodes, fn ($n) => $n['status'] === 'offline')),
            'rx_rate' => $this->humanRate($aggregateRates['rx']),
            'tx_rate' => $this->humanRate($aggregateRates['tx']),
        ];

        return ['nodes' => $nodes, 'stats' => $stats, 'errors' => $errors];
    }

    /**
     * Stamp live byte totals and per-second rates onto interface, bridge and
     * endpoint nodes, comparing each sample with the previous one in cache.
     */
    private function attachTraffic(array &$nodes, Router $router, array $endpoints): array
    {
        $totals = ['rx' => 0.0, 'tx' => 0.0];
        $sampleKey = "mikrotik:rate-sample:{$router->id}";
        $previous = Cache::get($sampleKey);
        $now = microtime(true);
        $elapsed = isset($previous['at']) ? max(0.2, $now - (float) $previous['at']) : null;

        $sample = ['at' => $now, 'if' => [], 'hosts' => []];

        try {
            $counters = $router->service()->getInterfaceCounters();
        } catch (Exception $exception) {
            return $totals;
        }

        $ratePairs = [];

        foreach ($counters as $row) {
            $name = (string) ($row['name'] ?? '');
            if ($name === '') {
                continue;
            }

            $rx = (int) ($row['rx-byte'] ?? 0);
            $tx = (int) ($row['tx-byte'] ?? 0);
            $sample['if'][$name] = ['rx' => $rx, 'tx' => $tx];

            foreach (['bridge', 'iface'] as $kind) {
                $id = "{$kind}:{$router->id}:{$name}";
                if (!isset($nodes[$id])) {
                    continue;
                }

                $nodes[$id]['meta']['RX'] = $this->humanBytes($rx);
                $nodes[$id]['meta']['TX'] = $this->humanBytes($tx);

                $previousBytes = $previous['if'][$name] ?? null;
                if ($previousBytes === null || $elapsed === null) {
                    continue;
                }

                $ratePairs[$id] = [
                    'rx' => max(0, $rx - (int) $previousBytes['rx']) / $elapsed,
                    'tx' => max(0, $tx - (int) $previousBytes['tx']) / $elapsed,
                ];

                // Only plain interfaces feed the totals: a bridge's own
                // counters are re-summed from its ports below.
                if ($kind === 'iface') {
                    $totals['rx'] += $ratePairs[$id]['rx'];
                    $totals['tx'] += $ratePairs[$id]['tx'];
                }
            }
        }

        // RouterOS only counts BPDUs on a bridge's own counters — report the
        // sum of its ports instead, which is what actually crosses it.
        $children = [];
        foreach ($nodes as $node) {
            if ($node['parent'] !== null) {
                $children[$node['parent']][] = $node['id'];
            }
        }

        foreach ($children as $parentId => $childIds) {
            if (($nodes[$parentId]['kind'] ?? '') !== 'bridge') {
                continue;
            }

            $sumRx = 0;
            $sumTx = 0;
            $rateRx = 0.0;
            $rateTx = 0.0;
            $hasRate = false;

            foreach ($childIds as $childId) {
                if (($nodes[$childId]['kind'] ?? '') !== 'interface') {
                    continue;
                }

                $sumRx += (int) ($sample['if'][$nodes[$childId]['label']]['rx'] ?? 0);
                $sumTx += (int) ($sample['if'][$nodes[$childId]['label']]['tx'] ?? 0);

                if (isset($ratePairs[$childId])) {
                    $hasRate = true;
                    $rateRx += $ratePairs[$childId]['rx'];
                    $rateTx += $ratePairs[$childId]['tx'];
                }
            }

            $nodes[$parentId]['meta']['RX'] = $this->humanBytes($sumRx);
            $nodes[$parentId]['meta']['TX'] = $this->humanBytes($sumTx);

            if ($hasRate) {
                $ratePairs[$parentId] = ['rx' => $rateRx, 'tx' => $rateTx];
            }
        }

        foreach ($ratePairs as $id => $pair) {
            $nodes[$id]['meta']['RX rate'] = $this->humanRate($pair['rx']);
            $nodes[$id]['meta']['TX rate'] = $this->humanRate($pair['tx']);
        }

        foreach ($endpoints as $endpoint) {
            $mac = strtolower((string) ($endpoint['mac_address'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $in = (int) ($endpoint['bytes_in'] ?? 0);
            $out = (int) ($endpoint['bytes_out'] ?? 0);
            $sample['hosts'][$mac] = ['in' => $in, 'out' => $out];

            $id = 'live:' . $router->id . ':' . $mac;
            if (!isset($nodes[$id])) {
                continue;
            }

            $nodes[$id]['meta']['Traffic in'] = $this->humanBytes($in);
            $nodes[$id]['meta']['Traffic out'] = $this->humanBytes($out);

            $previousHost = $previous['hosts'][$mac] ?? null;
            if ($previousHost === null || $elapsed === null) {
                continue;
            }

            $nodes[$id]['meta']['Rate in'] = $this->humanRate(max(0, $in - (int) $previousHost['in']) / $elapsed);
            $nodes[$id]['meta']['Rate out'] = $this->humanRate(max(0, $out - (int) $previousHost['out']) / $elapsed);
        }

        Cache::put($sampleKey, $sample, now()->addMinutes(5));

        return $totals;
    }

    private function humanRate(float $bytesPerSecond): string
    {
        return $this->humanBytes($bytesPerSecond) . '/s';
    }

    /**
     * Addressing picture for one router: addresses owned by the router per
     * interface, DHCP pools/servers/networks, hotspot servers and the leases
     * those servers handed out. This is what lets a bridge with no address of
     * its own still show what lives behind it.
     */
    private function addressing(array $rows): array
    {
        $addrRows = [];
        $addresses = [];

        foreach ($rows['addresses'] ?? [] as $addressRow) {
            $ifaceName = (string) ($addressRow['interface'] ?? '');
            if ($ifaceName === '' || $this->isTrue($addressRow['disabled'] ?? 'false')) {
                continue;
            }

            $network = (string) ($addressRow['network'] ?? '');
            $addrRows[$ifaceName][] = [
                'address' => (string) ($addressRow['address'] ?? ''),
                'network' => $network,
            ];
            $addresses[$ifaceName][] = ($addressRow['address'] ?? '')
                . ($network !== '' ? ' (' . $network . ')' : '');
        }

        $pools = [];
        foreach ($rows['pools'] ?? [] as $pool) {
            $name = (string) ($pool['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $pools[$name] = trim(
                ($pool['ranges'] ?? '')
                . (($pool['used'] ?? null) !== null ? ' · ' . $pool['used'] . '/' . ($pool['total'] ?? '?') . ' used' : ''),
                ' · '
            );
        }

        $serverIface = [];
        $services = [];

        foreach ($rows['dhcp_servers'] ?? [] as $server) {
            $serverName = (string) ($server['name'] ?? '');
            $ifaceName = (string) ($server['interface'] ?? '');
            if ($serverName !== '') {
                $serverIface[$serverName] = $ifaceName;
            }
            if ($ifaceName === '') {
                continue;
            }

            $poolName = (string) ($server['address-pool'] ?? '');
            $services[$ifaceName]['DHCP servers'][] = trim(
                $serverName
                . ($poolName !== '' ? ' · pool ' . $poolName : '')
                . (($server['lease-time'] ?? '') !== '' ? ' · lease ' . $server['lease-time'] : '')
                . ($this->isTrue($server['disabled'] ?? 'false') ? ' · disabled' : ''),
                ' · '
            );

            if ($poolName !== '' && isset($pools[$poolName])) {
                $services[$ifaceName]['Address pools'][] = $poolName . ': ' . $pools[$poolName];
            }
        }

        foreach ($rows['dhcp_networks'] ?? [] as $network) {
            $base = explode('/', (string) ($network['address'] ?? ''))[0];
            if ($base === '') {
                continue;
            }

            foreach ($addrRows as $ifaceName => $rowsForIface) {
                foreach ($rowsForIface as $addressRow) {
                    // A DHCP network belongs to whichever interface carries an
                    // address in the same L3 network.
                    if ($addressRow['network'] !== $base) {
                        continue;
                    }

                    $services[$ifaceName]['DHCP networks'][] = trim(
                        ($network['address'] ?? '')
                        . (($network['gateway'] ?? '') !== '' ? ' · gw ' . $network['gateway'] : '')
                        . (($network['dns-server'] ?? '') !== '' ? ' · dns ' . $network['dns-server'] : ''),
                        ' · '
                    );
                }
            }
        }

        foreach ($rows['hotspot_servers'] ?? [] as $hotspot) {
            $ifaceName = (string) ($hotspot['interface'] ?? '');
            if ($ifaceName === '') {
                continue;
            }

            $poolName = (string) ($hotspot['address-pool'] ?? '');
            $services[$ifaceName]['Hotspot servers'][] = trim(
                ($hotspot['name'] ?? '')
                . (($hotspot['profile'] ?? '') !== '' ? ' · profile ' . $hotspot['profile'] : '')
                . ($poolName !== '' ? ' · pool ' . $poolName : '')
                . (($hotspot['addresses-per-mac'] ?? '') !== '' ? ' · ' . $hotspot['addresses-per-mac'] . ' addr/mac' : '')
                . (($hotspot['idle-timeout'] ?? '') !== '' ? ' · idle ' . $hotspot['idle-timeout'] : '')
                . ($this->isTrue($hotspot['disabled'] ?? 'false') ? ' · disabled' : ''),
                ' · '
            );

            if ($poolName !== '' && isset($pools[$poolName])) {
                $services[$ifaceName]['Address pools'][] = $poolName . ': ' . $pools[$poolName];
            }
        }

        foreach ($services as $ifaceName => $groups) {
            foreach ($groups as $key => $lines) {
                $services[$ifaceName][$key] = array_values(array_unique($lines));
            }
        }

        $leases = [];
        foreach ($rows['dhcp_leases'] ?? [] as $lease) {
            $ifaceName = $serverIface[(string) ($lease['server'] ?? '')] ?? '';
            if ($ifaceName === '' || ($lease['address'] ?? '') === '') {
                continue;
            }
            $leases[$ifaceName][] = $lease;
        }

        return [
            'addresses' => $addresses,
            'services' => $services,
            'leases' => $leases,
        ];
    }

    /** Keep a long list readable without hiding how many were left out. */
    private function capList(array $items, int $limit): array
    {
        if (count($items) <= $limit) {
            return $items;
        }

        return array_merge(
            array_slice($items, 0, $limit),
            ['+' . (count($items) - $limit) . ' more']
        );
    }

    /** Detail block shared by bridges and plain interfaces. */
    private function linkMeta(?array $row, array $addresses): array
    {
        $row = $row ?: [];
        $mtu = $row['mtu'] ?? null;

        return $this->compact([
            'MAC' => $row['mac-address'] ?? null,
            // Say so explicitly: an empty bridge is otherwise indistinguishable
            // from one whose details failed to load.
            'IPv4' => $addresses ?: 'none assigned',
            'Type' => $row['type'] ?? null,
            'MTU' => $mtu !== null
                ? $mtu . ((int) ($row['actual-mtu'] ?? 0) > 0 && (string) $row['actual-mtu'] !== (string) $mtu
                    ? ' (actual ' . $row['actual-mtu'] . ')'
                    : '')
                : null,
            'Driver' => $row['driver-name'] ?? null,
            'State' => isset($row['running']) ? ($this->isTrue($row['running']) ? 'up' : 'down') : null,
            'Disabled' => isset($row['disabled']) && $this->isTrue($row['disabled']) ? 'yes' : null,
            'Comment' => $row['comment'] ?? null,
            'RX' => isset($row['rx-byte']) ? $this->humanBytes($row['rx-byte']) : null,
            'TX' => isset($row['tx-byte']) ? $this->humanBytes($row['tx-byte']) : null,
        ]);
    }

    /** Drop keys that carry nothing so the detail panel stays readable. */
    private function compact(array $meta): array
    {
        return array_filter($meta, fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function isTrue($value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower((string) $value), ['true', 'yes', '1'], true);
    }

    private function boolLabel($value): string
    {
        return $this->isTrue($value) ? 'yes' : 'no';
    }

    private function humanBytes($bytes): ?string
    {
        if ($bytes === null || $bytes === '' || !is_numeric($bytes)) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $index = 0;

        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }

        return round($value, $index === 0 ? 0 : 1) . ' ' . $units[$index];
    }

    private function humanDuration(int $seconds): string
    {
        $parts = [];
        $map = [
            'd' => intdiv($seconds, 86400),
            'h' => intdiv($seconds % 86400, 3600),
            'm' => intdiv($seconds % 3600, 60),
            's' => $seconds % 60,
        ];

        foreach ($map as $suffix => $value) {
            if (!$parts && $value === 0) {
                continue;
            }
            $parts[] = $value . $suffix;
        }

        return implode(' ', $parts) ?: '0s';
    }
}
