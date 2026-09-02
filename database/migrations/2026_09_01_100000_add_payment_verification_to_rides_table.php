<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            // Estado del cobro al cliente. Es independiente de la billetera
            // cooperativa-conductor, que representa el reparto del valor.
            $table->string('payment_status', 32)->default('pending')->after('transfer_payment_notified_at');
            $table->string('payment_proof_path')->nullable()->after('payment_status');
            $table->string('payment_proof_mime', 100)->nullable()->after('payment_proof_path');
            $table->unsignedBigInteger('payment_proof_original_size')->nullable()->after('payment_proof_mime');
            $table->unsignedBigInteger('payment_proof_stored_size')->nullable()->after('payment_proof_original_size');
            $table->timestamp('payment_proof_uploaded_at')->nullable()->after('payment_proof_stored_size');
            $table->timestamp('payment_confirmed_at')->nullable()->after('payment_proof_uploaded_at');
            $table->foreignId('payment_confirmed_by_user_id')->nullable()->after('payment_confirmed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('payment_rejected_at')->nullable()->after('payment_confirmed_by_user_id');
            $table->string('payment_rejection_reason', 500)->nullable()->after('payment_rejected_at');
            $table->index(['payment_status', 'payment_method']);
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropIndex(['payment_status', 'payment_method']);
            $table->dropConstrainedForeignId('payment_confirmed_by_user_id');
            $table->dropColumn([
                'payment_status',
                'payment_proof_path',
                'payment_proof_mime',
                'payment_proof_original_size',
                'payment_proof_stored_size',
                'payment_proof_uploaded_at',
                'payment_confirmed_at',
                'payment_rejected_at',
                'payment_rejection_reason',
            ]);
        });
    }
};
