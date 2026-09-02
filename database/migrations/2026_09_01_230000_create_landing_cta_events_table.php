<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_cta_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type', 20);
            $table->string('target', 20)->nullable();
            // Nunca guardamos el identificador del navegador, la sesión ni la
            // IP en claro. Los hashes permiten contar personas únicas sin
            // convertir esta métrica comercial en un registro de seguimiento.
            $table->char('visitor_hash', 64);
            $table->char('session_hash', 64);
            $table->char('ip_hash', 64)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('landing_path')->default('/');
            $table->timestamps();

            $table->index(['event_type', 'created_at']);
            $table->index(['visitor_hash', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_cta_events');
    }
};
