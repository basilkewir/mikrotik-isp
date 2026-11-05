<?php

namespace App\Services\Mikrotik;

use App\Models\Router;

/**
 * Generates the RouterOS script that runs on the router to push status and
 * pull commands from the platform (reverse link, for routers the platform
 * cannot reach directly).
 *
 * The endpoint returns a plain-text command list (one "command|arg1|arg2"
 * per line) instead of JSON: RouterOS has no built-in JSON parser, so a flat
 * protocol keeps the router script small and reliable. Supported commands:
 *
 *   disable_hotspot_user|<name>
 *   enable_hotspot_user|<name>
 *   kick_hotspot_user|<name>
 *   block_ip|<ip>            (adds to EXPIRED_USERS + drop)
 *   unblock_ip|<ip>
 */
class RouterAgentScript
{
    public function build(Router $router, string $baseUrl): string
    {
        $token = $router->ensureAgentToken();
        $url = rtrim($baseUrl, '/') . '/api/router-agent/sync';

        return <<<ROUTEROS
# --- MikroTik ISP agent bridge (router -> VPS) ---
# Schedule this every minute. It pushes status + sessions and runs queued
# commands returned by the platform.

:local agentUrl "{$url}"
:local agentToken "{$token}"

:local identity [/system identity get name]
:local boardName [/system resource get board-name]
:local version [/system resource get version]
:local uptime [/system resource get uptime]
:local cpuLoad [/system resource get cpu-load]
:local freeMem [/system resource get free-memory]
:local totalMem [/system resource get total-memory]

# Active sessions, one per line: kind|username|address|mac|uptime|bytesIn|bytesOut
:local sessions ""
:foreach a in=[/ip hotspot active find] do={
  :local u [/ip hotspot active get \$a user]
  :local addr [/ip hotspot active get \$a address]
  :local mac [/ip hotspot active get \$a mac-address]
  :local up [/ip hotspot active get \$a uptime]
  :local bin [/ip hotspot active get \$a bytes-in]
  :local bout [/ip hotspot active get \$a bytes-out]
  :set sessions (\$sessions . "hotspot|\$u|\$addr|\$mac|\$up|\$bin|\$bout\n")
}
:foreach s in=[/ppp active find] do={
  :local u [/ppp active get \$s name]
  :local addr [/ppp active get \$s address]
  :local up [/ppp active get \$s uptime]
  :local bin [/ppp active get \$s bytes-in]
  :local bout [/ppp active get \$s bytes-out]
  :set sessions (\$sessions . "pppoe|\$u|\$addr||\$up|\$bin|\$bout\n")
}

# Body is form-encoded to avoid JSON quoting entirely.
:local body ("identity=\$identity&board_name=\$boardName&version=\$version&uptime=\$uptime&cpu_load=\$cpuLoad&free_memory=\$freeMem&total_memory=\$totalMem&sessions=" . \$sessions)

:local res [/tool fetch url=\$agentUrl http-method=post \\
  http-header-field=("Authorization: Bearer " . \$agentToken) \\
  http-data=\$body output=user as-value]

:local resp ""
:if ([:typeof \$res] = "array") do={ :set resp (\$res->"data") } else={ :set resp \$res }

# Response lines: command|arg1|arg2  (empty when nothing queued)
:if ([:len \$resp] > 0) do={
  :foreach line in=[:toarray \$resp] do={
    :local parts [:toarray \$line]
    :local cmd [:pick \$parts 0]
    :local a1 [:pick \$parts 1]
    :local a2 [:pick \$parts 2]
    :if (\$cmd = "disable_hotspot_user") do={ /ip hotspot user disable [find name=\$a1] }
    :if (\$cmd = "enable_hotspot_user") do={ /ip hotspot user enable [find name=\$a1] }
    :if (\$cmd = "kick_hotspot_user") do={ /ip hotspot active remove [find user=\$a1] }
    :if (\$cmd = "block_ip") do={
      /ip firewall address-list add list=EXPIRED_USERS address=\$a1 comment="agent-block"
      /ip firewall filter add chain=forward action=drop src-address=\$a1 comment=("agent-block " . \$a1)
    }
    :if (\$cmd = "unblock_ip") do={
      /ip firewall address-list remove [find list=EXPIRED_USERS address=\$a1]
      /ip firewall filter remove [find comment~("agent-block " . \$a1)]
    }
    :log info ("agent: ran " . \$line)
  }
}
ROUTEROS;
    }
}
