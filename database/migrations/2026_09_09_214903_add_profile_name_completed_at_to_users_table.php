<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: registro rápido de cliente (solo teléfono +
 * código de WhatsApp) — el nombre/apellido se piden RECIÉN DESPUÉS de
 * verificar el teléfono, así que hace falta saber a quién todavía le falta
 * ese paso. Mismo criterio que `onboarding_completed_at`/`password_set_at`:
 * `null` = falta, timestamp = cuándo lo completó. Ver
 * App\Http\Middleware\EnsureProfileNameIsComplete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('profile_name_completed_at')->nullable()->after('onboarding_completed_at');
        });

        // Backfill obligatorio: sin esto, TODAS las cuentas existentes
        // quedarían marcadas como "le falta el nombre" apenas se despliega,
        // aunque ya lo tengan desde siempre — solo las cuentas nuevas del
        // registro rápido deben arrancar en null.
        DB::table('users')->update(['profile_name_completed_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('profile_name_completed_at');
        });
    }
};
