<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track router-side enforcement of expired/depleted subscriptions.
     *
     * enforcement_status lifecycle:
     *   null       – nothing pending (fresh or already-clean row)
     *   'pending'  – claimed by the enforcement worker, router ops in flight
     *   'enforced' – router-side block confirmed, status set to expired/disabled
     *   'failed'   – router operation failed; worker retries next run
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->string('enforcement_status', 20)->nullable()->after('status');
            $table->timestamp('expired_at')->nullable()->after('expires_at');
            $table->timestamp('enforced_at')->nullable()->after('expired_at');
            $table->text('last_enforcement_error')->nullable()->after('enforced_at');

            $table->index('enforcement_status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['enforcement_status']);
            $table->dropColumn([
                'enforcement_status',
                'expired_at',
                'enforced_at',
                'last_enforcement_error',
            ]);
        });
    }
};
