<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('express_route_companions', function (Blueprint $table) {
            $table->string('driver_approval_status')->nullable()->after('status');
            $table->timestamp('driver_responded_at')->nullable()->after('responded_at');
        });

        // Los acompañantes históricos ya aceptados no deben quedar bloqueados
        // al desplegar esta segunda aprobación.
        DB::table('express_route_companions')
            ->where('status', 'accepted')
            ->update(['driver_approval_status' => 'accepted']);
    }

    public function down(): void
    {
        Schema::table('express_route_companions', function (Blueprint $table) {
            $table->dropColumn(['driver_approval_status', 'driver_responded_at']);
        });
    }
};
