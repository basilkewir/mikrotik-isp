<?php

namespace App\Services\Mikrotik;

use App\Models\Router;
use App\Models\Subscription;
use Exception;
use Illuminate\Support\Facades\Cache;

class RouterDataService
{
    /**
     * Live router snapshot with a short cache and single-flight lock.
     *
     * Page loads must never block on RouterOS: while the router is slow or
     * in login backoff, a login probe can take 10-25s. Snapshots are cached
     * for 30s, only one request per router ever talks to the router at a
     * time (the rest wait for that result), and on failure the last known
     * snapshot (up to 1h old) is served with a connection_error attached.
     */
    public function snapshot(Router $router, bool $fresh = false): array
    {
        $cacheKey = "mikrotik:snapshot:{$router->id}";
        $lastKey = "{$cacheKey}:last";

        if (!$fresh && ($cached = cache()->get($cacheKey))) {
            return $cached;
        }

        $lock = Cache::lock("{$cacheKey}:lock", 90);

        if (!$lock->get()) {
            // Another request is already fetching this router — wait for it.
            for ($i = 0; $i < 50; $i++) {
                usleep(500_000);
                if ($cached = cache()->get($cacheKey)) {
                    return $cached;
                }
            }

            if ($last = cache()->get($lastKey)) {
                return $last;
            }

            throw new Exception("A live snapshot of {$router->name} is already being fetched — please retry in a moment.");
        }

        $service = new MikrotikService();

        try {
            $service->forRouter($router);
            $snapshot = $service->getRouterSnapshot();
            $health = $snapshot['health'];

            $router->update([
                'is_online' => true,
                'last_seen_at' => now(),
                'identity' => $health['identity'],
                'uptime' => $health['uptime'],
                'ros_version' => $health['version'],
                'board_name' => $health['board_name'],
                'cpu_load' => $health['cpu_load'],
                'free_memory' => $health['free_memory'],
                'total_memory' => $health['total_memory'],
            ]);

            $subscriptions = $this->subscriptionsByKey($router);

            $users = [
                ...$this->formatUsers(
                    router: $router,
                    authType: 'hotspot',
                    accounts: $snapshot['hotspot_users'],
                    sessions: $snapshot['hotspot_sessions'],
                    subscriptions: $subscriptions,
                ),
                ...$this->formatUsers(
                    router: $router,
                    authType: 'pppoe',
                    accounts: $snapshot['pppoe_users'],
                    sessions: $snapshot['pppoe_sessions'],
                    subscriptions: $subscriptions,
                ),
            ];

            $result = [
                'router' => $router->only([
                    'id',
                    'name',
                    'ip_address',
                    'api_port',
                    'api_username',
                    'use_ssl',
                    'identity',
                    'is_online',
                    'cpu_load',
                    'uptime',
                    'last_seen_at',
                    'ros_version',
                    'board_name',
                ]),
                'health' => $health,
                'users' => $users,
                'active_sessions' => array_values(array_filter(
                    $users,
                    fn (array $user): bool => $user['has_active_session'],
                )),
            ];

            cache()->put($cacheKey, $result, 30);
            cache()->put($lastKey, $result, 3600);

            return $result;
        } catch (Exception $exception) {
            $router->update(['is_online' => false]);

            if ($last = cache()->get($lastKey)) {
                $last['router']['connection_error'] = $exception->getMessage();
                $last['stale'] = true;

                return $last;
            }

            throw $exception;
        } finally {
            $service->disconnect();
            $lock->release();
        }
    }

    /**
     * Local subscriptions for this router keyed by "auth_type:username",
     * so live router rows can surface their managed expiry.
     *
     * @return array<string, array{id: int, expires_at: ?string, status: string, client_name: ?string}>
     */
    private function subscriptionsByKey(Router $router): array
    {
        return Subscription::query()
            ->where('router_id', $router->id)
            ->with('client:id,name,client_code')
            ->get()
            ->keyBy(fn (Subscription $sub) => "{$sub->auth_type}:{$sub->username}")
            ->map(fn (Subscription $sub) => [
                'id' => $sub->id,
                'expires_at' => $sub->expires_at?->toIso8601String(),
                'status' => $sub->status,
                'client_name' => $sub->client?->name,
            ])
            ->all();
    }

    private function formatUsers(Router $router, string $authType, array $accounts, array $sessions, array $subscriptions = []): array
    {
        $sessionsByUsername = [];
        foreach ($sessions as $session) {
            $username = $session['user'] ?? $session['name'] ?? null;
            if ($username !== null) {
                $sessionsByUsername[$username] = $session;
            }
        }

        $users = [];
        foreach ($accounts as $account) {
            $username = $account['name'] ?? null;
            if ($username === null) {
                continue;
            }

            $session = $sessionsByUsername[$username] ?? [];
            unset($sessionsByUsername[$username]);
            $users[] = $this->formatUser($router, $authType, $username, $account, $session, $subscriptions["{$authType}:{$username}"] ?? null);
        }

        foreach ($sessionsByUsername as $username => $session) {
            $users[] = $this->formatUser($router, $authType, $username, [], $session, $subscriptions["{$authType}:{$username}"] ?? null);
        }

        return $users;
    }

    private function formatUser(Router $router, string $authType, string $username, array $account, array $session, ?array $subscription = null): array
    {
        $disabled = in_array(strtolower((string) ($account['disabled'] ?? 'no')), ['yes', 'true', '1'], true);

        return [
            'key' => "{$router->id}:{$authType}:{$username}",
            'router_id' => $router->id,
            'router_name' => $router->name,
            'auth_type' => $authType,
            'username' => $username,
            'profile' => $account['profile'] ?? null,
            'comment' => $account['comment'] ?? null,
            'status' => $disabled ? 'disabled' : (empty($session) ? 'enabled' : 'active'),
            'has_active_session' => ! empty($session),
            'ip_address' => $session['address'] ?? $account['remote-address'] ?? null,
            'mac_address' => $session['mac-address'] ?? $session['caller-id'] ?? null,
            'uptime' => $session['uptime'] ?? null,
            'bytes_in' => (int) ($session['bytes-in'] ?? $account['bytes-in'] ?? 0),
            'bytes_out' => (int) ($session['bytes-out'] ?? $account['bytes-out'] ?? 0),
            'subscription_id' => $subscription['id'] ?? null,
            'subscription_status' => $subscription['status'] ?? null,
            'expires_at' => $subscription['expires_at'] ?? null,
            'client_name' => $subscription['client_name'] ?? null,
        ];
    }
}
