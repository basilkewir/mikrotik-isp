<?php

namespace App\Services\Payments;

use App\Models\Client;
use App\Models\Payment;

/**
 * Contract for hosted payment gateways.
 *
 * Each gateway verifies its own webhook and re-checks the transaction
 * status on its own terms — then, on confirmed success, MUST hand the
 * Payment row to PaymentSettlementService::settle(). Settlement is
 * gateway-agnostic: subscription payments renew and reactivate the account
 * on the router immediately; wallet payments credit the ledger and cascade
 * into armed (auto_renew) subscriptions.
 */
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
        ?int $subscriptionId = null,
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
