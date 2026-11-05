<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Expiry reminders issued to subscribers.
     *
     * window_key is the Y-m-d of the expiry the reminder was issued for, so
     * a renewal that moves expires_at opens a fresh reminder window without
     * any reset/delete logic.
     */
    public function up(): void
    {
        Schema::create('subscription_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // '3d' = expires within 3 days, '1d' = within 1 day
            $table->string('reminder_type', 10);
            $table->string('window_key', 10);

            $table->timestamp('expires_at_snapshot')->nullable();
            $table->timestamp('sent_at')->useCurrent();

            $table->timestamps();

            $table->unique(['subscription_id', 'reminder_type', 'window_key'], 'sub_reminder_window_unique');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_reminders');
    }
};
