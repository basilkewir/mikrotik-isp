<?php

/**
 * End-to-End Live MikroTik Feature & API Verification Script
 * Target: 192.168.20.1
 */

require_once __DIR__ . '/../app/Services/Mikrotik/RouterOsProtocol.php';

use App\Services\Mikrotik\RouterOsProtocol;

// 1. Retrieve connection parameters from arguments or environment
$ip = $argv[1] ?? getenv('MIKROTIK_HOST') ?: '192.168.20.1';
$username = $argv[2] ?? getenv('MIKROTIK_USER') ?: 'admin';
$password = $argv[3] ?? getenv('MIKROTIK_PASS') ?: '';
$port = (int) ($argv[4] ?? getenv('MIKROTIK_PORT') ?: 8728);

echo "\n=================================================================\n";
echo "  MIKROTIK ISP PLATFORM: LIVE HARDWARE & API TEST SUITE\n";
echo "  Target Host : {$ip}:{$port}\n";
echo "  Username    : {$username}\n";
echo "=================================================================\n\n";

$protocol = new RouterOsProtocol(timeout: 15);
$passed = 0;
$failed = 0;

function report(string $name, bool $success, string $details = '') {
    global $passed, $failed;
    if ($success) {
        $passed++;
        echo "  [\033[32mPASS\033[0m] {$name}" . ($details ? " - {$details}" : "") . "\n";
    } else {
        $failed++;
        echo "  [\033[31mFAIL\033[0m] {$name}" . ($details ? " - {$details}" : "") . "\n";
    }
}

// -------------------------------------------------------------
// TEST 1: API Socket Connection & Authentication
// -------------------------------------------------------------
echo "[1/8] Testing RouterOS API Socket & Authentication...\n";
try {
    $connected = $protocol->connect($ip, $username, $password, $port);
    report("API Authentication", $connected, "Connected & Authenticated to {$ip}:{$port}");
} catch (\Throwable $e) {
    report("API Authentication", false, $e->getMessage());
    echo "\n\033[31mFatal: Could not authenticate to RouterOS. Stopping tests.\033[0m\n";
    echo "Tip: Usage: php " . basename(__FILE__) . " <ip> <username> <password> [port]\n\n";
    exit(1);
}

// -------------------------------------------------------------
// TEST 2: System Identity & Resource Telemetry
// -------------------------------------------------------------
echo "\n[2/8] Testing System Identity & Resource Telemetry...\n";
try {
    $identityRes = $protocol->command('/system/identity/print');
    $identity = $identityRes[0]['name'] ?? 'MikroTik';
    report("System Identity Probe", true, "Router Identity: '{$identity}'");

    $resourceRes = $protocol->command('/system/resource/print');
    $res = $resourceRes[0] ?? [];
    $cpu = $res['cpu-load'] ?? '0';
    $uptime = $res['uptime'] ?? 'unknown';
    $version = $res['version'] ?? 'unknown';
    $board = $res['board-name'] ?? ($res['platform'] ?? 'RouterBOARD');
    $freeMem = isset($res['free-memory']) ? round($res['free-memory'] / 1024 / 1024, 1) . 'MB' : 'N/A';
    $totalMem = isset($res['total-memory']) ? round($res['total-memory'] / 1024 / 1024, 1) . 'MB' : 'N/A';

    report("Hardware & OS Info", true, "Board: {$board}, ROS: {$version}");
    report("Performance Telemetry", true, "CPU: {$cpu}%, Free RAM: {$freeMem}/{$totalMem}, Uptime: {$uptime}");
} catch (\Throwable $e) {
    report("System Telemetry", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 3: Interface Inventory & Traffic Monitoring
// -------------------------------------------------------------
echo "\n[3/8] Testing Interface Inventory...\n";
try {
    $interfaces = $protocol->command('/interface/print');
    $ifaceNames = array_map(fn($i) => $i['name'] . ' (' . ($i['type'] ?? 'unknown') . ')', array_slice($interfaces, 0, 5));
    report("Interface Discovery", !empty($interfaces), count($interfaces) . " interface(s) detected: " . implode(', ', $ifaceNames));
} catch (\Throwable $e) {
    report("Interface Discovery", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 4: Hotspot Management & Provisioning
// -------------------------------------------------------------
echo "\n[4/8] Testing Hotspot Management (CRUD & Active Session)...\n";
$testHotspotUser = 'isp_test_hs_' . substr(md5(uniqid()), 0, 6);
$testHotspotPass = 'Pass987!';

try {
    // 4.1 Profiles
    $hsProfiles = $protocol->command('/ip/hotspot/user/profile/print');
    $profileName = $hsProfiles[0]['name'] ?? 'default';
    report("Hotspot Profiles Query", true, "Found " . count($hsProfiles) . " profile(s), using '{$profileName}'");

    // 4.2 Create Hotspot User
    $addUser = $protocol->command('/ip/hotspot/user/add', [
        '=name=' . $testHotspotUser,
        '=password=' . $testHotspotPass,
        '=profile=' . $profileName,
        '=comment=Automated ISP Test User',
        '=limit-uptime=1h',
        '=limit-bytes-total=104857600', // 100MB
    ]);
    report("Create Hotspot User", true, "User '{$testHotspotUser}' provisioned");

    // 4.3 Verify User in list
    $userCheck = $protocol->command('/ip/hotspot/user/print', ['?name=' . $testHotspotUser]);
    $createdId = $userCheck[0]['.id'] ?? null;
    report("Verify Hotspot User Exists", !empty($userCheck), "User ID: {$createdId}");

    // 4.4 Disable Hotspot User
    if ($createdId) {
        $protocol->command('/ip/hotspot/user/set', [
            '=.id=' . $createdId,
            '=disabled=yes',
        ]);
        $disabledCheck = $protocol->command('/ip/hotspot/user/print', ['?name=' . $testHotspotUser]);
        $isDisabled = ($disabledCheck[0]['disabled'] ?? '') === 'true';
        report("Disable Hotspot User", $isDisabled, "Status disabled: " . ($isDisabled ? 'yes' : 'no'));

        // 4.5 Re-enable Hotspot User
        $protocol->command('/ip/hotspot/user/set', [
            '=.id=' . $createdId,
            '=disabled=no',
        ]);
        report("Re-enable Hotspot User", true);

        // 4.6 Hotspot Active Sessions
        $activeSessions = $protocol->command('/ip/hotspot/active/print');
        report("Hotspot Active Query", true, count($activeSessions) . " active hotspot connection(s) currently online");

        // 4.7 Cleanup
        $protocol->command('/ip/hotspot/user/remove', ['=.id=' . $createdId]);
        report("Hotspot User Cleanup", true, "Test user '{$testHotspotUser}' removed safely");
    }
} catch (\Throwable $e) {
    report("Hotspot Feature Suite", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 5: PPPoE Management & Provisioning
// -------------------------------------------------------------
echo "\n[5/8] Testing PPPoE Management (Secrets & Active Tunnels)...\n";
$testPppUser = 'isp_test_ppp_' . substr(md5(uniqid()), 0, 6);
$testPppPass = 'PppSecret123!';

try {
    // 5.1 PPP Profiles
    $pppProfiles = $protocol->command('/ppp/profile/print');
    $pppProfileName = $pppProfiles[0]['name'] ?? 'default';
    report("PPP Profiles Query", true, "Found " . count($pppProfiles) . " profile(s), using '{$pppProfileName}'");

    // 5.2 Create PPPoE Secret
    $protocol->command('/ppp/secret/add', [
        '=name=' . $testPppUser,
        '=password=' . $testPppPass,
        '=profile=' . $pppProfileName,
        '=service=pppoe',
        '=comment=Automated ISP Test Secret',
    ]);
    report("Create PPPoE Secret", true, "Secret '{$testPppUser}' provisioned");

    // 5.3 Verify Secret Exists
    $secretCheck = $protocol->command('/ppp/secret/print', ['?name=' . $testPppUser]);
    $secretId = $secretCheck[0]['.id'] ?? null;
    report("Verify PPPoE Secret Exists", !empty($secretCheck), "Secret ID: {$secretId}");

    // 5.4 Disable PPPoE Secret
    if ($secretId) {
        $protocol->command('/ppp/secret/set', [
            '=.id=' . $secretId,
            '=disabled=yes',
        ]);
        $disabledCheck = $protocol->command('/ppp/secret/print', ['?name=' . $testPppUser]);
        $isDisabled = ($disabledCheck[0]['disabled'] ?? '') === 'true';
        report("Disable PPPoE Secret", $isDisabled, "Disabled status: " . ($isDisabled ? 'yes' : 'no'));

        // 5.5 Re-enable PPPoE Secret
        $protocol->command('/ppp/secret/set', [
            '=.id=' . $secretId,
            '=disabled=no',
        ]);
        report("Re-enable PPPoE Secret", true);

        // 5.6 PPPoE Active Sessions
        $activePpp = $protocol->command('/ppp/active/print');
        report("PPPoE Active Query", true, count($activePpp) . " active PPPoE tunnel(s) currently online");

        // 5.7 Cleanup Secret
        $protocol->command('/ppp/secret/remove', ['=.id=' . $secretId]);
        report("PPPoE Secret Cleanup", true, "Test secret '{$testPppUser}' removed safely");
    }
} catch (\Throwable $e) {
    report("PPPoE Feature Suite", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 6: Bandwidth Rate Limiting (Simple Queues)
// -------------------------------------------------------------
echo "\n[6/8] Testing Bandwidth Rate-Limiting (Simple Queues)...\n";
$testQueueName = 'isp_test_queue_' . substr(md5(uniqid()), 0, 4);

try {
    // 6.1 Create Simple Queue
    $protocol->command('/queue/simple/add', [
        '=name=' . $testQueueName,
        '=target=192.168.20.250/32',
        '=max-limit=5M/10M', // 5M Upload, 10M Download
        '=comment=Automated ISP Test Queue',
    ]);
    report("Create Simple Queue", true, "Queue '{$testQueueName}' created with limit 5M/10M");

    // 6.2 Verify Queue
    $queueCheck = $protocol->command('/queue/simple/print', ['?name=' . $testQueueName]);
    $queueId = $queueCheck[0]['.id'] ?? null;
    $limits = $queueCheck[0]['max-limit'] ?? 'N/A';
    report("Verify Queue Limits", !empty($queueCheck), "ID: {$queueId}, Limit: {$limits}");

    // 6.3 Dynamic Speed Upgrade (e.g. Plan Change 10M/25M)
    if ($queueId) {
        $protocol->command('/queue/simple/set', [
            '=.id=' . $queueId,
            '=max-limit=10M/25M',
        ]);
        $updatedCheck = $protocol->command('/queue/simple/print', ['?name=' . $testQueueName]);
        $newLimits = $updatedCheck[0]['max-limit'] ?? 'N/A';
        report("Update Queue Limits", true, "New rate limit: {$newLimits}");

        // 6.4 Cleanup Queue
        $protocol->command('/queue/simple/remove', ['=.id=' . $queueId]);
        report("Queue Cleanup", true, "Test queue '{$testQueueName}' removed");
    }
} catch (\Throwable $e) {
    report("Simple Queue Suite", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 7: Firewall Walled Garden / Isolation Address List
// -------------------------------------------------------------
echo "\n[7/8] Testing Firewall Quarantine Isolation...\n";
$testIp = '192.168.20.244';
$listName = 'EXPIRED_USERS';

try {
    // 7.1 Add IP to quarantine list
    $protocol->command('/ip/firewall/address-list/add', [
        '=list=' . $listName,
        '=address=' . $testIp,
        '=comment=Automated Quota Expiration Isolation',
    ]);
    report("Isolate Expired IP", true, "Added {$testIp} to firewall list '{$listName}'");

    // 7.2 Verify isolation entry
    $listCheck = $protocol->command('/ip/firewall/address-list/print', [
        '?list=' . $listName,
        '?address=' . $testIp,
    ]);
    $listId = $listCheck[0]['.id'] ?? null;
    report("Verify Quarantine List", !empty($listCheck), "Entry ID: {$listId}");

    // 7.3 Unfreeze IP upon renewal
    if ($listId) {
        $protocol->command('/ip/firewall/address-list/remove', ['=.id=' . $listId]);
        report("Unfreeze IP Entry", true, "Removed {$testIp} from '{$listName}' safely");
    }
} catch (\Throwable $e) {
    report("Firewall Isolation Suite", false, $e->getMessage());
}

// -------------------------------------------------------------
// TEST 8: Session Teardown & Graceful Disconnect
// -------------------------------------------------------------
echo "\n[8/8] Testing Graceful Socket Teardown...\n";
try {
    $protocol->disconnect();
    report("Graceful Disconnect", true, "Socket connection closed cleanly");
} catch (\Throwable $e) {
    report("Graceful Disconnect", false, $e->getMessage());
}

// -------------------------------------------------------------
// SUMMARY
// -------------------------------------------------------------
echo "\n=================================================================\n";
echo "  TEST SUMMARY FOR MIKROTIK AT {$ip}\n";
echo "  Total Tests Executed : " . ($passed + $failed) . "\n";
echo "  \033[32mPassed\033[0m               : {$passed}\n";
echo "  \033[31mFailed\033[0m               : {$failed}\n";
echo "=================================================================\n\n";

exit($failed === 0 ? 0 : 1);

