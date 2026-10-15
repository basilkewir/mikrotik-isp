<?php

namespace App\Http\Controllers;

use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Services\Mikrotik\RouterDataService;
use Exception;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(RouterDataService $routerData): Response
    {
        $routers = [];
        $routerErrors = [];
        $activeSessions = [];
        $hotspotUsers = 0;
        $pppoeUsers = 0;

        foreach (Router::latest('id')->get() as $router) {
            try {
                $snapshot = $routerData->snapshot($router);
                $users = $snapshot['users'];
                $sessions = $snapshot['active_sessions'];
                $activeSessions = [...$activeSessions, ...$sessions];
                $hotspotUsers += count(array_filter($users, fn (array $user) => $user['auth_type'] === 'hotspot'));
                $pppoeUsers += count(array_filter($users, fn (array $user) => $user['auth_type'] === 'pppoe'));

                $routers[] = [
                    ...$snapshot['router'],
                    'hotspot_users_count' => count(array_filter($users, fn (array $user) => $user['auth_type'] === 'hotspot')),
                    'pppoe_users_count' => count(array_filter($users, fn (array $user) => $user['auth_type'] === 'pppoe')),
                ];
            } catch (Exception $exception) {
                $routers[] = [
                    ...$router->fresh()->only([
                        'id',
                        'name',
                        'ip_address',
                        'identity',
                        'is_online',
                        'cpu_load',
                        'uptime',
                        'last_seen_at',
                    ]),
                    'hotspot_users_count' => null,
                    'pppoe_users_count' => null,
                    'connection_error' => $exception->getMessage(),
                ];
                $routerErrors[] = [
                    'router_name' => $router->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        $stats = [
            'total_routers' => count($routers),
            'online_routers' => count(array_filter($routers, fn (array $router) => $router['is_online'])),
            'hotspot_users' => $hotspotUsers,
            'pppoe_users' => $pppoeUsers,
            'active_subscriptions' => count($activeSessions),
            'hotspot_active' => count(array_filter($activeSessions, fn (array $session) => $session['auth_type'] === 'hotspot')),
            'pppoe_active' => count(array_filter($activeSessions, fn (array $session) => $session['auth_type'] === 'pppoe')),
        ];

        $recentLogs = RouterActivityLog::with(['router:id,name', 'subscription:id,username'])
            ->latest('id')
            ->limit(10)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => $stats,
            'routers' => $routers,
            'routerErrors' => $routerErrors,
            'recentLogs' => $recentLogs,
        ]);
    }
}
