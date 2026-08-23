<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Configuración general del sitio público (pedido explícito del usuario:
     * "por lo menos haz que la pueda colocar desde la parte de
     * configuración del admin" — la imagen de fondo del hero de Welcome.vue
     * no se puede pegar como archivo desde el chat, así que en vez de
     * depender de que alguien la copie a mano a `public/img/`, se sube
     * desde acá). Fila única, mismo patrón singleton que
     * `pricing_settings`/`whatsapp_settings`/`chatbot_settings`.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->id();
            // Disco 'public' (storage/app/public, enlazado por `artisan
            // storage:link`) — mismo criterio que `avatar_path` en User.
            $table->string('hero_background_path')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('site_settings')->insert([
            'hero_background_path' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
