<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\DaysRemaining;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class PortalDashboardController extends Controller
{
    public function index(): Response
    {
        /** @var Client $client */
        $client = Auth::guard('client')->user();

        $client->load([
            'subscriptions' => fn ($q) => $q->with('plan')->latest('id'),
            'devices' => fn ($q) => $q->latest('id'),
            'walletTransactions' => fn ($q) => $q->latest('id')->limit(15),
            'invoices' => fn ($q) => $q->with('items')->latest('id')->limit(10),
            'reminders' => fn ($q) => $q->with('subscription.plan')->latest('id')->limit(10),
        ]);

        // Bilingual day countdown per subscription, shown at login.
        $client->subscriptions->each(function ($subscription): void {
            $subscription->days_remaining = DaysRemaining::for($subscription->expires_at);
        });

        $client->reminders->each(function ($reminder): void {
            $reminder->message_i18n = $reminder->message();
        });

        return Inertia::render('Portal/Dashboard', [
            'client' => $client,
        ]);
    }
}
