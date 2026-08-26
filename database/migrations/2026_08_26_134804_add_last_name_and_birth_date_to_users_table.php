<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido explícito del usuario ("estructura... nombres, apellidos,
     * fecha de nacimiento, pais por defecto ecuador, ciudad") — el registro
     * ya pide "nombre" y "apellido" por separado en el formulario
     * (RegisteredUserController::store()), pero los junta en un solo
     * `name` al guardar; no existía ninguna columna que guardara el
     * apellido aparte. `birth_date` tampoco existía — hasta ahora "debe
     * ser mayor de edad" era solo una frase en los manuales, sin nada que
     * lo validara de verdad.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_name', 100)->nullable()->after('name');
            $table->date('birth_date')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['last_name', 'birth_date']);
        });
    }
};
