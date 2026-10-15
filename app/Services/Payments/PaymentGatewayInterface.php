<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Create a pending payment and return the hosted payment URL plus our payment_ref.
     *
     * @return array{payment: Payment, payment_url: string}
     */
    public function initiate(
        Client $client,
        float $amount,
        ?string $phone,
        ?string $operator,
        string $description,
        ?int $invoiceId = null,
    ): array;

    /**
     * Verify an incoming webhook payload signature.
     */
    public function verifyWebhookSignature(array $payload): bool;

    /**
     * Ask the gateway for the authoritative status of a transaction.
     *
     * @return int Gateway status code (1 success, 0 failed, -1 cancelled)
     */
    public function checkTransactionStatus(string $transactionId): int;

    /**
     * Whether the gateway is fully configured (keys present).
     */
    public function isConfigured(): bool;
}
