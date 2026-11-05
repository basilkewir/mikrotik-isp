<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikService;
use App\Services\Payments\MonetbilGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

/**
 * Gateway-agnostic settlement: a confirmed wallet payment credits the
 * ledger and cascades into armed (auto_renew) subscriptions that are due —
 * including ones already flipped to 'expired' by the enforcement pass.
 */
class PaymentSettlementTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);

        $gateway = new class extends MonetbilGateway
        {
            public function verifyWebhookSignature(array $payload): bool
            {
                return true;
            }

            public function checkTransactionStatus(string $transactionId): int
            {
                return 1;
            }
        };
        $this->app->instance(MonetbilGateway::class, $gateway);

        Carbon::setTestNow(Carbon::parse('2026-10-18 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Client, 1: Subscription} */
    private function makeClientWithDueSubscription(array $subscriptionOverrides = [], float $balance = 0): array
    {
        $router = Router::create([
            'name' => 'Test Router',
            'ip_address' => '192.0.2.1',
            'api_port' => 8728,
            'api_username' => 'admin',
            'api_password' => 'secret',
        ]);

        $client = Client::create([
            'client_code' => 'CLI-TEST-1',
            'name' => 'Test Customer',
            'balance' => $balance,
        ]);

        $plan = Plan::create([
            'name' => '30 Days',
            'code' => 'T30',
            'validity_days' => 30,
            'price' => 5000,
        ]);

        $subscription = Subscription::create(array_merge([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => 'cust1',
            'password' => 'pw',
            'assigned_ip' => '192.168.10.50',
            'status' => 'expired',
            'auto_renew' => true,
            'start_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
            'enforcement_status' => Subscription::ENFORCEMENT_ENFORCED,
            'expired_at' => now()->subDay(),
        ], $subscriptionOverrides));

        return [$client, $subscription];
    }

    private function makeTopUpPayment(Client $client, float $amount): Payment
    {
        return Payment::create([
            'client_id' => $client->id,
            'gateway' => 'monetbil',
            'payment_ref' => 'MBL-TOPUP-0001',
            'item_ref' => 'WALLET-TOPUP',
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'XAF',
            'status' => Payment::STATUS_PENDING,
        ]);
    }

    public function test_wallet_topup_cascades_into_an_armed_auto_renewal(): void
    {
        [$client, $subscription] = $this->makeClientWithDueSubscription();
        $payment = $this->makeTopUpPayment($client, 5000);

        $response = $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-100',
        ]);

        $response->assertOk();
        $this->assertSame(Payment::STATUS_SUCCESS, $payment->refresh()->status);

        // Wallet credited, then the armed renewal debited it again.
        $this->assertSame('0.00', $client->refresh()->balance);

        // Subscription reactivated directly — no cron involved.
        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertNull($subscription->enforcement_status);

        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));
    }

    public function test_wallet_topup_with_insufficient_balance_only_credits(): void
    {
        [$client, $subscription] = $this->makeClientWithDueSubscription();
        $payment = $this->makeTopUpPayment($client, 100);

        $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-101',
        ])->assertOk();

        $this->assertSame('100.00', $client->refresh()->balance);

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertFalse($this->fake->hasCall('enableHotspotUser'));
    }

    public function test_wallet_topup_does_not_touch_subscriptions_without_auto_renew(): void
    {
        [$client, $subscription] = $this->makeClientWithDueSubscription(['auto_renew' => false]);
        $payment = $this->makeTopUpPayment($client, 5000);

        $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-102',
        ])->assertOk();

        $this->assertSame('5000.00', $client->refresh()->balance);

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertSame([], $this->fake->calls);
    }

    public function test_auto_renew_command_renews_enforced_subscriptions(): void
    {
        // The 1-minute enforcement pass flips status to 'expired' long
        // before the hourly cron runs — the cron must still pick it up.
        [, $subscription] = $this->makeClientWithDueSubscription([], 10000);

        $this->artisan('isp:renew-from-wallet')->assertSuccessful();

        $subscription->refresh();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertSame('5000.00', $subscription->client->refresh()->balance);

        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));
    }
}
