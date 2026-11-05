<?php

use App\Http\Controllers\Api\HotspotPortalController;
use App\Http\Controllers\Api\RouterAgentController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Captive-portal API (served from the MikroTik hotspot pages)
|--------------------------------------------------------------------------
| Unauthenticated by design: the RB951Ui hotspot login/signup pages call
| these from the captive network. Throttled; only expose what signup needs.
*/

Route::get('/hotspot/plans', [HotspotPortalController::class, 'plans'])
    ->middleware('throttle:30,1')
    ->name('api.hotspot.plans');

Route::post('/hotspot/register', [HotspotPortalController::class, 'register'])
    ->middleware('throttle:6,1')
    ->name('api.hotspot.register');

/*
|--------------------------------------------------------------------------
| Router agent bridge (router -> VPS push/pull)
|--------------------------------------------------------------------------
| The router authenticates with its agent token (Bearer). Throttled so a
| misconfigured script cannot hammer the API.
*/
Route::post('/router-agent/sync', [RouterAgentController::class, 'sync'])
    ->middleware('throttle:120,1')
    ->name('api.router-agent.sync');
