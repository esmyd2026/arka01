<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Promociones de precio por tiempo limitado en un plan (pedido explícito
     * del usuario: "regalar o promocionar los planes por un tiempo
     * determinado") — mismo patrón de vigencia por fecha que ya usa
     * `ad_banners` (starts_at/ends_at + is_active), pero acá ligado a un
     * plan puntual y con un precio promocional en vez de solo texto/imagen.
     */
    public function up(): void
    {
        Schema::create('plan_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_plan_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->decimal('promo_price', 8, 2);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_promotions');
    }
};
