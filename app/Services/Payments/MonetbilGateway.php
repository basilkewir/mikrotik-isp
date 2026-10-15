<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class MonetbilGateway implements PaymentGatewayInterface
{
    public const OPERATORS = [
        'CM_MTNMOBILEMONEY' => 'MTN Mobile Money',
        'CM_ORANGEMONEY' => 'Orange Money',
        'CM_EUMM' => 'Express Union Mobile Money',
    ];

    private const WIDGET_URL = 'https://api.monetbil.com/widget/v2.1/';
    private const CHECK_PAYMENT_URL = 'https://api.monetbil.com/payment/v1/checkPayment';

    private const STATUS_SUCCESS = 1;
    private const STATUS_SUCCESS_TEST = 7;

    public function isConfigured(): bool
    {
        return (bool) Setting::getValue('monetbil.service_key')
            && (bool) Setting::getValue('monetbil.service_secret');
    }

    public function initiate(
        Client $client,
        float $amount,
        ?string $phone,
        ?string $operator,
        string $description,
        ?int $invoiceId = null,
    ): array {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Monetbil is not configured — set the service key and secret in Settings.');
        }

        $serviceKey = (string) Setting::getValue('monetbil.service_key');
        $serviceSecret = (string) Setting::getValue('monetbil.service_secret');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $payment = Payment::create([
            'client_id' => $client->id,
            'invoice_id' => $invoiceId,
            'gateway' => 'monetbil',
            'payment_ref' => 'MBL-' . now()->format('Ymd') . '-' . strtoupper(Str::random(10)),
            'item_ref' => $invoiceId ? "INV-{$invoiceId}" : 'WALLET-TOPUP',
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'XAF',
            'phone' => $phone,
            'operator' => $operator,
            'status' => Payment::STATUS_PENDING,
        ]);

        $params = [
            'amount' => (string) (int) round($amount),
            'country' => 'CM',
            'currency' => 'XAF',
            'item_ref' => $payment->item_ref,
            'payment_ref' => $payment->payment_ref,
            'user' => $client->client_code,
            'first_name' => explode(' ', $client->name)[0] ?? $client->name,
            'last_name' => implode(' ', array_slice(explode(' ', $client->name), 1)) ?: $client->name,
            'return_url' => "{$baseUrl}/payments/{$payment->id}/return",
            'notify_url' => "{$baseUrl}/payments/monetbil/notify",
            'logo' => (string) Setting::getValue('monetbil.logo_url', ''),
        ];

        if ($phone) {
            $params['phone'] = preg_replace('/^\+/', '', $phone);
        }

        if ($operator && array_key_exists($operator, self::OPERATORS)) {
            $params['operator'] = $operator;
        }

        $params['sign'] = $this->sign($serviceSecret, $params);

        $response = Http::asForm()
            ->timeout(30)
            ->post(self::WIDGET_URL . $serviceKey, $params);

        if ($response->failed()) {
            $payment->update(['status' => Payment::STATUS_FAILED]);

            throw new \RuntimeException("Monetbil widget API error (HTTP {$response->status()}).");
        }

        $body = $response->json();

        if (empty($body['success']) || empty($body['payment_url'])) {
            $payment->update(['status' => Payment::STATUS_FAILED, 'raw_payload' => $body]);

            throw new \RuntimeException('Monetbil did not return a payment URL: ' . json_encode($body));
        }

        $payment->update(['raw_payload' => $body]);

        return ['payment' => $payment, 'payment_url' => $body['payment_url']];
    }

    /**
     * Monetbil signature: md5(service_secret . concat(sorted param values)).
     * (Same algorithm as the official PHP SDK Monetbil::sign / checkSign.)
     */
    public function sign(string $serviceSecret, array $params): string
    {
        ksort($params);

        return md5($serviceSecret . implode('', $params));
    }

    public function verifyWebhookSignature(array $payload): bool
    {
        if (empty($payload['sign']) || !Setting::getValue('monetbil.service_secret')) {
            return false;
        }

        $sign = $payload['sign'];
        unset($payload['sign']);

        return hash_equals($this->sign((string) Setting::getValue('monetbil.service_secret'), $payload), (string) $sign);
    }

    public function checkTransactionStatus(string $transactionId): int
    {
        $response = Http::asForm()
            ->timeout(30)
            ->post(self::CHECK_PAYMENT_URL, ['paymentId' => $transactionId]);

        if ($response->failed()) {
            throw new \RuntimeException("Monetbil checkPayment failed (HTTP {$response->status()}).");
        }

        return (int) data_get($response->json(), 'transaction.status', 0);
    }

    public static function isSuccessStatus(int $status): bool
    {
        return in_array($status, [self::STATUS_SUCCESS, self::STATUS_SUCCESS_TEST], true);
    }
}
