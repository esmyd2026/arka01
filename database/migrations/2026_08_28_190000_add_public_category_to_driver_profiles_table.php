<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('driver_profiles', 'public_category')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                // Clasificación visible asignada por administración. Es
                // independiente del tipo operativo interno y de la categoría
                // comercial del vehículo.
                $table->string('public_category', 30)->nullable()->after('service_category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('driver_profiles', 'public_category')) {
            Schema::table('driver_profiles', function (Blueprint $table) {
                $table->dropColumn('public_category');
            });
        }
    }
};
