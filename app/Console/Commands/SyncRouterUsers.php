<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncRouterUsers extends Command
{
    protected $signature = 'isp:sync-router-users
        {--router= : Limit the sync to a single router ID}
        {--dry-run : Show what would be imported without writing anything}';

    protected $description = 'Import RouterOS hotspot/PPPoE profiles (as plans) and users (as clients + subscriptions) into the local database';

    public function handle(): int
    {
        $routers = $this->option('router')
            ? Router::where('id', $this->option('router'))->get()
            : Router::all();

        if ($routers->isEmpty()) {
            $this->error('No routers configured.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $totals = ['plans' => 0, 'clients' => 0, 'subscriptions' => 0];

        foreach ($routers as $router) {
            $this->info("Syncing router \"{$router->name}\" ({$router->ip_address})...");

            try {
                $service = $router->service();

                try {
                    $profiles = $service->getRouterProfiles();
                    $hotspotUsers = $service->listHotspotUsers();
                    $pppoeSecrets = $service->listPppoeSecrets();
                } finally {
                    $service->disconnect();
                }
            } catch (Exception $exception) {
                $this->error("  Router unreachable: {$exception->getMessage()}");

                continue;
            }

            // 1. Plans from hotspot + pppoe profiles.
            $planByProfile = [];
            foreach (['hotspot' => $profiles['hotspot_profiles'], 'pppoe' => $profiles['pppoe_profiles']] as $kind => $list) {
                foreach ($list as $profile) {
                    $name = (string) ($profile['name'] ?? '');
                    if ($name === '') {
                        continue; // skip nameless profiles
                    }

                    $plan = $this->importPlan($router, $name, $kind, $dry);
                    if ($plan) {
                        $planByProfile[$name] = $plan;
                        $totals['plans']++;
                    }
                }
            }

            // 2. Hotspot users -> client + subscription.
            foreach ($hotspotUsers as $user) {
                $username = (string) ($user['name'] ?? '');
                if ($username === '') {
                    continue;
                }

                $result = $this->importUser($router, $user, 'hotspot', $planByProfile, $dry);
                $totals['clients'] += $result['client'] ? 1 : 0;
                $totals['subscriptions'] += $result['subscription'] ? 1 : 0;
            }

            // 3. PPPoE secrets -> client + subscription.
            foreach ($pppoeSecrets as $secret) {
                $username = (string) ($secret['name'] ?? '');
                if ($username === '') {
                    continue;
                }

                $result = $this->importUser($router, $secret, 'pppoe', $planByProfile, $dry);
                $totals['clients'] += $result['client'] ? 1 : 0;
                $totals['subscriptions'] += $result['subscription'] ? 1 : 0;
            }
        }

        $verb = $dry ? 'Would import' : 'Imported';
        $this->info("{$verb}: {$totals['plans']} plan(s), {$totals['clients']} client(s), {$totals['subscriptions']} subscription(s).");

        return self::SUCCESS;
    }

    /**
     * Create (or find) a Plan for a RouterOS profile name.
     */
    private function importPlan(Router $router, string $profileName, string $kind, bool $dry): ?Plan
    {
        $code = $this->planCode($profileName);

        $existing = Plan::where('code', $code)->first();
        if ($existing) {
            return $existing;
        }

        if ($dry) {
            $this->line("  [plan] {$profileName} -> {$code}");

            return null;
        }

        return Plan::create([
            'name' => $profileName,
            'code' => $code,
            'type' => 'time',
            'service_type' => $kind === 'pppoe' ? 'pppoe' : 'hotspot',
            'billing_mode' => 'prepaid',
            'price' => 0,
            'validity_days' => $this->guessValidityDays($profileName),
            'rate_limit_upload' => '5M',
            'rate_limit_download' => '10M',
            'hotspot_profile' => $kind === 'hotspot' ? $profileName : 'default',
            'pppoe_profile' => $kind === 'pppoe' ? $profileName : 'default',
            'shared_users' => 1,
            'is_active' => true,
        ]);
    }

    /**
     * Import a single router user (hotspot or pppoe) as a client + subscription.
     *
     * @return array{client: bool, subscription: bool}
     */
    private function importUser(Router $router, array $user, string $authType, array $planByProfile, bool $dry): array
    {
        $username = (string) $user['name'];
        $profile = (string) ($user['profile'] ?? '');
        $comment = trim((string) ($user['comment'] ?? ''));
        $disabled = ($user['disabled'] ?? 'false') === 'true';
        $password = (string) ($user['password'] ?? '');

        // Match a plan by the profile name. If the profile isn't one we've seen
        // in the profile list (RouterOS lets users reference free-form profile
        // names), create a plan for it on the fly so tiers stay distinct.
        $plan = $planByProfile[$profile] ?? null;

        if (!$plan && $profile !== '') {
            $plan = $this->importPlan($router, $profile, $authType, $dry);
            if ($plan) {
                $planByProfile[$profile] = $plan;
            }
        }

        if (!$plan) {
            // No profile at all (e.g. empty) — fall back to any plan, else create one.
            $plan = Plan::where('service_type', $authType)->orderBy('id')->first()
                ?? $this->importPlan($router, $profile !== '' ? $profile : 'Imported', $authType, $dry);
        }

        if ($dry) {
            $this->line("  [{$authType}] {$username} (profile: {$profile}) -> client + subscription");
            $this->line('      comment: ' . ($comment !== '' ? $comment : '(none)'));

            return ['client' => true, 'subscription' => true];
        }

        // Client: one per router username. Name from comment when it looks like a
        // person's name, otherwise title-case the username. Comment goes to notes.
        $client = $this->findOrCreateClient($username, $comment);

        // Subscription: upsert on the unique (router, auth_type, username) key.
        $subscription = Subscription::updateOrCreate(
            [
                'router_id' => $router->id,
                'auth_type' => $authType,
                'username' => $username,
            ],
            [
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'password' => $password !== '' ? $password : 'imported',
                'status' => $disabled ? 'disabled' : 'active',
                'last_synced_at' => now(),
            ]
        );

        return ['client' => true, 'subscription' => $subscription->wasRecentlyCreated];
    }

    /**
     * Find a client by notes-embedded username or create a new one.
     */
    private function findOrCreateClient(string $username, string $comment): Client
    {
        // Reuse a client already linked to this username's subscription if present.
        $existingSubscription = Subscription::where('username', $username)->first();
        if ($existingSubscription && $existingSubscription->client) {
            return $existingSubscription->client;
        }

        $name = $this->guessName($username, $comment);

        return Client::create([
            'client_code' => Client::generateClientCode(),
            'name' => $name,
            'status' => 'active',
            'balance' => 0,
            'notes' => $comment !== '' ? "Imported from router. {$comment}" : 'Imported from router.',
        ]);
    }

    /**
     * Build a plan code from a profile name: "30-Days-2Mbps" -> "30-DAYS-2MBPS".
     */
    private function planCode(string $profileName): string
    {
        return Str::upper(Str::slug($profileName, '-'));
    }

    /**
     * Guess validity in days from a profile name like "30-Days-2Mbps".
     */
    private function guessValidityDays(string $profileName): ?int
    {
        if (preg_match('/(\d+)\s*-?\s*day/i', $profileName, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Derive a human name: use the comment if it looks like a date-free label,
     * otherwise title-case the username.
     */
    private function guessName(string $username, string $comment): string
    {
        // Comments that are clearly dates or domains are not useful as names.
        $looksLikeDate = preg_match('/\d{4}|\/|\bjan\b|\bfeb\b|\bmar\b|\bapr\b|\bmay\b|\bjun\b|\bjul\b|\baug\b|\bsep\b|\boct\b|\bnov\b|\bdec\b/i', $comment);
        $looksLikeDomain = str_contains($comment, '.com') || str_contains($comment, '.net') || str_contains($comment, '.');

        if ($comment !== '' && !$looksLikeDate && !$looksLikeDomain && strlen($comment) <= 60) {
            return $comment;
        }

        return Str::title(str_replace(['-', '_'], ' ', $username));
    }
}
