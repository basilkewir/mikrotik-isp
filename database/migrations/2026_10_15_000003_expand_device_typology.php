<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Map legacy type values onto the new typology codes.
        DB::table('devices')->where('type', 'hotspot')->update(['type' => 'hotspot_client']);
        DB::table('devices')->where('type', 'pppoe')->update(['type' => 'cpe']);
        DB::table('devices')->where('type', 'bridge')->update(['type' => 'bridge_client']);
        DB::table('devices')->where('type', 'ap')->update(['type' => 'access_point']);

        // 2. customer edge types stay tied to a client; infrastructure devices have none.
        DB::statement('ALTER TABLE devices MODIFY client_id BIGINT UNSIGNED NULL');

        // 3. Expand the type enum to the full 12-code typology.
        DB::statement(
            "ALTER TABLE devices MODIFY type ENUM(
                'cpe','hotspot_client','bridge_client','access_point','router','switch',
                'onu','sector','backhaul','iot','server','unknown'
            ) NOT NULL DEFAULT 'cpe'"
        );

        // 4. New identification / hierarchy / status columns.
        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('parent_device_id')->nullable()->constrained('devices')->nullOnDelete();
            $table->string('serial_number', 64)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('manufacturer', 80)->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->string('ipv6_address', 45)->nullable();
            $table->string('interface', 50)->nullable();
            $table->string('management_ip', 45)->nullable();
            $table->string('snmp_community', 50)->nullable();
            $table->boolean('is_online')->default(false);
            $table->timestamp('last_seen_at')->nullable();
            $table->integer('signal_strength')->nullable();
            $table->unsignedBigInteger('uptime_seconds')->nullable();
            $table->text('notes')->nullable();

            $table->index(['router_id', 'mac_address'], 'devices_router_mac_index');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_device_id');
            $table->dropIndex('devices_router_mac_index');
            $table->dropColumn([
                'serial_number', 'model', 'manufacturer', 'firmware_version',
                'ipv6_address', 'interface', 'management_ip', 'snmp_community',
                'is_online', 'last_seen_at', 'signal_strength', 'uptime_seconds', 'notes',
            ]);
        });

        DB::statement(
            "ALTER TABLE devices MODIFY type ENUM(
                'hotspot','pppoe','cpe','bridge','ap'
            ) NOT NULL DEFAULT 'cpe'"
        );
        DB::statement('ALTER TABLE devices MODIFY client_id BIGINT UNSIGNED NOT NULL');
    }
};
