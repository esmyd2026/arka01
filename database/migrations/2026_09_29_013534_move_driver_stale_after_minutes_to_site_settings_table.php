<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * pricing_settings pasó a ser una fila POR PAÍS (ver
     * add_country_id_to_pricing_settings_table) — pero
     * driver_stale_after_minutes no es un dato de negocio/moneda, es un
     * umbral operativo ("hace cuánto no reporta ubicación un conductor para
     * considerarlo desconectado") que App\Console\Commands\SweepStaleDriverAvailability
     * usa para TODOS los conductores de una sola pasada, sin importar su
     * país. Partirlo por país exigiría barrer país por país sin ningún
     * beneficio real, así que se muda a site_settings (config operativa
     * global, mismo lugar que /admin/sistema) en vez de duplicarlo en cada
     * fila de pricing_settings.
     */
    public function up(): void
    {
        Schema::table('site_settings', function (Blueprint $table) {
            $table->unsignedInteger('driver_stale_after_minutes')->default(2)->after('notification_volume');
        });

        // Conserva el valor que el admin ya tenía configurado (si las filas
        // de pricing_settings llegaran a tener valores distintos por algún
        // motivo, toma el mayor — más tolerante, nunca desconecta antes de
        // lo que un admin esperaba).
        $currentValue = DB::table('pricing_settings')->max('driver_stale_after_minutes') ?? 2;
        DB::table('site_settings')->update(['driver_stale_after_minutes' => $currentValue]);

        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->dropColumn('driver_stale_after_minutes');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_settings', function (Blueprint $table) {
            $table->unsignedInteger('driver_stale_after_minutes')->default(2)->after('average_ticket_price');
        });

        $currentValue = DB::table('site_settings')->value('driver_stale_after_minutes') ?? 2;
        DB::table('pricing_settings')->update(['driver_stale_after_minutes' => $currentValue]);

        Schema::table('site_settings', function (Blueprint $table) {
            $table->dropColumn('driver_stale_after_minutes');
        });
    }
};
