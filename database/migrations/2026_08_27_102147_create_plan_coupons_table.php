<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cupones de descuento para suscripciones (pedido explícito del usuario:
     * "generar cupones de descuentos... para clientes y para conductores
     * como para cooperativa... si el cupon cubre el 100 o 50"). Se llama
     * `plan_coupons` (no `coupons` a secas) porque esa tabla ya existe para
     * el "Centro de cupones y beneficios" (promociones de comercios
     * aliados, ver App\Models\Coupon) — son dos conceptos completamente
     * distintos que por casualidad comparten el mismo nombre común.
     *
     * Un cupón está ligado a un ROL (driver/client/cooperative), no a un
     * plan puntual — aplica como porcentaje de descuento sobre el precio de
     * lista de cualquier plan de ese lado, mismo criterio de "% sobre
     * precio de lista" que ya usa el descuento cruzado de cooperativa
     * (PlanLimits::driverDiscountFor()). `max_redemptions` null = sin
     * límite de usos totales; cada usuario solo puede usar un cupón
     * puntual una vez (se valida contra subscription_requests.plan_coupon_id,
     * mismo patrón que PlanPromotion::isEligibleFor()).
     */
    public function up(): void
    {
        Schema::create('plan_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->enum('owner_type', ['driver', 'client', 'cooperative']);
            $table->unsignedTinyInteger('discount_percent');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            // Nota interna del admin (ej. "Campaña de lanzamiento"), nunca se
            // le muestra al usuario que canjea el cupón.
            $table->string('label')->nullable();
            $table->foreignId('created_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_coupons');
    }
};
