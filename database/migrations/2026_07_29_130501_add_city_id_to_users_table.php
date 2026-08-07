<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ciudad donde vive (consideración agregada al alcance): la
            // solicitud de carrera arranca con esta ciudad por defecto, así
            // el usuario no tiene que elegirla cada vez.
            $table->foreignId('city_id')->nullable()->after('user_type')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('city_id');
        });
    }
};
