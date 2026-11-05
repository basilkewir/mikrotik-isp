<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Router;
use App\Models\Subscription;
use App\Services\Mikrotik\RouterDataService;
use Exception;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    public function index(Request $request, RouterDataService $routerData): Response
    {
        $users = [];
        $routerErrors = [];
        $routers = Router::select([
            'id',
            'name',
            'ip_address',
            'api_username',
            'api_password',
            'api_port',
            'use_ssl',
        ])->get();

        foreach ($routers as $router) {
            try {
                $users = [...$users, ...$routerData->snapshot($router, $request->boolean('live'))['users']];
            } catch (Exception $exception) {
                $routerErrors[] = [
                    'router_name' => $router->name,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($type = $request->query('auth_type')) {
            $users = array_values(array_filter($users, fn (array $user) => $user['auth_type'] === $type));
        }
        if ($status = $request->query('status')) {
            $users = array_values(array_filter($users, fn (array $user) => $user['status'] === $status));
        }
        if ($routerId = $request->query('router_id')) {
            $users = array_values(array_filter($users, fn (array $user) => (string) $user['router_id'] === (string) $routerId));
        }

        return Inertia::render('Subscriptions/Index', [
            'users' => $users,
            'routers' => $routers->map(fn (Router $router) => $router->only(['id', 'name'])),
            'routerErrors' => $routerErrors,
            'filters' => $request->only(['status', 'auth_type', 'router_id']),
        ]);
    }

    public function kickLiveSession(Router $router, Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'auth_type' => 'required|in:hotspot,pppoe',
            'username' => 'required|string|max:100',
        ]);

        $service = null;

        try {
            $service = $router->service();
            $kicked = $validated['auth_type'] === 'hotspot'
                ? $service->kickHotspotActive($validated['username'])
                : $service->kickPppoeActive($validated['username']);

            return back()->with('success', "Disconnected {$kicked} active session(s) for '{$validated['username']}'.");
        } catch (Exception $exception) {
            return back()->with('error', "Failed to disconnect '{$validated['username']}': " . $exception->getMessage());
        } finally {
            $service?->disconnect();
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'plan_id' => 'nullable|exists:plans,id',
            'router_id' => 'required|exists:routers,id',
            'auth_type' => 'required|in:hotspot,pppoe',
            'username' => 'required|string|max:100',
            'password' => 'required|string|min:4',
            'assigned_ip' => 'nullable|ip',
            'assigned_mac' => 'nullable|string',
            'expires_at' => 'nullable|date',
        ]);

        // No plan chosen: the default plan (30 calendar days) applies to every new subscriber.
        $plan = !empty($validated['plan_id'])
            ? Plan::findOrFail($validated['plan_id'])
            : Plan::defaultPlan();

        if ($plan === null) {
            return back()->withInput()->with('error', 'Choose a plan — no default plan is set.');
        }

        $validated['plan_id'] = $plan->id;

        $startAt = Carbon::now();
        $expiresAt = !empty($validated['expires_at'])
            ? Carbon::parse($validated['expires_at'])->endOfDay()
            : ($plan->validity_days ? Subscription::calendarExpiry($startAt, (int) $plan->validity_days) : null);

        $subscription = Subscription::create([
            ...$validated,
            'status' => 'active',
            'start_at' => $startAt,
            'expires_at' => $expiresAt,
            'data_quota_bytes' => $plan->data_limit_bytes,
            'data_used_bytes' => 0,
        ]);

        // Attempt live provisioning onto the MikroTik router
        try {
            $subscription->provisionOnRouter();
            return back()->with('success', "Subscription created and provisioned on {$subscription->router->name}!");
        } catch (Exception $e) {
            return back()->with('warning', "Subscription saved to DB, but failed to push to MikroTik: " . $e->getMessage());
        }
    }

    public function disable(Subscription $subscription): RedirectResponse
    {
        try {
            $subscription->disableOnRouter('manual_admin_disable');
            return back()->with('success', "Subscriber '{$subscription->username}' disabled and disconnected.");
        } catch (Exception $e) {
            return back()->with('error', "Failed to disable on router: " . $e->getMessage());
        }
    }

    public function enable(Subscription $subscription): RedirectResponse
    {
        try {
            $subscription->activateOnRouter();

            return back()->with('success', "Subscriber '{$subscription->username}' re-enabled.");
        } catch (Exception $e) {
            return back()->with('error', "Failed to enable on router: " . $e->getMessage());
        }
    }

    /**
     * Quick expiry editor (Live Router Users page).
     * Setting a past date disables the account immediately; pushing a past
     * expiry into the future re-activates it.
     */
    public function updateExpiry(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'expires_at' => 'nullable|date',
        ]);

        $expiresAt = !empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null;
        $wasExpired = $subscription->status !== 'active';

        if ($expiresAt !== null && $expiresAt->isPast() && $subscription->status === 'active') {
            try {
                $subscription->disableOnRouter('time_expired');

                return back()->with('success', "Expiry for '{$subscription->username}' set to the past — account disabled.");
            } catch (Exception $e) {
                return back()->with('error', "Expiry saved, but disabling on the router failed: " . $e->getMessage());
            }
        }

        if ($wasExpired && ($expiresAt === null || $expiresAt->isFuture())) {
            try {
                // Explicit date wins; null means permanent (no plan fallback) —
                // the admin picked "No expiry" on purpose.
                $subscription->activateOnRouter($expiresAt, false);

                return back()->with('success', "Expiry for '{$subscription->username}' set — account active until " . ($expiresAt?->toDateTimeString() ?? 'further notice') . ".");
            } catch (Exception $e) {
                return back()->with('error', "Expiry saved, but activation on the router failed: " . $e->getMessage());
            }
        }

        $subscription->update(['expires_at' => $expiresAt]);

        return back()->with('success', "Expiry for '{$subscription->username}' set to " . ($expiresAt?->toDateTimeString() ?? 'no time limit') . ".");
    }

    public function update(Request $request, Subscription $subscription): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|exists:plans,id',
            'username' => 'required|string|max:100',
            'password' => 'nullable|string|min:4',
            'assigned_ip' => 'nullable|ip',
            'expires_at' => 'nullable|date',
        ]);

        $plan = Plan::findOrFail($validated['plan_id']);

        $wasExpired = $subscription->status === 'expired';

        $data = [
            'plan_id' => $plan->id,
            'username' => $validated['username'],
            'assigned_ip' => $validated['assigned_ip'] ?? null,
            'expires_at' => !empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : null,
            'data_quota_bytes' => $plan->data_limit_bytes,
        ];

        if (!empty($validated['password'] ?? null)) {
            $data['password'] = $validated['password'];
        }

        $subscription->update($data);

        // Move back to active when the calendar expiry was pushed into the future.
        $reanimate = $wasExpired
            && ($subscription->expires_at === null || $subscription->expires_at->isFuture());

        try {
            $subscription->provisionOnRouter();

            if ($reanimate) {
                $service = $subscription->router->service();

                try {
                    if ($subscription->assigned_ip) {
                        $service->unfreezeIp($subscription->assigned_ip);
                    }
                } finally {
                    $service->disconnect();
                }

                $subscription->update(['status' => 'active']);
            }

            return back()->with('success', "Subscription '{$subscription->username}' updated and re-provisioned on {$subscription->router->name}.");
        } catch (Exception $e) {
            return back()->with('warning', "Subscription saved, but failed to push to MikroTik: " . $e->getMessage());
        }
    }

    public function destroy(Subscription $subscription): RedirectResponse
    {
        try {
            $service = $subscription->router->service();

            if ($subscription->auth_type === 'hotspot') {
                $service->removeHotspotUser($subscription->username);
            } elseif ($subscription->auth_type === 'pppoe') {
                $service->removePppoeSecret($subscription->username);
            }

            if ($subscription->assigned_ip) {
                $service->unfreezeIp($subscription->assigned_ip);
            }

            $service->disconnect();

            $subscription->delete();

            return back()->with('success', "Subscription '{$subscription->username}' removed from router and deleted.");
        } catch (Exception $e) {
            $subscription->delete();

            return back()->with('warning', 'Subscription record deleted, but router cleanup failed: ' . $e->getMessage());
        }
    }

    public function kick(Subscription $subscription): RedirectResponse
    {
        try {
            $service = $subscription->router->service();
            $kicked = 0;

            if ($subscription->auth_type === 'hotspot') {
                $kicked = $service->kickHotspotActive($subscription->username);
            } elseif ($subscription->auth_type === 'pppoe') {
                $kicked = $service->kickPppoeActive($subscription->username);
            }

            $service->disconnect();

            return back()->with('success', "Active session terminated ({$kicked} active session(s) removed).");
        } catch (Exception $e) {
            return back()->with('error', "Failed to disconnect session: " . $e->getMessage());
        }
    }
}
