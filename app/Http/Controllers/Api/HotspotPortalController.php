<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Setting;
use App\Models\Subscription;
use App\Services\Payments\MonetbilGateway;
use Hash;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Public signup API for the captive-portal pages running on the MikroTik
 * hotspot (e.g. RB951Ui): list plans, create the client + subscription and
 * provision the hotspot user, then hand back a Mobile Money payment URL.
 *
 * No free ride: the subscription is created already lapsed — the 1-minute
 * enforcement pass keeps the account cut until the Monetbil webhook settles
 * the linked payment, which renews and reactivates the account directly
 * (PaymentSettlementService).
 */
class HotspotPortalController extends Controller
{
    public function plans(): JsonResponse
    {
        $plans = Plan::query()
            ->where('is_active', true)
            ->whereIn('service_type', ['hotspot', 'both'])
            ->orderBy('name')
            ->get(['id', 'name', 'price', 'validity_days']);

        return response()->json(['plans' => $plans]);
    }

    public function register(Request $request, MonetbilGateway $gateway): JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer|exists:plans,id',
            'username' => 'required|string|min:3|max:32|regex:/^[A-Za-z0-9._-]+$/',
            'password' => 'required|string|min:4|max:64',
            'phone' => 'nullable|string|max:30',
            'name' => 'nullable|string|max:150',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        if (!$plan->is_active) {
            return response()->json(['message' => 'This plan is not available.'], 422);
        }

        $router = $this->portalRouter();

        if (!$router) {
            return response()->json(['message' => 'No router is configured yet — please contact support.'], 503);
        }

        $taken = Subscription::query()
            ->where('router_id', $router->id)
            ->where('auth_type', 'hotspot')
            ->where('username', $validated['username'])
            ->exists();

        if ($taken) {
            return response()->json(['message' => 'That username is already taken — please choose another.'], 422);
        }

        $client = $this->resolveClient($validated);

        $subscription = Subscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'router_id' => $router->id,
            'auth_type' => 'hotspot',
            'username' => $validated['username'],
            'password' => $validated['password'],
            'status' => 'active',
            'start_at' => now(),
            // Already lapsed: access starts only when the linked payment
            // settles (the webhook renews + reactivates within seconds).
            'expires_at' => now(),
            'data_quota_bytes' => $plan->data_limit_bytes,
            'data_used_bytes' => 0,
        ]);

        try {
            $subscription->provisionOnRouter();
        } catch (\Exception $exception) {
            Log::warning("Hotspot signup for {$subscription->username}: router provisioning failed: " . $exception->getMessage());
        }

        $paymentUrl = null;

        if ($gateway->isConfigured()) {
            try {
                $result = $gateway->initiate(
                    $client,
                    (float) $plan->price,
                    $validated['phone'] ?? null,
                    null,
                    "Signup {$subscription->username} ({$plan->name})",
                    null,
                    $subscription->id,
                );

                $paymentUrl = $result['payment_url'];
            } catch (\RuntimeException $exception) {
                Log::warning("Hotspot signup for {$subscription->username}: payment initiation failed: " . $exception->getMessage());
            }
        }

        return response()->json([
            'ok' => true,
            'username' => $subscription->username,
            'client_code' => $client->client_code,
            'payment_url' => $paymentUrl,
            'message' => $paymentUrl
                ? 'Account created. Complete the Mobile Money payment to activate it.'
                : 'Account created. Please complete payment to activate it.',
        ], 201);
    }

    /** The router that serves this captive portal (setting wins, else the only/first router). */
    private function portalRouter(): ?Router
    {
        $configured = Setting::getValue('hotspot.default_router_id');

        if ($configured && $router = Router::find($configured)) {
            return $router;
        }

        return Router::query()->orderBy('id')->first();
    }

    /** @param array{username: string, password: string, phone?: string, name?: string} $validated */
    private function resolveClient(array $validated): Client
    {
        if (!empty($validated['phone'])) {
            $existing = Client::where('phone', $validated['phone'])->first();

            if ($existing) {
                return $existing;
            }
        }

        return Client::create([
            'client_code' => Client::generateClientCode(),
            'name' => $validated['name'] ?? $validated['username'],
            'phone' => $validated['phone'] ?? null,
            'portal_password' => Hash::make($validated['password']),
            'portal_password_set_at' => now(),
        ]);
    }
}
