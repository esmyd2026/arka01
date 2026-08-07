<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contador de una sola fila para el código de socio (consideración
     * agregada al alcance: "un código que comience desde 500 y en
     * adelante"). No se puede usar el AUTO_INCREMENT de `users.id` para esto
     * (MySQL solo permite una columna auto-incremental por tabla, y esa ya
     * es `id`), así que se maneja acá con un lock explícito por transacción
     * — ver App\Services\MemberCodeSequence.
     */
    public function up(): void
    {
        Schema::create('member_code_sequences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('next_value')->default(500);
        });

        DB::table('member_code_sequences')->insert(['next_value' => 500]);
    }

    public function down(): void
    {
        Schema::dropIfExists('member_code_sequences');
    }
};
