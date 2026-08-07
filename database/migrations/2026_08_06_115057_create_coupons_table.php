<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Centro de cupones y beneficios (pedido explícito del usuario): un
     * apartado exclusivo por rol (cliente/conductor), con promociones
     * completamente independientes entre los dos — `audience` es lo que las
     * separa. Alianzas comerciales y nueva fuente de ingresos, administrable
     * por completo desde el panel admin.
     */
    public function up(): void
    {
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('audience'); // client | driver
            $table->string('image_path');
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->string('button_label', 50)->nullable();
            $table->string('button_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupons');
    }
};
