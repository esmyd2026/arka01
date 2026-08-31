<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            // Conserva quién fijó la tarifa inicial aunque el despacho pase
            // la solicitud a otro conductor. No se usa para autorización.
            $table->foreignId('price_reference_driver_user_id')
                ->nullable()
                ->after('driver_user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('price_reference_driver_user_id');
        });
    }
};
