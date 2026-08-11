<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Configuración de WhatsApp editable desde /admin/integraciones/whatsapp
        // (pedido explícito del usuario: "evitar tener que modificar
        // constantemente el .env"). Tabla singleton, mismo patrón que
        // pricing_settings: siempre existe una sola fila, sembrada acá mismo.
        // Los tres campos sensibles (token, app_secret, webhook_verify_token)
        // se guardan cifrados (cast 'encrypted' en el modelo, con la propia
        // APP_KEY) — nunca en texto plano ni se exponen al frontend (ver
        // App\Http\Controllers\Admin\WhatsAppSettingController::edit()).
        // Cuando un campo está vacío acá, App\Services\WhatsAppConfig cae al
        // valor de config/services.php (.env) — el .env sigue funcionando
        // como respaldo, nunca se elimina ese soporte.
        Schema::create('whatsapp_settings', function (Blueprint $table) {
            $table->id();
            $table->text('token')->nullable();
            $table->string('phone_number_id')->nullable();
            $table->string('verification_template')->nullable();
            $table->string('business_number')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->text('app_secret')->nullable();
            // Quién hizo el último cambio (pedido explícito del usuario:
            // "registrar quién realizó el cambio y cuándo" — el cuándo ya lo
            // da `updated_at`).
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('whatsapp_settings')->insert([
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('whatsapp_settings');
    }
};
