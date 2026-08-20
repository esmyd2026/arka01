<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cooperatives', function (Blueprint $table) {
            $table->decimal('stand_lat', 10, 7)->nullable()->after('main_address');
            $table->decimal('stand_lng', 10, 7)->nullable()->after('stand_lat');
            $table->boolean('automatic_assignment_enabled')->default(true)->after('response_timeout_seconds');
            $table->unsignedSmallInteger('manual_assignment_timeout_seconds')->default(30)->after('automatic_assignment_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('cooperatives', fn (Blueprint $table) => $table->dropColumn([
            'stand_lat', 'stand_lng', 'automatic_assignment_enabled', 'manual_assignment_timeout_seconds',
        ]));
    }
};
