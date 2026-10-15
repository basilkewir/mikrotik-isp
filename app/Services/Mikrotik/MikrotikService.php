<?php

namespace App\Services\Mikrotik;

use App\Models\Device;
use App\Models\Router;
use Exception;
use Illuminate\Support\Facades\Log;

class MikrotikService
{
    protected RouterOsProtocol $client;
    protected ?Router $router = null;

    public function __construct(?Router $router = null)
    {
        $this->client = new RouterOsProtocol(timeout: 4);

        if ($router) {
            $this->forRouter($router);
        }
    }

    /**
     * Set target router and establish connection.
     */
    public function forRouter(Router $router): self
    {
        $this->router = $router;
        $this->connect();
        return $this;
    }

    /**
     * Connect to the configured router.
     */
    public function connect(): bool
    {
        if (!$this->router) {
            throw new Exception("Router instance not assigned.");
        }

        return $this->client->connect(
            ip: $this->router->ip_address,
            username: $this->router->api_username,
            password: $this->router->api_password, // decrypted via model cast
            port: $this->router->api_port ?? 8728,
            useSsl: $this->router->use_ssl ?? false
        );
    }

    /**
     * Check connection and fetch router health metrics.
     */
    public function checkHealth(): array
    {
        $resources = $this->client->command('/system/resource/print');
        $identity = $this->client->command('/system/identity/print');

        $res = $resources[0] ?? [];
        $id = $identity[0]['name'] ?? 'MikroTik';

        return [
            'online' => true,
            'identity' => $id,
            'uptime' => $res['uptime'] ?? 'unknown',
            'version' => $res['version'] ?? 'unknown',
            'board_name' => $res['board-name'] ?? ($res['platform'] ?? 'RouterBOARD'),
            'cpu_load' => (int) ($res['cpu-load'] ?? 0),
            'free_memory' => (int) ($res['free-memory'] ?? 0),
            'total_memory' => (int) ($res['total-memory'] ?? 0),
            'free_hdd' => (int) ($res['free-hdd-space'] ?? 0),
        ];
    }

    /**
     * Fetch RouterOS accounts without requesting or exposing stored passwords.
     */
    public function getRouterSnapshot(): array
    {
        return [
            'health' => $this->checkHealth(),
            'hotspot_users' => $this->client->command('/ip/hotspot/user/print', [
                '=.proplist=name,profile,disabled,comment,limit-uptime,limit-bytes-total,bytes-in,bytes-out',
            ]),
            'hotspot_sessions' => $this->getActiveHotspotSessions(),
            'pppoe_users' => array_values(array_filter(
                $this->client->command('/ppp/secret/print', [
                    '=.proplist=name,service,profile,disabled,remote-address,comment',
                ]),
                fn (array $user): bool => in_array($user['service'] ?? 'any', ['any', 'pppoe'], true),
            )),
            'pppoe_sessions' => $this->getActivePppoeSessions(),
        ];
    }

    // ==========================================
    // ROUTER PLANS / PROFILES
    // ==========================================

    /**
     * Fetch all Hotspot and PPPoE profiles ("plans") configured on the router.
     */
    public function getRouterProfiles(): array
    {
        return [
            'hotspot_profiles' => $this->client->command('/ip/hotspot/profile/print'),
            'pppoe_profiles' => $this->client->command('/ppp/profile/print'),
        ];
    }

    /**
     * Fetch every live device data source from RouterOS.
     *
     * Each source fails independently so a single unavailable table
     * (e.g. PPP disabled) never breaks the whole snapshot.
     */
    public function getConnectedDeviceSources(): array
    {
        $sources = [
            'arp' => '/ip/arp/print',
            'dhcp' => '/ip/dhcp-server/lease/print',
            'hotspot_active' => '/ip/hotspot/active/print',
            'hotspot_hosts' => '/ip/hotspot/host/print',
            'ppp_active' => '/ppp/active/print',
            'neighbors' => '/ip/neighbor/print',
        ];

        $rows = [];
        $errors = [];

        foreach ($sources as $key => $command) {
            try {
                $rows[$key] = $this->client->command($command);
            } catch (Exception $exception) {
                $rows[$key] = [];
                $errors[$key] = $exception->getMessage();
            }
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    // ==========================================
    // HOTSPOT MANAGEMENT
    // ==========================================

    /**
     * Provision a Hotspot User in MikroTik.
     */
    public function createOrUpdateHotspotUser(
        string $username,
        string $password,
        string $profile = 'default',
        ?string $comment = null,
        ?string $limitUptime = null,
        ?int $limitBytesTotal = null
    ): array {
        $existing = $this->client->command('/ip/hotspot/user/print', [
            '?name=' . $username,
        ]);

        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile,
            '=disabled=no',
        ];

        if ($comment !== null) {
            $params[] = '=comment=' . $comment;
        }
        if ($limitUptime !== null) {
            $params[] = '=limit-uptime=' . $limitUptime;
        }
        if ($limitBytesTotal !== null && $limitBytesTotal > 0) {
            $params[] = '=limit-bytes-total=' . $limitBytesTotal;
        }

        if (!empty($existing)) {
            $id = $existing[0]['.id'];
            $params[] = '=.id=' . $id;

            if ($limitUptime === null) {
                // Access duration is calendar-based and enforced by the application
                // clock (expires_at). Clear any stale RouterOS hour-based limit so a
                // session can never be counted as "uptime hours" instead of dates.
                $params[] = '=limit-uptime=';
            }

            return $this->client->command('/ip/hotspot/user/set', $params);
        }

        return $this->client->command('/ip/hotspot/user/add', $params);
    }

    /**
     * Disable Hotspot User and disconnect active session.
     */
    public function disableHotspotUser(string $username): void
    {
        $users = $this->client->command('/ip/hotspot/user/print', ['?name=' . $username]);
        if (!empty($users)) {
            $this->client->command('/ip/hotspot/user/set', [
                '=.id=' . $users[0]['.id'],
                '=disabled=yes',
            ]);
        }

        $this->kickHotspotActive($username);
    }

    /**
     * Enable Hotspot User.
     */
    public function enableHotspotUser(string $username): void
    {
        $users = $this->client->command('/ip/hotspot/user/print', ['?name=' . $username]);
        if (!empty($users)) {
            $this->client->command('/ip/hotspot/user/set', [
                '=.id=' . $users[0]['.id'],
                '=disabled=no',
            ]);
        }
    }

    /**
     * Permanently remove a Hotspot User and terminate any active session.
     */
    public function removeHotspotUser(string $username): void
    {
        $users = $this->client->command('/ip/hotspot/user/print', ['?name=' . $username]);
        foreach ($users as $user) {
            if (isset($user['.id'])) {
                $this->client->command('/ip/hotspot/user/remove', ['=.id=' . $user['.id']]);
            }
        }

        $this->kickHotspotActive($username);
    }

    /**
     * Kick active hotspot session by username.
     */
    public function kickHotspotActive(string $username): int
    {
        $activeSessions = $this->client->command('/ip/hotspot/active/print', ['?user=' . $username]);
        $kicked = 0;

        foreach ($activeSessions as $session) {
            if (isset($session['.id'])) {
                $this->client->command('/ip/hotspot/active/remove', ['=.id=' . $session['.id']]);
                $kicked++;
            }
        }

        return $kicked;
    }

    /**
     * Get list of active hotspot sessions.
     */
    public function getActiveHotspotSessions(): array
    {
        return $this->client->command('/ip/hotspot/active/print');
    }

    /**
     * Get user statistics (bytes in, bytes out, uptime) from /ip/hotspot/user.
     */
    public function getHotspotUserStats(string $username): ?array
    {
        $users = $this->client->command('/ip/hotspot/user/print', ['?name=' . $username]);
        return $users[0] ?? null;
    }

    /**
     * List every hotspot user configured on the router (the subscriber database).
     *
     * @return array<int, array>
     */
    public function listHotspotUsers(): array
    {
        return $this->client->command('/ip/hotspot/user/print', [
            '=.proplist=.id,name,password,profile,server,comment,disabled,limit-uptime,total-bytes-in,total-bytes-out,last-logged-out',
        ]);
    }

    /**
     * List every PPPoE secret configured on the router.
     *
     * @return array<int, array>
     */
    public function listPppoeSecrets(): array
    {
        return $this->client->command('/ppp/secret/print', [
            '=.proplist=.id,name,password,profile,service,remote-address,comment,disabled,routes,limit-bytes-in,limit-bytes-out',
        ]);
    }

    // ==========================================
    // PPPoE MANAGEMENT
    // ==========================================

    /**
     * Provision or update PPPoE Secret.
     */
    public function createOrUpdatePppoeSecret(
        string $username,
        string $password,
        string $profile = 'default',
        ?string $remoteAddress = null,
        ?string $comment = null
    ): array {
        $existing = $this->client->command('/ppp/secret/print', [
            '?name=' . $username,
        ]);

        $params = [
            '=name=' . $username,
            '=password=' . $password,
            '=profile=' . $profile,
            '=service=pppoe',
            '=disabled=no',
        ];

        if ($remoteAddress) {
            $params[] = '=remote-address=' . $remoteAddress;
        }

        if ($comment !== null) {
            $params[] = '=comment=' . $comment;
        }

        if (!empty($existing)) {
            $id = $existing[0]['.id'];
            $params[] = '=.id=' . $id;
            return $this->client->command('/ppp/secret/set', $params);
        }

        return $this->client->command('/ppp/secret/add', $params);
    }

    /**
     * Disable PPPoE Secret and kick active connection.
     */
    public function disablePppoeSecret(string $username): void
    {
        $secrets = $this->client->command('/ppp/secret/print', ['?name=' . $username]);
        if (!empty($secrets)) {
            $this->client->command('/ppp/secret/set', [
                '=.id=' . $secrets[0]['.id'],
                '=disabled=yes',
            ]);
        }

        $this->kickPppoeActive($username);
    }

    /**
     * Enable PPPoE Secret.
     */
    public function enablePppoeSecret(string $username): void
    {
        $secrets = $this->client->command('/ppp/secret/print', ['?name=' . $username]);
        if (!empty($secrets)) {
            $this->client->command('/ppp/secret/set', [
                '=.id=' . $secrets[0]['.id'],
                '=disabled=no',
            ]);
        }
    }

    /**
     * Permanently remove a PPPoE Secret and terminate any active session.
     */
    public function removePppoeSecret(string $username): void
    {
        $secrets = $this->client->command('/ppp/secret/print', ['?name=' . $username]);
        foreach ($secrets as $secret) {
            if (isset($secret['.id'])) {
                $this->client->command('/ppp/secret/remove', ['=.id=' . $secret['.id']]);
            }
        }

        $this->kickPppoeActive($username);
    }

    /**
     * Kick active PPPoE connection by username.
     */
    public function kickPppoeActive(string $username): int
    {
        $activeSessions = $this->client->command('/ppp/active/print', ['?name=' . $username]);
        $kicked = 0;

        foreach ($activeSessions as $session) {
            if (isset($session['.id'])) {
                $this->client->command('/ppp/active/remove', ['=.id=' . $session['.id']]);
                $kicked++;
            }
        }

        return $kicked;
    }

    /**
     * Get list of active PPPoE sessions.
     */
    public function getActivePppoeSessions(): array
    {
        return $this->client->command('/ppp/active/print');
    }

    // ==========================================
    // QUEUES / BANDWIDTH SPEED LIMITS
    // ==========================================

    /**
     * Set or update simple queue for subscriber rate limiting (e.g. 5M/10M).
     */
    public function setSimpleQueue(string $name, string $targetIp, string $maxLimit): array
    {
        $existing = $this->client->command('/queue/simple/print', ['?name=' . $name]);

        $params = [
            '=name=' . $name,
            '=target=' . $targetIp,
            '=max-limit=' . $maxLimit, // format: "upload/download", e.g. 5M/10M
        ];

        if (!empty($existing)) {
            $params[] = '=.id=' . $existing[0]['.id'];
            return $this->client->command('/queue/simple/set', $params);
        }

        return $this->client->command('/queue/simple/add', $params);
    }

    // ==========================================
    // MAC AUTHORIZATION — ACTIVE-CLIENTS ALLOW-LIST
    // ==========================================

    public const ALLOW_LIST = 'active-clients';
    public const POLICY_COMMENT_PREFIX = 'ISP-BILLING:';

    /**
     * List all entries of an address-list.
     */
    public function listAddressList(string $list = self::ALLOW_LIST): array
    {
        return $this->client->command('/ip/firewall/address-list/print', [
            '?list=' . $list,
            '=.proplist=.id,address,comment,created',
        ]);
    }

    /**
     * Add a client IP to the allow-list (idempotent).
     */
    public function addAllowListEntry(string $address, string $comment, string $list = self::ALLOW_LIST): string
    {
        $existing = $this->client->command('/ip/firewall/address-list/print', [
            '?list=' . $list,
            '?address=' . $address,
        ]);

        if (!empty($existing)) {
            $this->client->command('/ip/firewall/address-list/set', [
                '=.id=' . $existing[0]['.id'],
                '=comment=' . $comment,
            ]);

            return 'updated';
        }

        $this->client->command('/ip/firewall/address-list/add', [
            '=list=' . $list,
            '=address=' . $address,
            '=comment=' . $comment,
        ]);

        return 'created';
    }

    /**
     * Remove a client IP from the allow-list (all entries for that address).
     */
    public function removeAllowListEntry(string $address, string $list = self::ALLOW_LIST): string
    {
        $entries = $this->client->command('/ip/firewall/address-list/print', [
            '?list=' . $list,
            '?address=' . $address,
        ]);

        if (empty($entries)) {
            return 'absent';
        }

        foreach ($entries as $entry) {
            $this->client->command('/ip/firewall/address-list/remove', [
                '=.id=' . $entry['.id'],
            ]);
        }

        return 'removed';
    }

    /**
     * Create or refresh the static DHCP lease binding IP to MAC (idempotent).
     * This is what keeps the allow-list (IP based) pinned to the customer's MAC.
     */
    public function ensureStaticLease(string $macAddress, string $ipAddress, string $comment): string
    {
        $mac = strtoupper($macAddress);
        $existing = $this->client->command('/ip/dhcp-server/lease/print', [
            '?mac-address=' . $mac,
        ]);

        if (!empty($existing)) {
            $params = [
                '=.id=' . $existing[0]['.id'],
                '=address=' . $ipAddress,
                '=comment=' . $comment,
                '=disabled=no',
            ];
            $this->client->command('/ip/dhcp-server/lease/set', $params);

            return 'updated';
        }

        $this->client->command('/ip/dhcp-server/lease/add', [
            '=mac-address=' . $mac,
            '=address=' . $ipAddress,
            '=comment=' . $comment,
            '=disabled=no',
        ]);

        return 'created';
    }

    /**
     * Remove the static DHCP lease for a MAC (best effort).
     */
    public function removeStaticLease(string $macAddress): string
    {
        $leases = $this->client->command('/ip/dhcp-server/lease/print', [
            '?mac-address=' . strtoupper($macAddress),
        ]);

        $removed = 0;
        foreach ($leases as $lease) {
            // Only remove leases we created (comment tagged), never dynamic entries.
            if (!str_starts_with((string) ($lease['comment'] ?? ''), 'device:')) {
                continue;
            }

            $this->client->command('/ip/dhcp-server/lease/remove', [
                '=.id=' . $lease['.id'],
            ]);
            $removed++;
        }

        return $removed > 0 ? 'removed' : 'absent';
    }

    /**
     * Detect the WAN interface from the active default route.
     */
    public function detectWanInterface(): ?string
    {
        $routes = $this->client->command('/ip/route/print', [
            '?dst-address=0.0.0.0/0',
            '?active=true',
            '=.proplist=.id,immediate-gw,vrf-interface,gateway',
        ]);

        $route = $routes[0] ?? [];

        // RouterOS v7: "192.168.4.1%ether2"
        if (!empty($route['immediate-gw']) && str_contains($route['immediate-gw'], '%')) {
            return substr($route['immediate-gw'], strpos($route['immediate-gw'], '%') + 1);
        }

        if (!empty($route['vrf-interface'])) {
            return $route['vrf-interface'];
        }

        return null;
    }

    /**
     * IPs of devices with a complete ARP entry (currently reachable peers).
     * Used to seed the allow-list before installing the default-deny policy.
     */
    public function getReachableArpAddresses(): array
    {
        $rows = $this->client->command('/ip/arp/print', ['=.proplist=address,complete']);

        return array_values(array_unique(array_filter(
            array_map(
                fn (array $row): ?string => ($row['complete'] ?? 'false') === 'true' ? ($row['address'] ?? null) : null,
                $rows
            )
        )));
    }

    /**
     * Read-only status of the ISP allow-list firewall policy on this router.
     */
    public function getFirewallPolicyState(): array
    {
        $chain = $this->client->command('/ip/firewall/filter/print', [
            '?chain=forward',
            '=.proplist=.id,chain,action,comment,disabled,out-interface,src-address-list',
        ]);

        $rules = array_values(array_filter(
            $chain,
            fn (array $rule): bool => str_starts_with((string) ($rule['comment'] ?? ''), self::POLICY_COMMENT_PREFIX)
        ));

        $wan = $this->detectWanInterface();
        $anchorIndex = $this->findWanAcceptAnchorIndex($chain, $wan);
        $dropIndex = null;

        foreach ($chain as $index => $rule) {
            if (
                str_starts_with((string) ($rule['comment'] ?? ''), self::POLICY_COMMENT_PREFIX)
                && ($rule['action'] ?? '') === 'drop'
            ) {
                $dropIndex = $index;
                break;
            }
        }

        $entries = $this->listAddressList();

        $interfaceLists = array_map(
            fn (array $list): string => $list['name'],
            $this->client->command('/interface/list/print', ['=.proplist=name'])
        );

        return [
            'installed' => count(array_filter($rules, fn (array $rule): bool => $rule['action'] === 'drop')) > 0,
            'effective' => $dropIndex !== null && ($anchorIndex === null || $dropIndex < $anchorIndex),
            'anchor_index' => $anchorIndex,
            'rules' => $rules,
            'wan_interface' => $wan,
            'interface_lists' => $interfaceLists,
            'allow_list' => self::ALLOW_LIST,
            'allow_list_entries' => count($entries),
            'allow_list_sample' => array_slice($entries, 0, 10),
        ];
    }

    /**
     * Install the default-deny (allow-list) firewall policy.
     *
     * Three rules are inserted directly BEFORE the router's main "accept
     * everything out the WAN" rule (the anchor), because appending at the
     * end of the chain would be a no-op — the anchor already accepts all
     * LAN traffic first. Seeding writes currently connected IPs into the
     * allow-list before the drop rule becomes active so nobody is cut off.
     *
     *  1. accept  src-address-list= active-clients   -> paying devices
     *  2. accept  hotspot=from-client,auth           -> hotspot sessions
     *     (hotspot has its own login/billing gate)
     *  3. drop    src-address-list=!active-clients   -> everyone else
     */
    public function installFirewallPolicy(string $wanInterface, array $seedAddresses = []): array
    {
        $installed = [
            'rules' => [],
            'seeded' => 0,
            'wan_interface' => $wanInterface,
        ];

        $existing = array_map(
            fn (array $rule): string => (string) ($rule['comment'] ?? ''),
            $this->client->command('/ip/firewall/filter/print', ['=.proplist=comment'])
        );

        // 1. Seed the allow-list BEFORE enabling the drop rule.
        foreach (array_unique($seedAddresses) as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP)) {
                continue;
            }
            $result = $this->addAllowListEntry($address, 'seed:' . now()->toDateString());
            if ($result === 'created') {
                $installed['seeded']++;
            }
        }

        $specs = [
            [
                'comment' => self::POLICY_COMMENT_PREFIX . ' allow active-clients',
                'rule' => [
                    '=chain=forward',
                    '=action=accept',
                    '=src-address-list=' . self::ALLOW_LIST,
                    '=out-interface=' . $wanInterface,
                ],
            ],
            [
                'comment' => self::POLICY_COMMENT_PREFIX . ' accept hotspot-authenticated',
                'rule' => [
                    '=chain=forward',
                    '=action=accept',
                    '=hotspot=from-client,auth',
                    '=out-interface=' . $wanInterface,
                ],
            ],
            [
                'comment' => self::POLICY_COMMENT_PREFIX . ' default-deny (allow-list)',
                'rule' => [
                    '=chain=forward',
                    '=action=drop',
                    '=src-address-list=!' . self::ALLOW_LIST,
                    '=connection-state=new',
                    '=out-interface=' . $wanInterface,
                ],
            ],
        ];

        $created = [];

        // 2. Create the rules (at the end first — moved into place below).
        foreach ($specs as $spec) {
            if (in_array($spec['comment'], $existing, true)) {
                continue;
            }

            $this->client->command('/ip/firewall/filter/add', [
                ...$spec['rule'],
                '=comment=' . $spec['comment'],
            ]);
            $installed['rules'][] = $spec['comment'];
        }

        // 3. Move all ISP-BILLING rules directly before the WAN accept anchor.
        $chain = $this->client->command('/ip/firewall/filter/print', [
            '?chain=forward',
            '=.proplist=.id,chain,action,comment,disabled,out-interface,src-address-list',
        ]);
        $anchorId = $this->findWanAcceptAnchorId($chain, $wanInterface);

        if ($anchorId) {
            $ourIds = [];
            foreach ($chain as $rule) {
                if (str_starts_with((string) ($rule['comment'] ?? ''), self::POLICY_COMMENT_PREFIX)) {
                    $ourIds[] = $rule['.id'];
                }
            }

            foreach ($ourIds as $id) {
                $this->client->command('/ip/firewall/filter/move', [
                    '=.id=' . $id,
                    '=destination=' . $anchorId,
                ]);
            }

            $installed['placement'] = 'before WAN accept rule ' . $anchorId;
        } else {
            $installed['placement'] = 'no WAN accept anchor found — rules appended at end of chain';
        }

        return $installed;
    }

    /**
     * Index of the first unconditional "accept out <WAN>" rule — everything
     * LAN-to-WAN is already accepted there, so our deny must sit before it.
     */
    private function findWanAcceptAnchorIndex(array $chain, ?string $wanInterface): ?int
    {
        foreach ($chain as $index => $rule) {
            if (($rule['action'] ?? '') === 'accept' && ($rule['out-interface'] ?? '') === $wanInterface) {
                return $index;
            }
        }

        return null;
    }

    private function findWanAcceptAnchorId(array $chain, ?string $wanInterface): ?string
    {
        foreach ($chain as $rule) {
            if (($rule['action'] ?? '') === 'accept' && ($rule['out-interface'] ?? '') === $wanInterface) {
                return $rule['.id'] ?? null;
            }
        }

        return null;
    }

    /**
     * Remove the ISP firewall policy rules (allow-list entries are kept).
     */
    public function removeFirewallPolicy(): array
    {
        $rules = $this->client->command('/ip/firewall/filter/print', ['=.proplist=.id,comment']);

        $removed = [];
        foreach ($rules as $rule) {
            if (!str_starts_with((string) ($rule['comment'] ?? ''), self::POLICY_COMMENT_PREFIX)) {
                continue;
            }

            $this->client->command('/ip/firewall/filter/remove', ['=.id=' . $rule['.id']]);
            $removed[] = $rule['comment'];
        }

        return ['removed' => $removed];
    }

    // ==========================================
    // FIREWALL WALLED GARDEN / ISOLATION
    // ==========================================

    /**
     * Add target subscriber IP to expired/quarantine address-list.
     */
    public function isolateIp(string $ip, string $listName = 'EXPIRED_USERS', ?string $comment = null): void
    {
        $existing = $this->client->command('/ip/firewall/address-list/print', [
            '?list=' . $listName,
            '?address=' . $ip,
        ]);

        if (empty($existing)) {
            $params = [
                '=list=' . $listName,
                '=address=' . $ip,
            ];
            if ($comment) {
                $params[] = '=comment=' . $comment;
            }
            $this->client->command('/ip/firewall/address-list/add', $params);
        }
    }

    /**
     * Remove subscriber IP from isolation list upon payment/renewal.
     */
    public function unfreezeIp(string $ip, string $listName = 'EXPIRED_USERS'): void
    {
        $entries = $this->client->command('/ip/firewall/address-list/print', [
            '?list=' . $listName,
            '?address=' . $ip,
        ]);

        foreach ($entries as $entry) {
            if (isset($entry['.id'])) {
                $this->client->command('/ip/firewall/address-list/remove', ['=.id=' . $entry['.id']]);
            }
        }
    }

    /**
     * Manage an L2 bridge filter which blocks this LAN device when access expires.
     */
    public function setLanDeviceAccess(Device $device, bool $enabled): void
    {
        // RouterOS 7.24 rejects a bare MAC here — bridge filter src-mac-address
        // expects the range form "MAC/MASK" (exact match = full FF mask).
        $macRange = strtoupper($device->mac_address);
        if (!str_contains($macRange, '/')) {
            $macRange .= '/FF:FF:FF:FF:FF:FF';
        }

        $comment = 'ISP-DEVICE-' . $device->id;
        $rules = $this->client->command('/interface/bridge/filter/print', [
            '?comment=' . $comment,
            '=.proplist=.id',
        ]);

        if ($rules !== []) {
            $this->client->command('/interface/bridge/filter/set', [
                '=.id=' . $rules[0]['.id'],
                '=disabled=' . ($enabled ? 'yes' : 'no'),
                '=src-mac-address=' . $macRange,
            ]);

            return;
        }

        $this->client->command('/interface/bridge/filter/add', [
            '=chain=forward',
            '=action=drop',
            '=src-mac-address=' . $macRange,
            '=disabled=' . ($enabled ? 'yes' : 'no'),
            '=comment=' . $comment,
        ]);
    }

    /**
     * Remove the L2 bridge filter rule that blocks this LAN device.
     */
    public function removeLanDeviceAccess(Device $device): void
    {
        $comment = 'ISP-DEVICE-' . $device->id;
        $rules = $this->client->command('/interface/bridge/filter/print', [
            '?comment=' . $comment,
            '=.proplist=.id',
        ]);

        foreach ($rules as $rule) {
            if (isset($rule['.id'])) {
                $this->client->command('/interface/bridge/filter/remove', ['=.id=' . $rule['.id']]);
            }
        }
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }
}
