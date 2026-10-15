<?php

namespace App\Services\Mikrotik;

use App\Models\Router;
use Exception;

class RouterDataService
{
    public function snapshot(Router $router): array
    {
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

            $users = [
                ...$this->formatUsers(
                    router: $router,
                    authType: 'hotspot',
                    accounts: $snapshot['hotspot_users'],
                    sessions: $snapshot['hotspot_sessions'],
                ),
                ...$this->formatUsers(
                    router: $router,
                    authType: 'pppoe',
                    accounts: $snapshot['pppoe_users'],
                    sessions: $snapshot['pppoe_sessions'],
                ),
            ];

            return [
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
        } catch (Exception $exception) {
            $router->update(['is_online' => false]);
            throw $exception;
        } finally {
            $service->disconnect();
        }
    }

    private function formatUsers(Router $router, string $authType, array $accounts, array $sessions): array
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
            $users[] = $this->formatUser($router, $authType, $username, $account, $session);
        }

        foreach ($sessionsByUsername as $username => $session) {
            $users[] = $this->formatUser($router, $authType, $username, [], $session);
        }

        return $users;
    }

    private function formatUser(Router $router, string $authType, string $username, array $account, array $session): array
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
        ];
    }
}
