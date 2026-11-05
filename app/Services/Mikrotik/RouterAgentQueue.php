<?php

namespace App\Services\Mikrotik;

use App\Models\Router;
use App\Models\RouterAgentCommand;

/**
 * Queue an action for a router that the platform cannot reach directly.
 * The router agent picks the command up on its next scheduled push.
 */
class RouterAgentQueue
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function push(Router $router, string $command, array $payload = []): RouterAgentCommand
    {
        $router->ensureAgentToken();

        return RouterAgentCommand::create([
            'router_id' => $router->id,
            'command' => $command,
            'payload' => $payload,
            'status' => RouterAgentCommand::STATUS_PENDING,
            'queued_at' => now(),
        ]);
    }

    public function disableHotspotUser(Router $router, string $username): RouterAgentCommand
    {
        return $this->push($router, 'disable_hotspot_user', ['username' => $username]);
    }

    public function enableHotspotUser(Router $router, string $username): RouterAgentCommand
    {
        return $this->push($router, 'enable_hotspot_user', ['username' => $username]);
    }

    public function kickHotspotUser(Router $router, string $username): RouterAgentCommand
    {
        return $this->push($router, 'kick_hotspot_user', ['username' => $username]);
    }

    public function blockIp(Router $router, string $ip): RouterAgentCommand
    {
        return $this->push($router, 'block_ip', ['ip' => $ip]);
    }

    public function unblockIp(Router $router, string $ip): RouterAgentCommand
    {
        return $this->push($router, 'unblock_ip', ['ip' => $ip]);
    }
}
