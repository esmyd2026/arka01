<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Bug real reportado por el usuario: quien entra por Google no tiene
        // una contraseña que conozca (GoogleAuthController le guarda un hash
        // al azar, la columna es obligatoria pero nunca se usa para entrar)
        // — el formulario de "Cambiar contraseña" de siempre le pedía esa
        // contraseña actual para poder cambiarla, algo que jamás podía
        // escribir bien. Null acá significa "todavía tiene la del azar de
        // Google, nunca puso una propia" — ProfileController los deja
        // crearla sin pedirles la actual; no-nulo significa "ya tiene una
        // contraseña propia" (la de siempre, o una que ya creó por acá) y
        // sigue pidiendo la actual para cambiarla, como corresponde.
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_set_at')->nullable()->after('password');
        });

        // Backfill: las cuentas existentes que NO entraron por Google sí
        // pusieron su propia contraseña al registrarse (es obligatoria en
        // ese formulario) — se les marca con su fecha de alta. Las que sí
        // tienen `google_id` se dejan en null a propósito: con el flujo
        // viejo, ninguna pudo haber cambiado su contraseña de verdad (el
        // chequeo de "contraseña actual" se lo impedía siempre), así que
        // siguen sin una propia hasta que la creen con el flujo nuevo.
        DB::table('users')->whereNull('google_id')->update(['password_set_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_set_at');
        });
    }
};
