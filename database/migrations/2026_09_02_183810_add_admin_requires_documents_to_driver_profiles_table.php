<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido explícito del usuario: con los documentos ya opcionales para todos
 * los conductores (ver DriverVerificationRequirementRegistry, interruptor
 * global en site_settings.disabled_driver_requirements), un admin necesita
 * poder volver a exigírselos a UN conductor puntual (ej. duda sobre sus
 * datos, aviso de un cliente) sin tocar el interruptor global ni afectar al
 * resto. Mismo patrón que admin_activated_at/by/note, pero en sentido
 * opuesto: en vez de saltar requisitos, los vuelve a exigir para este
 * conductor. Ver DriverProfile::missingRegistrationInformation() y
 * Admin\UserProfileController::requireDocuments().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->timestamp('admin_requires_documents_at')->nullable()->after('admin_activation_note');
            $table->foreignId('admin_requires_documents_by')->nullable()->after('admin_requires_documents_at')->constrained('users')->nullOnDelete();
            $table->text('admin_requires_documents_note')->nullable()->after('admin_requires_documents_by');
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_requires_documents_by');
            $table->dropColumn(['admin_requires_documents_at', 'admin_requires_documents_note']);
        });
    }
};
