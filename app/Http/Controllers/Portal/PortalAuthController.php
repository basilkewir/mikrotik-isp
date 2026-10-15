<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PortalAuthController extends Controller
{
    public function showLogin(): Response|RedirectResponse
    {
        if (Auth::guard('client')->check()) {
            return redirect()->route('portal.dashboard');
        }

        return Inertia::render('Portal/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'client_code' => 'required|string|max:30',
            'portal_password' => 'required|string|max:255',
        ]);

        $client = Client::where('client_code', $credentials['client_code'])->first();

        if (!$client || !$client->portal_password || !Hash::check($credentials['portal_password'], $client->portal_password)) {
            throw ValidationException::withMessages([
                'client_code' => 'Invalid client code or password.',
            ]);
        }

        if ($client->status !== 'active') {
            throw ValidationException::withMessages([
                'client_code' => 'This account is suspended. Contact support.',
            ]);
        }

        $request->session()->regenerate();
        Auth::guard('client')->login($client);

        return redirect()->intended(route('portal.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }
}
