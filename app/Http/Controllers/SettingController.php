<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\Payments\MonetbilGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function edit(): Response
    {
        return Inertia::render('Settings/Index', [
            'settings' => [
                'monetbil_service_key' => Setting::getValue('monetbil.service_key', ''),
                'monetbil_service_secret' => Setting::getValue('monetbil.service_secret', ''),
                'monetbil_logo_url' => Setting::getValue('monetbil.logo_url', ''),
                'hotspot_portal_base_url' => Setting::getValue('hotspot.portal_base_url', ''),
            ],
            'operators' => MonetbilGateway::OPERATORS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'monetbil_service_key' => 'nullable|string|max:100',
            'monetbil_service_secret' => 'nullable|string|max:100',
            'monetbil_logo_url' => 'nullable|url|max:255',
            'hotspot_portal_base_url' => 'nullable|url|max:255',
        ]);

        Setting::setValue('monetbil.service_key', $validated['monetbil_service_key'] ?: null);
        Setting::setValue('monetbil.service_secret', $validated['monetbil_service_secret'] ?: null);
        Setting::setValue('monetbil.logo_url', $validated['monetbil_logo_url'] ?: null);
        Setting::setValue('hotspot.portal_base_url', $validated['hotspot_portal_base_url'] ?: null);

        return back()->with('success', 'Payment settings saved.');
    }
}
