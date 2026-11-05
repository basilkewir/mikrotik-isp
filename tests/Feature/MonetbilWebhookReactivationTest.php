<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Router;
use App\Models\RouterActivityLog;
use App\Models\Subscription;
use App\Services\Mikrotik\MikrotikService;
use App\Services\Payments\MonetbilGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\FakeMikrotikService;
use Tests\TestCase;

/**
 * Monetbil server-to-server notification: a successful payment for a
 * subscription must reactivate the account on the router directly
 * (user enabled, quarantine lifted, expiry extended), stay idempotent,
 * and heal an activation that failed on a previous delivery.
 */
class MonetbilWebhookReactivationTest extends TestCase
{
    use RefreshDatabase;

    private FakeMikrotikService $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeMikrotikService();
        $this->app->instance(MikrotikService::class, $this->fake);

        // Signature verification and status re-check are the gateway's own
        // concern — the fake confirms the webhook and reports success.
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

    /** @return array{0: Subscription, 1: Payment} */
    private function makeRenewalPayment(array $subscriptionOverrides = [], array $paymentOverrides = []): array
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
            'start_at' => now()->subDays(31),
            'expires_at' => now()->subDay(),
            'enforcement_status' => Subscription::ENFORCEMENT_ENFORCED,
            'expired_at' => now()->subDay(),
        ], $subscriptionOverrides));

        $payment = Payment::create(array_merge([
            'client_id' => $client->id,
            'subscription_id' => $subscription->id,
            'gateway' => 'monetbil',
            'payment_ref' => 'MBL-TEST-0001',
            'item_ref' => "SUB-{$subscription->id}",
            'amount' => '5000.00',
            'currency' => 'XAF',
            'status' => Payment::STATUS_PENDING,
        ], $paymentOverrides));

        return [$subscription, $payment];
    }

    public function test_successful_payment_reactivates_the_subscription_on_the_router(): void
    {
        [, $payment] = $this->makeRenewalPayment();

        $response = $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-999',
        ]);

        $response->assertOk();
        $this->assertSame('received', $response->getContent());

        $payment->refresh();
        $this->assertSame(Payment::STATUS_SUCCESS, $payment->status);
        $this->assertSame('TX-999', $payment->operator_transaction_id);
        $this->assertNotNull($payment->paid_at);

        $subscription = $payment->subscription;
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());
        $this->assertNull($subscription->enforcement_status);

        // Router reactivated directly: re-provisioned, enabled, unfrozen.
        $this->assertTrue($this->fake->hasCall('createOrUpdateHotspotUser'));
        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));

        $this->assertTrue(
            RouterActivityLog::where('event', 'subscription_activate')
                ->where('subscription_id', $subscription->id)
                ->exists()
        );
    }

    public function test_failed_activation_is_healed_on_webhook_retry(): void
    {
        // Payment settled on a previous delivery, but the router was down
        // then — Monetbil retries and the retry must still activate.
        [, $payment] = $this->makeRenewalPayment([], [
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => now()->subMinutes(5),
            'operator_transaction_id' => 'TX-999',
        ]);

        $response = $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-999',
        ]);

        $response->assertOk();

        $subscription = $payment->subscription;
        $this->assertSame('active', $subscription->status);
        $this->assertTrue($subscription->expires_at->isFuture());

        $this->assertTrue($this->fake->hasCall('enableHotspotUser'));
        $this->assertTrue($this->fake->hasCall('unfreezeIp'));
    }

    public function test_retry_after_successful_activation_is_a_no_op(): void
    {
        [, $payment] = $this->makeRenewalPayment([
            'status' => 'active',
            'expires_at' => now()->addDays(20),
            'enforcement_status' => null,
            'expired_at' => null,
        ], [
            'status' => Payment::STATUS_SUCCESS,
            'paid_at' => now()->subMinutes(5),
            'operator_transaction_id' => 'TX-999',
        ]);

        $response = $this->post(route('payments.notify'), [
            'payment_ref' => $payment->payment_ref,
            'transaction_id' => 'TX-999',
        ]);

        $response->assertOk();
        $this->assertSame('received', $response->getContent());

        // Nothing pushed again — expiry untouched.
        $this->assertSame([], $this->fake->calls);
        $this->assertSame(
            now()->addDays(20)->toDateTimeString(),
            $payment->subscription->expires_at->toDateTimeString()
        );
    }

    public function test_unknown_payment_ref_is_acknowledged_without_side_effects(): void
    {
        $response = $this->post(route('payments.notify'), [
            'payment_ref' => 'MBL-DOES-NOT-EXIST',
            'transaction_id' => 'TX-1',
        ]);

        $response->assertOk();
        $this->assertSame('received', $response->getContent());
        $this->assertSame([], $this->fake->calls);
    }
}
