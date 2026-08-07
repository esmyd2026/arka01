<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Módulo de publicidad y promociones (pedido explícito del usuario):
     * espacio publicitario administrable desde el panel admin, para vender a
     * talleres, aseguradoras, lavadoras, restaurantes y otros negocios
     * aliados — nada de contenido queda quemado en código.
     */
    public function up(): void
    {
        Schema::create('ad_banners', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->string('button_label', 50)->nullable();
            $table->string('button_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            // En blanco = publicado ya mismo / sin fecha de baja.
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_banners');
    }
};
