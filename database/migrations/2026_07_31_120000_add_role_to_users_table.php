<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Columna `role` en `users` (consideración agregada al alcance, sección 9 de
 * las directrices de arquitectura: preparar la estructura de usuarios para
 * roles futuros): un string consultable directo por SQL con el tipo de
 * cuenta — 'cliente' | 'conductor' | 'admin' por ahora, extensible más
 * adelante (supervisor, operador, empresa, etc.) sin rediseñar la tabla.
 *
 * OJO: es una columna de LECTURA/consulta rápida (reportes, admin, queries
 * directas a la BD) — nunca la fuente de verdad para permisos. Las
 * decisiones de autorización siguen usando `User::isDriver()`/`isClient()`/
 * `is_admin` como siempre; esta columna se mantiene sincronizada sola desde
 * ahí (ver `User::booted()` y `DriverProfile::booted()`), no se actualiza a
 * mano en ningún controlador.
 *
 * No se reutiliza la columna `user_type` existente porque es un concepto
 * distinto (individual/organización, sin uso real todavía) — mezclarlos
 * hubiera atado dos ideas que no tienen por qué viajar juntas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('cliente')->after('is_admin');
        });

        // Al agregar la columna con default, MySQL ya dejó a todas las filas
        // existentes en 'cliente' — acá se corrige a los que en realidad son
        // conductor o admin, según lo que ya está configurado hoy.
        DB::table('users')
            ->whereIn('id', DB::table('driver_profiles')->select('user_id'))
            ->update(['role' => 'conductor']);

        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
