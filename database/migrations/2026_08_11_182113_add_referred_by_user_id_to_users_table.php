<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de referidos (pedido explícito del usuario): quién compartió
 * el enlace (invitación de flota de un conductor, o el perfil público de
 * cualquiera) que hizo que esta cuenta se registrara — ver
 * App\Models\User::referredBy()/referrals() y RegisteredUserController::store().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('referred_by_user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by_user_id');
        });
    }
};
