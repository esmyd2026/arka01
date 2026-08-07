<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');

            // Teléfono: es el dato central para buscar/invitar gente a una flota
            // (sección 3.2 del alcance), por eso es único y queda separado del email.
            $table->string('phone')->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();

            // 'individual' = persona común (cliente y/o conductor). 'organization' = cuenta
            // institucional (universidad/empresa, sección 3.1 y 3.4). Se deja el campo listo
            // desde ahora aunque el panel institucional se construya recién en una fase futura,
            // así no hace falta otra migración para agregarlo después.
            $table->enum('user_type', ['individual', 'organization'])->default('individual');

            // Simple bandera de administrador de la plataforma (sección 3.1). No usamos un
            // sistema de roles/permisos más complejo porque, por ahora, el panel admin solo
            // necesita distinguir "es admin" de "no lo es".
            $table->boolean('is_admin')->default(false);

            $table->string('avatar_path')->nullable();

            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
