<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Una conexión representa el consentimiento mutuo entre dos cuentas.
        // Los contactos SOS continúan en trusted_contacts porque pueden ser
        // personas externas sin una cuenta Arka01.
        Schema::create('trust_circle_connections', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('requester_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('addressee_user_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected'])->default('pending');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['requester_user_id', 'addressee_user_id'], 'trust_circle_pair_unique');
            $table->index(['addressee_user_id', 'status']);
            $table->index(['requester_user_id', 'status']);
        });

        // La privacidad es direccional: cada integrante decide qué comparte
        // con la otra persona, aunque la conexión sea una sola.
        Schema::create('trust_circle_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('connection_id')->constrained('trust_circle_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('relationship_label', 50)->nullable();
            $table->boolean('share_fleet')->default(false);
            $table->boolean('share_rating')->default(true);
            $table->timestamps();

            $table->unique(['connection_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_circle_settings');
        Schema::dropIfExists('trust_circle_connections');
    }
};
