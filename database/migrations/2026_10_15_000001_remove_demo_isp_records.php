<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $demoRouter = DB::table('routers')
                ->where('ip_address', '192.168.88.1')
                ->where('name', 'Core Gateway RB951Ui')
                ->first(['id']);

            $sampleSubscriptions = DB::table('subscriptions')
                ->whereIn('username', ['alex_turner_ppp', 'guest_voucher_881']);

            if ($demoRouter) {
                $sampleSubscriptions->where('router_id', $demoRouter->id);
            }

            $subscriptionIds = $sampleSubscriptions->pluck('id');

            if ($subscriptionIds->isNotEmpty()) {
                DB::table('router_activity_logs')->whereIn('subscription_id', $subscriptionIds)->delete();
                DB::table('subscriptions')->whereIn('id', $subscriptionIds)->delete();
            }

            DB::table('devices')
                ->where('mac_address', '64:D1:54:33:9A:01')
                ->delete();

            if ($demoRouter) {
                DB::table('router_activity_logs')->where('router_id', $demoRouter->id)->delete();

                if (! DB::table('subscriptions')->where('router_id', $demoRouter->id)->exists()) {
                    DB::table('routers')->where('id', $demoRouter->id)->delete();
                }
            }

            foreach (['CLI-1001', 'CLI-1002'] as $clientCode) {
                $client = DB::table('clients')->where('client_code', $clientCode)->first(['id']);

                if ($client
                    && ! DB::table('subscriptions')->where('client_id', $client->id)->exists()
                    && ! DB::table('devices')->where('client_id', $client->id)->exists()) {
                    DB::table('clients')->where('id', $client->id)->delete();
                }
            }

            foreach (['HOTSPOT-24H', 'HOTSPOT-WEEKLY-15GB', 'PPPOE-HOME-25M'] as $planCode) {
                $plan = DB::table('plans')->where('code', $planCode)->first(['id']);

                if ($plan && ! DB::table('subscriptions')->where('plan_id', $plan->id)->exists()) {
                    DB::table('plans')->where('id', $plan->id)->delete();
                }
            }
        });
    }

    public function down(): void
    {
    }
};
