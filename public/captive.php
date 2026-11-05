<?php

/**
 * OrbitLink captive portal — standalone front controller.
 *
 * Run with the PHP built-in server so that EVERY path lands on the portal
 * (a captive portal must answer any URL the client asks for):
 *
 *   php -S 0.0.0.0:8080 -t public public/captive.php
 *
 * The MikroTik redirects every hotspot-bridge HTTP(S) request here
 * (dst-nat -> 172.31.0.20:8080), so any device that joins the OrbitLink
 * hotspot lands on this page. Access is granted by a voucher code; once a
 * device is provisioned the platform puts its IP in the router's
 * "active-clients" allow-list, the router stops intercepting it and the
 * client is online.
 */

declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', '/tmp/captive_php_errors.log');

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

set_exception_handler(function (\Throwable $e): void {
    file_put_contents('/tmp/captive_php_errors.log',
        get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n"
        . $e->getTraceAsString() . "\n", FILE_APPEND);
    http_response_code(500);
    echo '<p>portal error: ' . htmlspecialchars($e->getMessage()) . '</p>';
});

use App\Models\Client;
use App\Models\Device;
use App\Models\Router;
use App\Models\Voucher;
use App\Services\Mikrotik\RouterOsProtocol;
use App\Support\DaysRemaining;
use Illuminate\Support\Facades\DB;

const ISP_NAME = 'OrbitLink';
const ALLOW_LIST = 'active-clients';

function json_out(array $payload, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/**
 * Connect to the router that serves the given client IP.
 *
 * The DHCP lease table and the allow-list are cached briefly: the portal is
 * hit by every client on the hotspot and the RouterOS API round trip is far
 * too slow to do on every page view.
 */
function router_for(string $clientIp): array
{
    // Per-client key: MAC and allow-list state belong to one client, not the hotspot.
    $cacheKey = 'captive:router-state:' . $clientIp;
    if ($cached = cache()->get($cacheKey)) {
        // Reload the router on a cache hit: the voucher POST handler needs it.
        $cachedRouter = isset($cached['router_id']) ? Router::find($cached['router_id']) : null;
        return $cached + ['router' => $cachedRouter, 'api' => null];
    }

    // Try the router on the client's own subnet first: an unreachable router
    // otherwise stalls every request for the full connect timeout.
    $prefix = implode('.', array_slice(explode('.', $clientIp), 0, 3)) . '.';
    $candidates = Router::orderBy('id')->get()
        ->sortBy(fn ($r) => str_starts_with((string) $r->ip_address, $prefix) ? 0 : 1);

    foreach ($candidates as $router) {
        $api = new RouterOsProtocol();
        try {
            // api_password is cast as 'encrypted' on the model, so it is already plaintext.
            $api->connect($router->ip_address, $router->api_username, $router->api_password,
                $router->api_port, (bool) $router->use_ssl);
        } catch (\Throwable $e) {
            continue;
        }
        $subnet = null;
        foreach ($api->command('/ip/address/print') as $row) {
            if (str_starts_with((string) ($row['address'] ?? ''), '192.168.88.')) {
                $subnet = '192.168.88.0/24';
            }
        }
        if ($subnet === null) {
            $api->close();
            continue;
        }
        $mac = null;
        foreach ($api->command('/ip/dhcp-server/lease/print') as $row) {
            if (($row['address'] ?? null) === $clientIp && !empty($row['mac-address'])) {
                $mac = strtoupper($row['mac-address']);
            }
        }
        $allowed = false;
        foreach ($api->command('/ip/firewall/address-list/print', ['?list=' . ALLOW_LIST]) as $row) {
            if (($row['address'] ?? null) === $clientIp) {
                $allowed = true;
            }
        }
        $state = ['mac' => $mac, 'allowed' => $allowed, 'router_id' => $router->id];
        cache()->put($cacheKey, $state, 15);
        return $state + ['router' => $router, 'api' => $api];
    }

    return ['router' => null, 'api' => null, 'mac' => null, 'allowed' => false];
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
if ($clientIp === '' || $clientIp === '::1') {
    $clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '')[0];
}

// ------------------------------------------------- countdown endpoint (JSON)
// The router's hotspot login/status pages call this by username to render the
// bilingual "N days left" notice. Read-only; no voucher is consumed here.
if (($_GET['action'] ?? '') === 'remaining') {
    $lookupUser = trim((string) ($_GET['username'] ?? ''));
    $lookupMac = strtoupper(trim((string) ($_GET['mac'] ?? '')));
    $expiresAt = null;

    if ($lookupUser !== '') {
        $sub = \App\Models\Subscription::where('username', $lookupUser)->latest('id')->first();
        $expiresAt = $sub?->expires_at;
    }

    if ($expiresAt === null && $lookupMac !== '') {
        $dev = Device::where('mac_address', $lookupMac)->latest('id')->first();
        $expiresAt = $dev?->access_expires_at;
    }

    $remaining = DaysRemaining::for($expiresAt);

    json_out([
        'days' => $remaining['days'],
        'expired' => $remaining['expired'],
        'en' => $remaining['en'],
        'fr' => $remaining['fr'],
        'until' => $expiresAt ? $expiresAt->format('j M Y') : null,
    ]);
}

$conn = router_for($clientIp);
$router = $conn['router'];
$mac = $conn['mac'];
$allowed = $conn['allowed'];

// A device that is already online still needs to see its countdown on reload.
$connectedRemaining = null;
$connectedUntil = null;

if ($allowed && $router && $mac) {
    $online = Device::where('router_id', $router->id)
        ->where('mac_address', $mac)
        ->where('is_active', true)
        ->first();

    if ($online && $online->access_expires_at) {
        $connectedRemaining = DaysRemaining::for($online->access_expires_at);
        $connectedUntil = $online->access_expires_at->format('j M Y');
    }
}

// ------------------------------------------------------------------ voucher login
$message = null;
$messageType = 'info';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && $router) {
    $voucherCode = strtoupper(trim((string) ($_POST['voucher'] ?? '')));
    $voucher = $voucherCode !== '' ? Voucher::where('code', $voucherCode)->first() : null;
    $plan = $voucher?->plan;

    if ($voucherCode === '') {
        $message = 'Please enter your voucher code.';
        $messageType = 'error';
    } elseif (! $voucher || $voucher->status !== Voucher::STATUS_ACTIVE) {
        $message = 'That voucher is not valid or has already been used.';
        $messageType = 'error';
    } elseif (! $plan) {
        $message = 'That voucher is not linked to a package. Please contact OrbitLink support.';
        $messageType = 'error';
    } elseif (! $mac) {
        $message = 'We could not read the MAC address of your device. Reconnect to the ' . ISP_NAME . ' network and try again.';
        $messageType = 'error';
    } else {
        // Claim the voucher and save the client atomically. The conditional
        // update means two simultaneous logins cannot both use one voucher.
        $client = DB::transaction(function () use ($voucher) {
            // The portal collects only the voucher; clients.name is required,
            // so the client is recorded under the voucher code.
            $client = new Client();
            $client->name = 'Voucher ' . $voucher->code;
            if (empty($client->client_code)) {
                $client->client_code = 'ORB-' . strtoupper(bin2hex(random_bytes(3)));
            }
            $client->status = $client->status ?: 'active';
            $client->save();

            $claimed = Voucher::where('id', $voucher->id)
                ->where('status', Voucher::STATUS_ACTIVE)
                ->update([
                    'status' => Voucher::STATUS_REDEEMED,
                    'redeemed_by' => $client->id,
                    'redeemed_at' => now(),
                ]);

            return $claimed === 1 ? $client : null;
        });

        if (! $client) {
            $message = 'That voucher was just used by someone else.';
            $messageType = 'error';
        } else {
            $validity = (int) ($plan->validity_days ?: 30);
            $type = \App\Enums\DeviceType::Cpe;
            [$days, $expiresAt] = Device::resolveAccessExpiry($validity, null, $type->isCustomerEdge());

            // One device per MAC on this router — refresh it on a new voucher
            // instead of failing on the unique key.
            $device = Device::where('router_id', $router->id)
                ->where('mac_address', $mac)
                ->first();

            $attributes = [
                'client_id' => $client->id,
                'router_id' => $router->id,
                'mac_address' => $mac,
                'ip_address' => $clientIp,
                'hostname' => (string) ($_POST['hostname'] ?? ''),
                'type' => $type->value,
                'is_active' => true,
                'access_duration_days' => $days,
                'access_expires_at' => $expiresAt,
                'comment' => 'Voucher ' . $voucher->code,
            ];

            if ($device) {
                $device->update($attributes);
            } else {
                $device = Device::create($attributes);
            }

            // Platform path: allow-list + static DHCP lease + L2 MAC gate.
            $device->provisionLanAccess();

            $remaining = DaysRemaining::for($expiresAt);
            $until = $expiresAt ? $expiresAt->format('j M Y') : null;
            $message = 'You are connected to ' . ISP_NAME . ': ' . $remaining['en']
                . ($until ? ' (until ' . $until . ')' : '') . '.'
                . ' — Vous êtes connecté à ' . ISP_NAME . ' : ' . $remaining['fr']
                . ($until ? ' (jusqu’au ' . $until . ')' : '') . '.';
            $messageType = 'success';
            $allowed = true;
        }
    }
}

// ------------------------------------------------------------------ page
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= e(ISP_NAME) ?> — WiFi Hotspot</title>
<style>
  :root { --brand:#0b3d91; --brand2:#1e6fd9; --ink:#12233d; --muted:#5b6b82; }
  * { box-sizing:border-box; }
  body { margin:0; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
         background:linear-gradient(160deg,#0b3d91 0%,#1e6fd9 55%,#4aa3ff 100%); color:var(--ink); }
  .wrap { max-width:460px; margin:0 auto; padding:36px 18px 60px; }
  .card { background:#fff; border-radius:18px; padding:26px 26px 30px; box-shadow:0 18px 50px rgba(6,26,64,.28); }
  .brand { display:flex; align-items:center; justify-content:center; gap:14px; margin-bottom:18px; }
  .logo { width:52px; height:52px; border-radius:14px; background:linear-gradient(135deg,#0b3d91,#4aa3ff);
          display:grid; place-items:center; color:#fff; font-weight:800; font-size:22px; }
  h1 { font-size:24px; margin:0; letter-spacing:-.4px; }
  .msg { border-radius:12px; padding:12px 15px; font-size:14px; margin-bottom:18px; }
  .msg.success { background:#e8f8ee; color:#166534; border:1px solid #bfe9cd; }
  .msg.error { background:#fff2f2; color:#9f1239; border:1px solid #f6c9c9; }
  form { display:grid; gap:12px; }
  label { font-size:13px; color:var(--muted); display:block; margin-bottom:4px; }
  input { width:100%; padding:12px 13px; border:1px solid #cfdcef; border-radius:10px; font-size:16px; background:#fff; }
  input:focus { outline:2px solid #a9c8f7; border-color:var(--brand2); }
  button { padding:14px; border:0; border-radius:11px; background:linear-gradient(135deg,#0b3d91,#1e6fd9);
           color:#fff; font-size:16px; font-weight:600; cursor:pointer; margin-top:4px; }
  button:hover { filter:brightness(1.08); }
  .foot { text-align:center; color:#dce8fb; font-size:12.5px; margin-top:20px; }
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <div class="brand">
      <div class="logo">O</div>
      <h1><?= e(ISP_NAME) ?></h1>
    </div>

    <?php if ($message): ?>
      <div class="msg <?= e($messageType) ?>"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if (! $allowed): ?>
      <form method="post" action="">
        <div>
          <label for="voucher">Voucher code</label>
          <input id="voucher" name="voucher" required autocapitalize="characters" autocomplete="off" placeholder="e.g. A7K9Q2XM3P">
        </div>
        <button type="submit">Login with voucher</button>
      </form>
    <?php else: ?>
      <div class="msg success">
        <strong>You are connected.</strong> — <strong>Vous êtes connecté.</strong>
        <?php if ($connectedRemaining && ! $connectedRemaining['expired']): ?>
          <div style="margin-top:6px">
            <?= e($connectedRemaining['en']) ?><?= $connectedUntil ? ' (until ' . e($connectedUntil) . ')' : '' ?> ·
            <?= e($connectedRemaining['fr']) ?><?= $connectedUntil ? ' (jusqu&rsquo;au ' . e($connectedUntil) . ')' : '' ?>
          </div>
        <?php elseif ($connectedRemaining && $connectedRemaining['expired']): ?>
          <div style="margin-top:6px">
            <?= e($connectedRemaining['en']) ?> · <?= e($connectedRemaining['fr']) ?>
          </div>
        <?php else: ?>
          <div style="margin-top:6px">Browse freely — the network will cut off when the voucher period expires.</div>
        <?php endif; ?>
      </div>
      <?php if ($connectedRemaining && ! $connectedRemaining['expired'] && $connectedRemaining['days'] <= 3): ?>
        <?php $d = $connectedRemaining['days']; ?>
        <div class="msg error">
          <strong>Reminder</strong> — Your internet ends <?= $d === 0 ? 'today' : 'in ' . $d . ' day' . ($d === 1 ? '' : 's') ?>. Renew to stay online.
          <br>
          <strong>Rappel</strong> — Votre internet se termine <?= $d === 0 ? "aujourd'hui" : 'dans ' . $d . ' jour' . ($d === 1 ? '' : 's') ?>. Renouvelez pour rester en ligne.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <div class="foot"><?= e(ISP_NAME) ?> · authorised devices are tracked by MAC address</div>
</div>
</body>
</html>
