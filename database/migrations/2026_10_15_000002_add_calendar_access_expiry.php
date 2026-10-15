<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('validity_days')->nullable()->after('price');
        });

        DB::table('plans')
            ->whereNotNull('validity_hours')
            ->orderBy('id')
            ->get(['id', 'validity_hours'])
            ->each(function (object $plan): void {
                DB::table('plans')
                    ->where('id', $plan->id)
                    ->update(['validity_days' => (int) ceil($plan->validity_hours / 24)]);
            });

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('validity_hours');
        });

        Schema::table('devices', function (Blueprint $table): void {
            $table->unsignedInteger('access_duration_days')->default(30)->after('is_active');
            $table->timestamp('access_expires_at')->nullable()->after('access_duration_days');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['access_duration_days', 'access_expires_at']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('validity_hours')->nullable()->after('price');
        });

        DB::table('plans')
            ->whereNotNull('validity_days')
            ->update(['validity_hours' => DB::raw('validity_days * 24')]);

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn('validity_days');
        });
    }
};
