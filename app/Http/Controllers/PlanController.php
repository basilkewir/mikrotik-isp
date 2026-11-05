<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(): Response
    {
        $plans = Plan::withCount('subscriptions')->latest('id')->get();

        return Inertia::render('Plans/Index', [
            'plans' => $plans,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:plans,code',
            'type' => 'required|in:time,data,time_and_data,unlimited',
            'service_type' => 'required|in:hotspot,pppoe,both',
            'price' => 'required|numeric|min:0',
            'validity_days' => 'nullable|integer|min:1|max:3650',
            'data_limit_mb' => 'nullable|integer|min:1',
            'rate_limit_upload' => 'required|string|max:20',
            'rate_limit_download' => 'required|string|max:20',
            'hotspot_profile' => 'nullable|string|max:100',
            'pppoe_profile' => 'nullable|string|max:100',
            'shared_users' => 'required|integer|min:1|max:10',
            'is_active' => 'boolean',
        ]);

        Plan::create($validated);

        return back()->with('success', 'Plan created successfully.');
    }

    public function update(Request $request, Plan $plan): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:50|unique:plans,code,' . $plan->id,
            'type' => 'required|in:time,data,time_and_data,unlimited',
            'service_type' => 'required|in:hotspot,pppoe,both',
            'price' => 'required|numeric|min:0',
            'validity_days' => 'nullable|integer|min:1|max:3650',
            'data_limit_mb' => 'nullable|integer|min:1',
            'rate_limit_upload' => 'required|string|max:20',
            'rate_limit_download' => 'required|string|max:20',
            'hotspot_profile' => 'nullable|string|max:100',
            'pppoe_profile' => 'nullable|string|max:100',
            'shared_users' => 'required|integer|min:1|max:10',
            'is_active' => 'boolean',
        ]);

        if ($plan->is_default && (int) ($validated['validity_days'] ?? 0) !== Plan::DEFAULT_VALIDITY_DAYS) {
            return back()->withInput()->with('error', 'The default plan must stay at ' . Plan::DEFAULT_VALIDITY_DAYS . ' calendar days.');
        }

        $plan->update($validated);

        return back()->with('success', "Plan '{$plan->name}' updated successfully.");
    }

    /**
     * Make this plan the default for new subscribers (one default at a time).
     */
    public function setDefault(Plan $plan): RedirectResponse
    {
        try {
            $plan->makeDefault();
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "'{$plan->name}' is now the default plan for new subscribers.");
    }

    public function destroy(Plan $plan): RedirectResponse
    {
        if ($plan->subscriptions()->where('status', 'active')->exists()) {
            return back()->with('error', 'Cannot delete plan with active subscribers.');
        }

        $plan->delete();
        return back()->with('success', 'Plan deleted successfully.');
    }
}
