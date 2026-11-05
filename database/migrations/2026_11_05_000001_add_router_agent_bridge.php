<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            // Secret the router uses to authenticate pushes/pulls. Distinct from
            // the API password: this one is used for the reverse (router -> VPS) link.
            $table->string('agent_token', 64)->nullable()->unique()->after('api_password');
            $table->timestamp('agent_last_seen_at')->nullable()->after('last_seen_at');
            $table->string('agent_source_ip', 45)->nullable()->after('agent_last_seen_at');
        });

        // Live sessions pushed by the router agent (hotspot + pppoe).
        Schema::create('router_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);            // hotspot | pppoe
            $table->string('username');
            $table->string('address', 45)->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->string('uptime', 40)->nullable();
            $table->string('session_time_left', 40)->nullable();
            $table->unsignedBigInteger('bytes_in')->default(0);
            $table->unsignedBigInteger('bytes_out')->default(0);
            $table->timestamp('pushed_at');
            $table->timestamps();

            $table->unique(['router_id', 'kind', 'username', 'address']);
            $table->index(['router_id', 'pushed_at']);
        });

        // Commands the operator queues for a router that cannot be reached
        // directly (router pulls them on its next agent run).
        Schema::create('router_agent_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('router_id')->constrained()->cascadeOnDelete();
            $table->string('command');             // e.g. disable_hotspot_user
            $table->json('payload')->nullable();
            $table->string('status', 12)->default('pending'); // pending|delivered|done|failed
            $table->string('result', 500)->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['router_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('router_agent_commands');
        Schema::dropIfExists('router_sessions');

        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn(['agent_token', 'agent_last_seen_at', 'agent_source_ip']);
        });
    }
};
