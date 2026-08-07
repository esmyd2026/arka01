<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Zonas del Ecuador" (consideración agregada al alcance): catálogo de
     * ciudades y sectores/barrios para que, al pedir una carrera, el cliente
     * pueda indicar dónde está y a dónde va sin abrir el mapa (ej. "Sauces 1"
     * → "Samanes 3"), y el conductor lo vea de un vistazo. Es un catálogo
     * editable por el admin (pantalla de mantenimiento), no una lista quemada
     * en el código — acá solo se seedea un punto de partida razonable.
     */
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('province')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('sectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['city_id', 'name']);
        });

        $now = now();

        $catalog = [
            'Quito' => [
                'province' => 'Pichincha',
                'sectors' => ['Centro Histórico', 'La Mariscal', 'La Carolina', 'Iñaquito', 'González Suárez', 'Cumbayá', 'El Bosque', 'San Rafael'],
            ],
            'Guayaquil' => [
                'province' => 'Guayas',
                'sectors' => ['Centro', 'Urdesa', 'Kennedy', 'Sauces 1', 'Sauces 9', 'Samanes 1', 'Samanes 3', 'Alborada', 'Ceibos', 'Mapasingue'],
            ],
            'Cuenca' => [
                'province' => 'Azuay',
                'sectors' => ['Centro Histórico', 'El Vergel', 'Totoracocha', 'Yanuncay', 'Racar'],
            ],
        ];

        foreach ($catalog as $cityName => $data) {
            $cityId = DB::table('cities')->insertGetId([
                'name' => $cityName,
                'province' => $data['province'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('sectors')->insert(array_map(fn (string $sector) => [
                'city_id' => $cityId,
                'name' => $sector,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['sectors']));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('cities');
    }
};
