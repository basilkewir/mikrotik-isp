<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('ip_address');
            $table->unsignedInteger('api_port')->default(8728);
            $table->string('api_username');
            $table->text('api_password'); // Encrypted
            $table->boolean('use_ssl')->default(false);
            $table->string('identity')->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('ros_version')->nullable();
            $table->string('board_name')->nullable();
            $table->unsignedInteger('cpu_load')->default(0);
            $table->unsignedBigInteger('total_memory')->default(0);
            $table->unsignedBigInteger('free_memory')->default(0);
            $table->string('uptime')->nullable();
            $table->timestamps();
        });

        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['time', 'data', 'time_and_data', 'unlimited'])->default('time');
            $table->enum('service_type', ['hotspot', 'pppoe', 'both'])->default('both');
            $table->decimal('price', 10, 2)->default(0.00);
            $table->unsignedInteger('validity_hours')->nullable(); // e.g. 24, 168 (7d), 720 (30d)
            $table->unsignedBigInteger('data_limit_mb')->nullable();
            $table->string('rate_limit_upload')->default('5M'); // e.g. 5M
            $table->string('rate_limit_download')->default('10M'); // e.g. 10M
            $table->string('hotspot_profile')->default('default');
            $table->string('pppoe_profile')->default('default');
            $table->unsignedSmallInteger('shared_users')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_code')->unique(); // CLI-1001
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->enum('status', ['active', 'suspended', 'inactive'])->default('active');
            $table->decimal('balance', 10, 2)->default(0.00);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->enum('auth_type', ['hotspot', 'pppoe'])->default('hotspot');
            $table->string('username');
            $table->string('password');
            $table->string('assigned_ip')->nullable();
            $table->string('assigned_mac')->nullable();
            $table->enum('status', ['active', 'expired', 'disabled', 'paused'])->default('active');
            $table->timestamp('start_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedBigInteger('data_quota_bytes')->nullable();
            $table->unsignedBigInteger('data_used_bytes')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['router_id', 'status']);
            $table->index(['expires_at', 'status']);
            $table->unique(['router_id', 'auth_type', 'username']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mac_address');
            $table->string('ip_address')->nullable();
            $table->string('hostname')->nullable();
            $table->enum('type', ['hotspot', 'pppoe', 'cpe', 'bridge', 'ap'])->default('cpe');
            $table->string('comment')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('router_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event'); // auto_disconnect, manual_disable, sync, auth_failed
            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->json('details')->nullable();
            $table->string('ip_address')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_activity_logs');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('clients');
        Schema::dropIfExists('plans');
        Schema::dropIfExists('routers');
    }
};

