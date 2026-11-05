<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Models\RouterAgentCommand;
use App\Models\RouterSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reverse link: the MikroTik router pushes status/usage and pulls commands.
 *
 * Used when the platform cannot open an API connection to the router (for
 * example a VPS reaching a router behind a home/office LAN). The router runs
 * a scheduled script that POSTs here with its agent token.
 */
class RouterAgentController extends Controller
{
    /**
     * Combined push + pull. The router sends its status and current sessions,
     * and receives any pending commands in the same round trip.
     */
    public function sync(Request $request): Response
    {
        $router = $this->authenticate($request);
        if ($router === null) {
            return response('', 401)->header('Content-Type', 'text/plain');
        }

        $data = $request->validate([
            'identity' => 'nullable|string|max:120',
            'board_name' => 'nullable|string|max:120',
            'version' => 'nullable|string|max:40',
            'uptime' => 'nullable|string|max:40',
            'cpu_load' => 'nullable|integer|min:0|max:100',
            'free_memory' => 'nullable|integer',
            'total_memory' => 'nullable|integer',
            'sessions' => 'nullable|string',
        ]);

        $sessions = $this->parseSessions((string) ($data['sessions'] ?? ''));

        DB::transaction(function () use ($router, $data, $sessions, $request): void {
            $this->applyHeartbeat($router, $data, $request);
            $this->replaceSessions($router, $sessions);
        });

        // Plain-text command lines the router script executes directly.
        return response(implode("\n", $this->pendingCommands($router)))
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Parse the router's line-based session payload:
     * kind|username|address|mac|uptime|bytesIn|bytesOut (one per line).
     *
     * @return array<int, array<string, mixed>>
     */
    private function parseSessions(string $raw): array
    {
        $sessions = [];

        foreach (preg_split('/\r\n|\r|\n/', trim($raw)) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);
            if (count($parts) < 3 || !in_array($parts[0], ['hotspot', 'pppoe'], true)) {
                continue;
            }

            $sessions[] = [
                'kind' => $parts[0],
                'username' => $parts[1] ?? '',
                'address' => $parts[2] ?? '',
                'mac_address' => $parts[3] ?? '',
                'uptime' => $parts[4] ?? '',
                'bytes_in' => (int) ($parts[5] ?? 0),
                'bytes_out' => (int) ($parts[6] ?? 0),
            ];
        }

        return $sessions;
    }

    private function authenticate(Request $request): ?Router
    {
        $token = (string) ($request->bearerToken() ?: $request->input('token', ''));

        if ($token === '') {
            return null;
        }

        return Router::where('agent_token', $token)->first();
    }

    private function applyHeartbeat(Router $router, array $data, Request $request): void
    {
        $router->forceFill([
            'identity' => $data['identity'] ?? $router->identity,
            'board_name' => $data['board_name'] ?? $router->board_name,
            'ros_version' => $data['version'] ?? $router->ros_version,
            'uptime' => $data['uptime'] ?? $router->uptime,
            'cpu_load' => $data['cpu_load'] ?? $router->cpu_load,
            'free_memory' => $data['free_memory'] ?? $router->free_memory,
            'total_memory' => $data['total_memory'] ?? $router->total_memory,
            'is_online' => true,
            'last_seen_at' => now(),
            'agent_last_seen_at' => now(),
            'agent_source_ip' => $request->ip(),
        ])->save();
    }

    private function replaceSessions(Router $router, array $sessions): void
    {
        // One timestamp per push; keep the ids updated in this batch so any
        // session the router no longer reports can be deleted afterwards.
        $now = now();
        $kept = [];

        foreach ($sessions as $session) {
            $row = RouterSession::updateOrCreate(
                [
                    'router_id' => $router->id,
                    'kind' => $session['kind'],
                    'username' => (string) $session['username'],
                    'address' => (string) ($session['address'] ?? ''),
                ],
                [
                    'mac_address' => isset($session['mac_address']) ? strtoupper($session['mac_address']) : null,
                    'uptime' => $session['uptime'] ?? null,
                    'session_time_left' => $session['session_time_left'] ?? null,
                    'bytes_in' => (int) ($session['bytes_in'] ?? 0),
                    'bytes_out' => (int) ($session['bytes_out'] ?? 0),
                    'pushed_at' => $now,
                ]
            );

            $kept[] = $row->id;
        }

        // Drop sessions this push did not include (disconnected since last time).
        RouterSession::where('router_id', $router->id)
            ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
            ->delete();
    }

    /**
     * Return pending commands as plain "command|arg1|arg2" lines and mark
     * them delivered. Commands stay delivered (not done) until the router
     * confirms on a later push — if it never does, they can be re-queued.
     *
     * @return array<int, string>
     */
    private function pendingCommands(Router $router): array
    {
        $commands = RouterAgentCommand::where('router_id', $router->id)
            ->where('status', RouterAgentCommand::STATUS_PENDING)
            ->orderBy('id')
            ->limit(20)
            ->get();

        $lines = [];

        foreach ($commands as $command) {
            $payload = $command->payload ?? [];
            $args = array_map(
                fn ($value): string => str_replace('|', ' ', (string) $value),
                array_values(array_filter($payload, fn ($value): bool => $value !== null && $value !== ''))
            );
            $lines[] = $command->command . ($args !== [] ? '|' . implode('|', $args) : '');

            $command->forceFill([
                'status' => RouterAgentCommand::STATUS_DELIVERED,
                'delivered_at' => now(),
            ])->save();
        }

        if ($lines !== []) {
            Log::info('Router agent commands delivered', [
                'router_id' => $router->id,
                'count' => count($lines),
            ]);
        }

        return $lines;
    }
}
