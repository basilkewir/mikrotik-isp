<?php

use App\Http\Controllers\Api\HotspotPortalController;
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
