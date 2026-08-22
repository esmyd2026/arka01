<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->boolean('ride_notifications_enabled')->default(true);
            $table->boolean('driver_ride_actions_enabled')->default(false);
            $table->boolean('client_ride_booking_enabled')->default(false);
            $table->text('privacy_notice_text')->nullable();
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->boolean('whatsapp_ride_actions_enabled')->default(true);
        });

        Schema::table('cooperatives', function (Blueprint $table) {
            $table->boolean('whatsapp_ride_actions_enabled')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', fn (Blueprint $table) => $table->dropColumn('whatsapp_ride_actions_enabled'));
        Schema::table('driver_profiles', fn (Blueprint $table) => $table->dropColumn('whatsapp_ride_actions_enabled'));
        Schema::table('whatsapp_settings', function (Blueprint $table) {
            $table->dropColumn([
                'ride_notifications_enabled',
                'driver_ride_actions_enabled',
                'client_ride_booking_enabled',
                'privacy_notice_text',
            ]);
        });
    }
};
