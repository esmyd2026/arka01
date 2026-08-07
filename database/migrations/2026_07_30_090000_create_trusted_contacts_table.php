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
        // trusted_contacts: a quién avisar si algo sale mal durante un viaje
        // (sección 8: seguimiento compartible y botón SOS). No son usuarios de
        // la plataforma — solo un nombre y un contacto para notificarlos.
        Schema::create('trusted_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            // Ej. "Mamá", "Pareja", "Compañero de trabajo" — puramente informativo.
            $table->string('relationship_label')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trusted_contacts');
    }
};
