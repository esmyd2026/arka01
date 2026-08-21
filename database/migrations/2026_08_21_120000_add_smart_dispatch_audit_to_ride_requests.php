<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->string('smart_dispatch_version', 20)->nullable()->after('current_offer_expires_at');
            $table->json('smart_dispatch_snapshot')->nullable()->after('smart_dispatch_version');
        });
    }

    public function down(): void
    {
        Schema::table('ride_requests', function (Blueprint $table) {
            $table->dropColumn(['smart_dispatch_version', 'smart_dispatch_snapshot']);
        });
    }
};
