<?php

namespace App\Services\Mikrotik;

use App\Models\Device;
use App\Models\Router;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MikrotikService
{
    protected RouterOsProtocol $client;
    protected ?Router $router = null;

    /** @var bool isolation drop rules already checked on this connection */
    private bool $isolationEnsured = false;

    public function __construct(?Router $router = null)
    {
        $timeout = (int) config('mikrotik.timeout', 15);
        $this->client = new RouterOsProtocol(timeout: $timeout);

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
     *
     * After a failed login a short cooldown is cached: while it lasts we
     * refuse to open a socket at all. RouterOS delays login replies from a
     * source IP after failed attempts (anti-bruteforce backoff), and every
     * page view triggers a login — without the cooldown a refresh loop
     * feeds the backoff indefinitely and no page can ever load.
     */
    public function connect(): bool
    {
        if (!$this->router) {
            throw new Exception("Router instance not assigned.");
        }

        $cooldownKey = $this->cooldownKey();

        if ($fails = (int) cache()->get($cooldownKey, 0)) {
            $ttl = cache()->get($cooldownKey . ':ttl');
            $remaining = $ttl ? max(1, (int) $ttl - time()) : null;
            throw new Exception(
                "RouterOS at {$this->router->ip_address}: login paused after {$fails} failed attempt(s)"
                . ($remaining ? " — retrying in {$remaining}s (letting the router's login backoff clear)" : '')
            );
        }

        $started = microtime(true);
        $this->client->setTimeout($this->effectiveTimeout());

        try {
            $connected = $this->client->connect(
                ip: $this->router->ip_address,
                username: $this->router->api_username,
                password: $this->router->api_password, // decrypted via model cast
                port: $this->router->api_port ?? 8728,
                useSsl: $this->router->use_ssl ?? false
            );
        } catch (Exception $exception) {
            // Real credential errors get a short pause; silent timeouts (the
            // router holding replies back) get 180s+ of quiet to recover.
            $silent = str_starts_with($exception->getMessage(), 'Failed to authenticate');
            throw $this->pauseLogin(
                $exception->getMessage(),
                $silent ? null : 60, // null => escalating 180×fails for silent timeouts
            );
        }

        $elapsed = microtime(true) - $started;
        $maxLoginTime = $this->effectiveTimeout();

        if ($elapsed > $maxLoginTime) {
            // Login took longer than the configured timeout budget.
            $this->client->disconnect();

            throw $this->pauseLogin(
                sprintf('login succeeded but replies took too long (~%.1fs > %ds)', $elapsed, $maxLoginTime)
            );
        }

        cache()->forget($cooldownKey);
        cache()->forget($cooldownKey . ':ttl');

        return $connected;
    }

    /**
     * Arm the per-router login cooldown and return an exception carrying it.
     *
     * Shared by the login path and the command-phase guard so every kind of
     * slow/failed interaction with a backing-off router lengthens the same
     * quiet window instead of hammering it.
     */
    protected function pauseLogin(string $reason, ?int $ttl = null): Exception
    {
        $fails = (int) cache()->get($this->cooldownKey(), 0) + 1;
        $ttl = $ttl ?? min(180 * $fails, 600);
        cache()->put($this->cooldownKey(), $fails, $ttl);
        cache()->put($this->cooldownKey() . ':ttl', time() + $ttl, $ttl);

        $at = $this->router?->ip_address ?? 'router';

        return new Exception("RouterOS at {$at}: {$reason} — paused for {$ttl}s");
    }

    /**
     * Abort a command batch that has spent more than its time budget.
     *
     * Healthy command batches finish quickly; during high CPU load each reply
     * can take several seconds. The guard ensures a batch doesn't run indefinitely.
     */
    private function phaseGuard(float $since, string $phase): void
    {
        $elapsed = microtime(true) - $since;
        $maxPhaseTime = $this->effectiveTimeout();

        if ($elapsed > $maxPhaseTime) {
            throw $this->pauseLogin("{$phase} replies took longer than {$maxPhaseTime}s — router-side delay");
        }

        $this->noteLatency($elapsed);
    }

    /**
     * Socket/phase budget for this router.
     *
     * Starts at the configured base and grows (up to mikrotik.max_timeout) when
     * the router has recently been slow, so a legitimately loaded device is given
     * room to answer instead of being marked offline on every page load. The
     * observed latency cache expires after an hour, so the budget falls back to
     * the base value once the router recovers.
     */
    private function effectiveTimeout(): int
    {
        $base = (int) config('mikrotik.timeout', 15);
        $cap = (int) config('mikrotik.max_timeout', 60);

        if (!$this->router) {
            return $base;
        }

        $latency = (float) cache()->get($this->latencyKey(), 0);

        if ($latency <= 0) {
            return $base;
        }

        return max($base, min($cap, (int) ceil($latency * 3)));
    }

    /**
     * Record the slowest command phase seen recently for this router.
     */
    private function noteLatency(float $seconds): void
    {
        if (!$this->router || $seconds <= 0) {
            return;
        }

        $key = $this->latencyKey();
        $previous = (float) cache()->get($key, 0);

        cache()->put($key, max($previous, $seconds), now()->addHour());
    }

    private function latencyKey(): string
    {
        return 'mikrotik:latency:' . $this->router->id;
    }

    private function cooldownKey(): string
    {
        return 'mikrotik:login-cooldown:' . $this->router->id;
    }

    /**
     * Check connection and fetch router health metrics.
     */
    public function checkHealth(): array
    {
        $since = microtime(true);
        $resources = $this->client->command('/system/resource/print');
        $this->phaseGuard($since, 'system resource');

        $since = microtime(true);
        $identity = $this->client->command('/system/identity/print');
        $this->phaseGuard($since, 'system identity');

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
        $since = microtime(true);
        $health = $this->checkHealth();
        $this->phaseGuard($since, 'snapshot health');

        $since = microtime(true);
        $hotspotUsers = $this->client->command('/ip/hotspot/user/print', [
            '=.proplist=name,profile,disabled,comment,limit-uptime,limit-bytes-total,bytes-in,bytes-out',
        ]);
        $this->phaseGuard($since, 'snapshot hotspot users');

        $since = microtime(true);
        $hotspotSessions = $this->getActiveHotspotSessions();
        $this->phaseGuard($since, 'snapshot hotspot sessions');

        $since = microtime(true);
        $pppoeUsers = array_values(array_filter(
            $this->client->command('/ppp/secret/print', [
                '=.proplist=name,service,profile,disabled,remote-address,comment',
            ]),
            fn (array $user): bool => in_array($user['service'] ?? 'any', ['any', 'pppoe'], true),
        ));
        $this->phaseGuard($since, 'snapshot pppoe users');

        return [
            'health' => $health,
            'hotspot_users' => $hotspotUsers,
            'hotspot_sessions' => $hotspotSessions,
            'pppoe_users' => $pppoeUsers,
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
            'hotspot_profiles' => $this->client->command('/ip/hotspot/user/profile/print'),
            'pppoe_profiles' => $this->client->command('/ppp/profile/print'),
        ];
    }

    /**
     * Fetch every live device data source from RouterOS.
     *
     * Each source fails independently so a single unavailable table
     * (e.g. PPP disabled) never breaks the whole snapshot.
     */
    /**
     * Active reachability check from the router: one ICMP echo. Used for
     * static-IP devices that have no DHCP lease and a silent ARP entry.
     */
    public function pingReachable(string $address): bool
    {
        try {
            $rows = $this->client->command('/ping', [
                '=address=' . $address,
                '=count=1',
            ]);
        } catch (Exception) {
            return false;
        }

        foreach ($rows as $row) {
            // Replies carry a time; timeouts carry a status instead.
            if (isset($row['time']) && !isset($row['status'])) {
                return true;
            }
        }

        return false;
    }

    public function getConnectedDeviceSources(): array
    {
        $sources = [
            'arp' => '/ip/arp/print',
            'dhcp' => '/ip/dhcp-server/lease/print',
            'hotspot_active' => '/ip/hotspot/active/print',
            'hotspot_hosts' => '/ip/hotspot/host/print',
            'ppp_active' => '/ppp/active/print',
            'simple_queues' => ['/queue/simple/print', [
                '=.proplist=name,target,max-limit,bytes,rate,disabled,comment',
            ]],
            'neighbors' => '/ip/neighbor/print',
        ];

        $rows = [];
        $errors = [];

        foreach ($sources as $key => $command) {
            $step = microtime(true);
            try {
                $rows[$key] = is_array($command)
                    ? $this->client->command($command[0], $command[1])
                    : $this->client->command($command);
            } catch (Exception $exception) {
                $rows[$key] = [];
                $errors[$key] = $exception->getMessage();
            }
            $this->phaseGuard($step, "device source {$key}");
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * L2/L3 structure of one router: interfaces, bridges, bridge port
     * membership and discovered neighbours.
     *
     * These are what turn the device list into an actual topology — without
     * bridge ports there is no way to say which interface a device hangs off.
     * Each source fails independently for the same reason as above.
     */
    public function getTopologySources(): array
    {
        $sources = [
            'interfaces' => ['/interface/print', [
                '=.proplist=name,type,driver-name,mac-address,mtu,actual-mtu,running,disabled,comment,rx-byte,tx-byte',
            ]],
            'bridges' => ['/interface/bridge/print', [
                '=.proplist=.id,name,protocol-mode,ports,disabled,comment',
            ]],
            'bridge_ports' => ['/interface/bridge/port/print', [
                '=.proplist=.id,bridge,interface,edge,disabled,comment',
            ]],
            'addresses' => ['/ip/address/print', [
                '=.proplist=address,network,interface,disabled,dynamic',
            ]],
            'pools' => ['/ip/pool/print', [
                '=.proplist=name,ranges,total,used,available,comment',
            ]],
            'dhcp_servers' => ['/ip/dhcp-server/print', [
                '=.proplist=name,interface,address-pool,lease-time,add-arp,disabled,comment',
            ]],
            'dhcp_networks' => ['/ip/dhcp-server/network/print', [
                '=.proplist=address,gateway,dns-server,ntp-server,comment',
            ]],
            'dhcp_leases' => ['/ip/dhcp-server/lease/print', [
                '=.proplist=address,mac-address,hostname,status,server,expires,active-address',
            ]],
            'hotspot_servers' => ['/ip/hotspot/print', [
                '=.proplist=name,interface,address-pool,profile,addresses-per-mac,idle-timeout,login-by,disabled',
            ]],
            'neighbors' => ['/ip/neighbor/print', []],
        ];

        $rows = [];
        $errors = [];

        foreach ($sources as $key => [$command, $params]) {
            $step = microtime(true);
            try {
                $rows[$key] = $this->client->command($command, $params);
            } catch (Exception $exception) {
                $rows[$key] = [];
                $errors[$key] = $exception->getMessage();
            }
            $this->phaseGuard($step, "topology source {$key}");
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * Fresh byte counters for every interface — one command, deliberately kept
     * apart from the 30s-cached topology structure so the live view can poll
     * traffic without re-reading the whole L2 picture.
     */
    public function getInterfaceCounters(): array
    {
        return $this->client->command('/interface/print', [
            '=.proplist=name,rx-byte,tx-byte,running,disabled',
        ]);
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
        ?int $limitBytesTotal = null,
        ?int $sharedUsers = null
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
        if ($sharedUsers !== null && $sharedUsers > 0) {
            // v6 per-account cap. RouterOS 7 removed shared-users from
            // /ip/hotspot/user entirely (see sendUserCommand), so on v7 this
            // silently falls through and the cap is applied on the user
            // profile instead — ensureHotspotProfileDeviceCap().
            $params[] = '=shared-users=' . $sharedUsers;
        }

        if (!empty($existing)) {
            $id = $existing[0]['.id'];
            $params[] = '=.id=' . $id;

            if ($limitUptime === null) {
                // Access duration is calendar-based and enforced by the application
                // clock (expires_at). Clear any stale RouterOS hour-based limit so a
                // session can never be counted as "uptime hours" instead of dates.
                // (!attribute unsets — an empty =value is rejected as an invalid time.)
                $params[] = '!limit-uptime';
            }

            return $this->sendUserCommand('/ip/hotspot/user/set', $params);
        }

        return $this->sendUserCommand('/ip/hotspot/user/add', $params);
    }

    /**
     * Run a hotspot user command, dropping shared-users when the router
     * rejects it: RouterOS 7 removed shared-users from /ip/hotspot/user
     * (the cap lives on the user profile now, see
     * ensureHotspotProfileDeviceCap), while v6 still honours the per-user
     * value. Profile-level shared-users applies on both.
     */
    private function sendUserCommand(string $command, array $params): array
    {
        try {
            return $this->client->command($command, $params);
        } catch (Exception $exception) {
            if (!str_contains($exception->getMessage(), 'shared-users')) {
                throw $exception;
            }

            $params = array_values(array_filter(
                $params,
                fn (string $param) => !str_starts_with($param, '=shared-users='),
            ));

            Log::notice("RouterOS rejected shared-users on hotspot users — provisioning without the cap ({$command}).");

            return $this->client->command($command, $params);
        }
    }

    /**
     * Make a hotspot profile keep devices signed in across reconnects.
     *
     * `add-mac-cookie` records the device MAC on first login and then
     * transparently re-authenticates it, so a dropped session comes back
     * without the captive portal ever appearing. A disabled or expired account
     * cannot obtain a new cookie, which is precisely the "unless the admin
     * disabled it or the access window ended" carve-out.
     *
     * The cookie lifetime must cover the longest possible access window, so it
     * is set once per profile and only when the router differs from it.
     */
    public function ensureAutoReconnectProfile(string $profile, ?string $cookieTimeout = null): void
    {
        $cookieTimeout = $cookieTimeout ?? (string) config('mikrotik.mac_cookie_timeout', '365d');
        $idleTimeout = (string) config('mikrotik.idle_timeout', '5m');

        $profiles = $this->client->command('/ip/hotspot/user/profile/print', [
            '?name=' . $profile,
        ]);

        if (empty($profiles)) {
            throw new Exception(
                "RouterOS hotspot profile '{$profile}' does not exist on " . ($this->router?->ip_address ?? 'the router')
            );
        }

        $current = $profiles[0];
        $hasCookie = ($current['add-mac-cookie'] ?? 'false') === 'true';
        $lifetimeOk = ($current['mac-cookie-timeout'] ?? '') === $cookieTimeout;
        $currentIdle = $current['idle-timeout'] ?? '';
        $idleOk = $currentIdle === $idleTimeout;

        if ($hasCookie && $lifetimeOk && $idleOk) {
            return;
        }

        $params = ['=.id=' . $current['.id']];

        if (!$hasCookie) {
            $params[] = '=add-mac-cookie=yes';
        }
        if (!$lifetimeOk) {
            $params[] = '=mac-cookie-timeout=' . $cookieTimeout;
        }
        if (!$idleOk) {
            // Session inactivity timeout only — a dropped session can come
            // straight back through the MAC cookie while the subscription
            // is still valid. Never touches expires_at.
            $params[] = '=idle-timeout=' . $idleTimeout;
        }

        $this->client->command('/ip/hotspot/user/profile/set', $params);

        Log::notice("RouterOS hotspot user profile '{$profile}' reconciled", [
            'add-mac-cookie' => $hasCookie ? 'unchanged' : 'yes',
            'mac-cookie-timeout' => $lifetimeOk ? 'unchanged' : $cookieTimeout,
            'idle-timeout' => $idleOk ? 'unchanged' : "{$currentIdle} -> {$idleTimeout}",
        ]);
    }

    /**
     * Enforce the per-account device cap on a hotspot user profile.
     *
     * RouterOS 7 removed `shared-users` from /ip/hotspot/user, so the
     * (N+1)th concurrent login can only be refused at authentication time
     * through the user profile: every account on the profile inherits the
     * cap, and each account still gets its own N slots (not one shared pool).
     * Idempotent — only written when the profile differs.
     */
    public function ensureHotspotProfileDeviceCap(string $profile, int $sharedUsers): string
    {
        $sharedUsers = max(1, $sharedUsers);

        $profiles = $this->client->command('/ip/hotspot/user/profile/print', [
            '?name=' . $profile,
        ]);

        if (empty($profiles)) {
            throw new Exception(
                "RouterOS hotspot profile '{$profile}' does not exist on " . ($this->router?->ip_address ?? 'the router')
            );
        }

        $current = (int) ($profiles[0]['shared-users'] ?? 0);

        if ($current === $sharedUsers) {
            return 'unchanged';
        }

        $this->client->command('/ip/hotspot/user/profile/set', [
            '=.id=' . $profiles[0]['.id'],
            '=shared-users=' . $sharedUsers,
        ]);

        Log::notice("RouterOS hotspot profile '{$profile}' device cap reconciled", [
            'shared-users' => "{$current} -> {$sharedUsers}",
        ]);

        return 'updated';
    }

    /**
     * Make the hotspot *server* profile accept remembered logins.
     *
     * `login-by` gains `cookie,mac-cookie` alongside whatever methods the
     * profile already uses (http-chap and/or https are preserved — plaintext
     * password login is never added). `http-cookie-lifetime` bounds the
     * browser cookie; it is a login convenience, not a subscription window:
     * an expired account cannot obtain a new cookie even while the old one
     * is still stored on the device.
     *
     * Idempotent: only fields that differ from the desired state are set.
     */
    public function ensureHotspotServerAuth(?string $serverProfile = null): void
    {
        $desiredLifetime = (string) config('mikrotik.http_cookie_lifetime', '30d');
        $required = ['http-chap', 'cookie', 'mac-cookie'];

        $profiles = $serverProfile
            ? $this->client->command('/ip/hotspot/profile/print', ['?name=' . $serverProfile])
            : $this->client->command('/ip/hotspot/profile/print');

        foreach ($profiles as $profile) {
            $current = array_values(array_filter(array_map('trim', explode(',', $profile['login-by'] ?? ''))));
            $merged = array_values(array_unique(array_merge($current, $required)));

            $params = [];
            if ($current !== $merged) {
                $params[] = '=login-by=' . implode(',', $merged);
            }
            if (($profile['http-cookie-lifetime'] ?? '') !== $desiredLifetime) {
                $params[] = '=http-cookie-lifetime=' . $desiredLifetime;
            }

            if (empty($params)) {
                continue;
            }

            $params[] = '=.id=' . $profile['.id'];
            $this->client->command('/ip/hotspot/profile/set', $params);

            Log::notice('RouterOS hotspot server profile reconciled', [
                'profile' => $profile['name'] ?? $profile['.id'],
                'login-by' => implode(',', $merged),
                'http-cookie-lifetime' => $desiredLifetime,
            ]);
        }
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
        $target = $this->normalizeQueueTarget($targetIp);
        $queues = $this->client->command('/queue/simple/print', [
            '=.proplist=.id,name,target',
        ]);

        $existingByName = array_values(array_filter(
            $queues,
            fn (array $queue): bool => ($queue['name'] ?? null) === $name
        ));
        $existingByTarget = array_values(array_filter(
            $queues,
            fn (array $queue): bool => $this->normalizeQueueTarget((string) ($queue['target'] ?? '')) === $target
        ));

        $params = [
            '=name=' . $name,
            '=target=' . $target,
            '=max-limit=' . $maxLimit, // format: "upload/download", e.g. 5M/10M
        ];

        // Static CPEs already have an ISP-CPE-* queue for traffic counters.
        // Updating every matching target prevents an older 0/0 queue from
        // winning RouterOS's first-match queue selection.
        if ($existingByTarget !== []) {
            foreach ($existingByTarget as $queue) {
                $this->client->command('/queue/simple/set', [
                    '=.id=' . $queue['.id'],
                    '=target=' . $target,
                    '=max-limit=' . $maxLimit,
                ]);
            }

            return [];
        }

        if (!empty($existingByName)) {
            $params[] = '=.id=' . $existingByName[0]['.id'];
            return $this->client->command('/queue/simple/set', $params);
        }

        return $this->client->command('/queue/simple/add', $params);
    }

    private function normalizeQueueTarget(string $target): string
    {
        $target = trim($target);

        if ($target === '') {
            return $target;
        }

        return str_contains($target, '/') ? $target : $target . '/32';
    }

    /**
     * Ensure a static CPE has a queue counter without creating duplicate
     * queues when DHCP or an operator already created one for its IP.
     */
    public function ensureTrafficQueue(string $name, string $targetIp): string
    {
        $queues = $this->client->command('/queue/simple/print', [
            '=.proplist=name,target',
        ]);
        $target = rtrim($targetIp, '/') . '/32';

        foreach ($queues as $queue) {
            if (rtrim((string) ($queue['target'] ?? ''), '/') === $target) {
                return 'existing';
            }
        }

        $this->setSimpleQueue($name, $targetIp, '0/0');

        return 'created';
    }

    // ==========================================
    // MAC AUTHORIZATION — ACTIVE-CLIENTS ALLOW-LIST
    // ==========================================

    public const ALLOW_LIST = 'active-clients';
    public const POLICY_COMMENT_PREFIX = 'ISP-BILLING:';
    public const ISOLATION_COMMENT = 'ISP-ISOLATION';
    public const ISOLATION_MAC_COMMENT_PREFIX = 'ISP-ISOLATION-MAC:';

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

    // ==========================================
    // CONNECTED DEVICE COMMENTS
    // ==========================================

    /**
     * Ownership tag embedded in lease/address-list comments by
     * MacAuthManager::comment(). Any operator comment we write must keep it
     * intact, otherwise removeStaticLease() would no longer recognise our
     * own reservations during cleanup.
     */
    public const OWNERSHIP_TAG_PATTERN = '/^device:\d+\|client:[^|]*\|[0-9A-Fa-f:]+/';

    /**
     * Write an operator comment onto the DHCP lease of one connected device —
     * the only RouterOS record for a live client that carries a writable
     * comment (ARP entries have no comment property and hotspot active
     * sessions are read-only, mirroring the hotspot user instead).
     *
     * Works for static and dynamic leases alike. Returns per-target status so
     * the caller can tell "saved", "no such lease" and "router refused" apart.
     *
     * @return array{updated: array<int, string>, absent: array<int, string>, errors: array<string, string>}
     */
    public function setDeviceComment(?string $macAddress, ?string $ipAddress, string $comment): array
    {
        $comment = trim($comment);
        $result = ['updated' => [], 'absent' => [], 'errors' => []];

        try {
            $leases = $this->findDeviceRows('/ip/dhcp-server/lease/print', $macAddress, $ipAddress);
        } catch (Exception $exception) {
            $result['errors']['dhcp_lease'] = $exception->getMessage();

            return $result;
        }

        if ($leases === []) {
            $result['absent'][] = 'dhcp_lease';

            return $result;
        }

        foreach ($leases as $lease) {
            if (empty($lease['.id'])) {
                continue;
            }

            try {
                $this->client->command('/ip/dhcp-server/lease/set', [
                    '=.id=' . $lease['.id'],
                    '=comment=' . self::composeComment($lease['comment'] ?? null, $comment),
                ]);
                $result['updated'][] = 'dhcp_lease';
            } catch (Exception $exception) {
                $result['errors']['dhcp_lease'] = $exception->getMessage();
            }
        }

        return $result;
    }

    /**
     * Rows of one device in a RouterOS table: matched on MAC first (the
     * stable identity), falling back to the IP for records without a MAC.
     *
     * @return array<int, array>
     */
    private function findDeviceRows(string $print, ?string $macAddress, ?string $ipAddress): array
    {
        if (!empty($macAddress)) {
            $rows = $this->client->command($print, ['?mac-address=' . strtoupper($macAddress)]);

            if ($rows !== []) {
                return $rows;
            }
        }

        if (!empty($ipAddress)) {
            return $this->client->command($print, ['?address=' . $ipAddress]);
        }

        return [];
    }

    /**
     * Merge an operator comment into a stored one, preserving the ownership
     * tag of platform-created reservations:
     *
     *   tag-only    + "camera" => "device:1|client:2|AA:BB:CC:DD:EE:FF — camera"
     *   tagged note + "camera" => tag — camera   (previous note replaced)
     *   untagged    + "camera" => "camera"
     *   clearing (empty note)  => the bare tag, or ""
     */
    public static function composeComment(?string $existing, string $comment): string
    {
        $tag = self::ownershipTag($existing);
        $note = trim($comment);

        if ($tag === null) {
            return $note;
        }

        return $note === '' ? $tag : "{$tag} — {$note}";
    }

    /**
     * The comment as an operator should see it: the ownership tag is
     * plumbing, not text. Returns null when nothing human-readable remains.
     */
    public static function displayComment(?string $comment): ?string
    {
        $value = trim((string) $comment);

        if ($value === '') {
            return null;
        }

        if (($tag = self::ownershipTag($value)) !== null) {
            $value = ltrim(substr($value, strlen($tag)), "— \t");
        }

        return $value === '' ? null : $value;
    }

    private static function ownershipTag(?string $comment): ?string
    {
        if ($comment !== null && preg_match(self::OWNERSHIP_TAG_PATTERN, $comment, $matches)) {
            return $matches[0];
        }

        return null;
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
     *
     * Tagging alone blocks nothing — this also makes sure the list is
     * enforced by forward-chain drop rules (created once, platform-owned).
     */
    public function isolateIp(string $ip, string $listName = 'EXPIRED_USERS', ?string $comment = null): void
    {
        $this->ensureIsolationRules($listName);

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
     * Create the two forward drop rules that actually enforce an isolation
     * address-list (source and destination), once per connection.
     *
     * Without these, isolateIp() only tags the IP and internet keeps
     * flowing — nothing on a stock router drops EXPIRED_USERS.
     * Only new rules with our own comment are added; foreign rules
     * (LOCKDOWN-B2, b2-autoblock, …) are never modified or moved.
     */
    public function ensureIsolationRules(string $listName = 'EXPIRED_USERS'): void
    {
        if ($this->isolationEnsured) {
            return;
        }

        $comment = self::ISOLATION_COMMENT . ':' . $listName;

        $existing = $this->client->command('/ip/firewall/filter/print', [
            '?comment=' . $comment,
            '=.proplist=.id,src-address-list,dst-address-list',
        ]);

        $present = ['src-address-list' => false, 'dst-address-list' => false];

        foreach ($existing as $rule) {
            foreach (array_keys($present) as $match) {
                if (($rule[$match] ?? null) === $listName) {
                    $present[$match] = true;
                }
            }
        }

        if ($present['src-address-list'] && $present['dst-address-list']) {
            $this->isolationEnsured = true;

            return;
        }

        // Place the drops before the first rule so no earlier accept can
        // short-circuit them (they only match listed IPs, so this is a
        // no-op for everything else).
        $first = $this->client->command('/ip/firewall/filter/print', ['=.proplist=.id']);
        $before = $first[0]['.id'] ?? null;

        foreach ($present as $match => $alreadyThere) {
            if ($alreadyThere) {
                continue;
            }

            $params = [
                '=chain=forward',
                '=action=drop',
                '=comment=' . $comment,
                '=' . $match . '=' . $listName,
            ];

            if ($before) {
                $params[] = '=place-before=' . $before;
            }

            $this->client->command('/ip/firewall/filter/add', $params);
        }

        $this->isolationEnsured = true;
    }

    /**
     * Block a device by MAC with its own forward drop rule.
     *
     * IP isolation alone is bypassable: kicking the session makes the client
     * re-login, it grabs a fresh DHCP lease and the IP block no longer
     * matches it. The MAC rule keeps the cut in place regardless of address.
     */
    public function isolateMac(string $mac): void
    {
        $mac = strtoupper(trim($mac));
        if ($mac === '') {
            return;
        }

        $comment = self::ISOLATION_MAC_COMMENT_PREFIX . $mac;

        $existing = $this->client->command('/ip/firewall/filter/print', [
            '?comment=' . $comment,
            '=.proplist=.id',
        ]);

        if (empty($existing)) {
            // Firewall filter wants a plain MAC here (only bridge filters take
            // the MAC/mask range form — this one traps "mac address required").
            $macRange = str_contains($mac, '/') ? strtok($mac, '/') : $mac;

            $first = $this->client->command('/ip/firewall/filter/print', ['=.proplist=.id']);

            $params = [
                '=chain=forward',
                '=action=drop',
                '=src-mac-address=' . $macRange,
                '=comment=' . $comment,
            ];

            if ($first[0]['.id'] ?? null) {
                $params[] = '=place-before=' . $first[0]['.id'];
            }

            $this->client->command('/ip/firewall/filter/add', $params);
        }

        $bridgeComment = self::ISOLATION_MAC_COMMENT_PREFIX . 'BRIDGE:' . $mac;
        $bridgeRules = $this->client->command('/interface/bridge/filter/print', [
            '?comment=' . $bridgeComment,
            '=.proplist=.id',
        ]);

        if ($bridgeRules !== []) {
            $firstBridgeRule = $this->client->command('/interface/bridge/filter/print', ['=.proplist=.id']);
            if (
                ($firstBridgeRule[0]['.id'] ?? null) !== ($bridgeRules[0]['.id'] ?? null)
                && isset($firstBridgeRule[0]['.id'], $bridgeRules[0]['.id'])
            ) {
                $this->client->command('/interface/bridge/filter/move', [
                    '=.id=' . $bridgeRules[0]['.id'],
                    '=destination=' . $firstBridgeRule[0]['.id'],
                ]);
            }

            return;
        }

        $bridgeRules = $this->client->command('/interface/bridge/filter/print', [
            '=.proplist=.id',
        ]);
        $bridgeParams = [
            '=chain=forward',
            '=action=drop',
            '=src-mac-address=' . $mac . '/FF:FF:FF:FF:FF:FF',
            '=comment=' . $bridgeComment,
        ];

        if ($bridgeRules[0]['.id'] ?? null) {
            $bridgeParams[] = '=place-before=' . $bridgeRules[0]['.id'];
        }

        $this->client->command('/interface/bridge/filter/add', $bridgeParams);
    }

    /**
     * Remove the per-MAC drop rule created by isolateMac().
     */
    public function unisolateMac(string $mac): void
    {
        $mac = strtoupper(trim($mac));
        if ($mac === '') {
            return;
        }

        $rules = $this->client->command('/ip/firewall/filter/print', [
            '?comment=' . self::ISOLATION_MAC_COMMENT_PREFIX . $mac,
            '=.proplist=.id',
        ]);

        foreach ($rules as $rule) {
            if (isset($rule['.id'])) {
                $this->client->command('/ip/firewall/filter/remove', ['=.id=' . $rule['.id']]);
            }
        }

        $bridgeRules = $this->client->command('/interface/bridge/filter/print', [
            '?comment=' . self::ISOLATION_MAC_COMMENT_PREFIX . 'BRIDGE:' . $mac,
            '=.proplist=.id',
        ]);

        foreach ($bridgeRules as $rule) {
            if (isset($rule['.id'])) {
                $this->client->command('/interface/bridge/filter/remove', ['=.id=' . $rule['.id']]);
            }
        }
    }

    /**
     * MACs currently carrying an isolation drop rule.
     *
     * @return array<int, string> upper-case MACs without the range mask
     */
    public function listIsolatedMacs(): array
    {
        $rules = $this->client->command('/ip/firewall/filter/print', [
            '=.proplist=.id,comment,src-mac-address',
        ]);

        $macs = [];

        foreach ($rules as $rule) {
            if (!str_starts_with((string) ($rule['comment'] ?? ''), self::ISOLATION_MAC_COMMENT_PREFIX)) {
                continue;
            }

            $source = (string) ($rule['src-mac-address'] ?? '');
            $mac = $source !== '' ? $source : (string) Str::after((string) ($rule['comment'] ?? ''), self::ISOLATION_MAC_COMMENT_PREFIX);
            $mac = strtoupper(strtok($mac, '/'));

            if (preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac)) {
                $macs[] = $mac;
            }
        }

        return array_values(array_unique($macs));
    }

    /**
     * Read-only diagnostics for a static CPE block.
     *
     * @return array{ip_entries: array, ip_drop_rules: array, mac_drop_rules: array, bridge_drop_rules: array}
     */
    public function inspectCpeAccess(string $ip, ?string $mac = null): array
    {
        $mac = $mac ? strtoupper(trim($mac)) : null;
        $ipEntries = $this->client->command('/ip/firewall/address-list/print', [
            '?list=EXPIRED_USERS',
            '?address=' . $ip,
        ]);
        $filterRules = $this->client->command('/ip/firewall/filter/print', [
            '=.proplist=.id,action,chain,comment,disabled,src-address,src-address-list,src-mac-address',
        ]);
        $bridgeRules = $this->client->command('/interface/bridge/filter/print', [
            '=.proplist=.id,action,chain,comment,disabled,src-mac-address',
        ]);

        $matchesMac = static function (array $rule) use ($mac): bool {
            if ($mac === null) {
                return false;
            }

            return strtoupper(strtok((string) ($rule['src-mac-address'] ?? ''), '/')) === $mac
                || str_ends_with((string) ($rule['comment'] ?? ''), $mac);
        };
        $isDrop = static fn (array $rule): bool => ($rule['action'] ?? null) === 'drop'
            && ($rule['disabled'] ?? 'false') !== 'true';

        return [
            'ip_entries' => $ipEntries,
            'ip_drop_rules' => array_values(array_filter($filterRules, fn (array $rule): bool => $isDrop($rule)
                && (($rule['src-address'] ?? null) === $ip || ($rule['src-address-list'] ?? null) === 'EXPIRED_USERS'))),
            'mac_drop_rules' => array_values(array_filter($filterRules, fn (array $rule): bool => $isDrop($rule) && $matchesMac($rule))),
            'bridge_drop_rules' => array_values(array_filter($bridgeRules, fn (array $rule): bool => $isDrop($rule) && $matchesMac($rule))),
        ];
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
            $first = $this->client->command('/interface/bridge/filter/print', ['=.proplist=.id']);
            $this->client->command('/interface/bridge/filter/set', [
                '=.id=' . $rules[0]['.id'],
                '=disabled=' . ($enabled ? 'yes' : 'no'),
                '=src-mac-address=' . $macRange,
            ]);
            if (
                ($first[0]['.id'] ?? null) !== ($rules[0]['.id'] ?? null)
                && isset($first[0]['.id'], $rules[0]['.id'])
            ) {
                $this->client->command('/interface/bridge/filter/move', [
                    '=.id=' . $rules[0]['.id'],
                    '=destination=' . $first[0]['.id'],
                ]);
            }

            return;
        }

        $params = [
            '=chain=forward',
            '=action=drop',
            '=src-mac-address=' . $macRange,
            '=disabled=' . ($enabled ? 'yes' : 'no'),
            '=comment=' . $comment,
        ];
        $first = $this->client->command('/interface/bridge/filter/print', ['=.proplist=.id']);
        if ($first[0]['.id'] ?? null) {
            $params[] = '=place-before=' . $first[0]['.id'];
        }

        $this->client->command('/interface/bridge/filter/add', $params);
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
