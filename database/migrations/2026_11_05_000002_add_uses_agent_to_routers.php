<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            // When true the platform never opens a direct API connection; it
            // relies on the router's agent pushes instead (VPS cannot route
            // to the router's private LAN address).
            $table->boolean('uses_agent')->default(false)->after('agent_source_ip');
        });
    }

    public function down(): void
    {
        Schema::table('routers', function (Blueprint $table) {
            $table->dropColumn('uses_agent');
        });
    }
};
