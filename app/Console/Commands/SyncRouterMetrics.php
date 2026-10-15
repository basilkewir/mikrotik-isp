<?php

namespace App\Console\Commands;

use App\Models\Router;
use App\Models\Subscription;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SyncRouterMetrics extends Command
{
    protected $signature = 'isp:sync-routers';
    protected $description = 'Poll MikroTik routers for system health, online status, and active subscriber session traffic';

    public function handle(): int
    {
        $routers = Router::all();

        if ($routers->isEmpty()) {
            $this->comment('No routers configured to sync.');
            return self::SUCCESS;
        }

        foreach ($routers as $router) {
            $this->line("Syncing router '{$router->name}' ({$router->ip_address})...");

            try {
                $service = $router->service();
                $health = $service->checkHealth();

                $router->update([
                    'is_online' => true,
                    'last_seen_at' => Carbon::now(),
                    'identity' => $health['identity'],
                    'uptime' => $health['uptime'],
                    'ros_version' => $health['version'],
                    'board_name' => $health['board_name'],
                    'cpu_load' => $health['cpu_load'],
                    'free_memory' => $health['free_memory'],
                    'total_memory' => $health['total_memory'],
                ]);

                // Sync Hotspot active traffic
                $hotspotActive = $service->getActiveHotspotSessions();
                foreach ($hotspotActive as $session) {
                    $user = $session['user'] ?? null;
                    if (!$user) continue;

                    $bytesIn = (int) ($session['bytes-in'] ?? 0);
                    $bytesOut = (int) ($session['bytes-out'] ?? 0);
                    $totalBytes = $bytesIn + $bytesOut;

                    $sub = Subscription::where('router_id', $router->id)
                        ->where('auth_type', 'hotspot')
                        ->where('username', $user)
                        ->where('status', 'active')
                        ->first();

                    if ($sub && $totalBytes > $sub->data_used_bytes) {
                        $sub->update([
                            'data_used_bytes' => $totalBytes,
                            'assigned_ip' => $session['address'] ?? $sub->assigned_ip,
                            'assigned_mac' => $session['mac-address'] ?? $sub->assigned_mac,
                            'last_synced_at' => Carbon::now(),
                        ]);
                    }
                }

                $service->disconnect();
                $this->info("✓ Router '{$router->name}' is ONLINE (CPU: {$health['cpu_load']}%, Uptime: {$health['uptime']}).");
            } catch (Exception $e) {
                $router->update(['is_online' => false]);
                $this->error("✗ Router '{$router->name}' unreachable: " . $e->getMessage());
                Log::warning("Router {$router->name} unreachable: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

