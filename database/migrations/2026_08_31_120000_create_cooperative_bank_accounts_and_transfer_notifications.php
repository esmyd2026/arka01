<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cooperative_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cooperative_id')->constrained()->cascadeOnDelete();
            $table->string('account_holder_name', 150);
            $table->string('identity_number', 20);
            $table->string('bank_name', 100);
            $table->enum('account_type', ['ahorros', 'corriente']);
            $table->string('account_number', 30);
            $table->boolean('is_favorite')->default(false);
            $table->timestamps();

            $table->index('cooperative_id');
        });

        Schema::table('rides', function (Blueprint $table) {
            // El cliente no aprueba ni concilia el dinero: solo informa a la
            // cooperativa que ya realizó la transferencia para que ella la
            // compruebe en su cuenta bancaria.
            $table->timestamp('transfer_payment_notified_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('rides', function (Blueprint $table) {
            $table->dropColumn('transfer_payment_notified_at');
        });

        Schema::dropIfExists('cooperative_bank_accounts');
    }
};
