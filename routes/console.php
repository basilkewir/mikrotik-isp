<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| ISP Background Automation & Schedule
|--------------------------------------------------------------------------
|
| - isp:enforce-quotas runs every minute to terminate expired or depleted subscribers.
| - isp:sync-routers runs every 5 minutes to gather CPU, memory, uptime, and traffic.
|
*/

Schedule::command('isp:enforce-quotas')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('isp:sync-routers')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('isp:renew-from-wallet')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('isp:remind-expiring')
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();

