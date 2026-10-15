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

        $plan->update($validated);

        return back()->with('success', "Plan '{$plan->name}' updated successfully.");
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
