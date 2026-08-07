<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * El catálogo de zonas arrancó con solo 3 ciudades (Quito, Guayaquil,
     * Cuenca) — acá se completa con al menos una ciudad por cada una de las
     * 24 provincias del Ecuador (más algunos cantones grandes adicionales),
     * cada una con un puñado de sectores reales. Sigue siendo un catálogo
     * editable por el admin (/admin/zonas) — esto es solo el punto de
     * partida, no una lista cerrada.
     */
    public function up(): void
    {
        $now = now();

        $catalog = [
            // Ya existen Quito, Guayaquil y Cuenca (migración original) — acá
            // se agregan más cantones grandes de esas mismas provincias.
            'Durán' => ['province' => 'Guayas', 'sectors' => ['Centro', 'El Recreo', 'Los Helechos']],
            'Milagro' => ['province' => 'Guayas', 'sectors' => ['Centro', 'Chirijos']],
            'Samborondón' => ['province' => 'Guayas', 'sectors' => ['La Puntilla', 'Entre Ríos']],
            'Sangolquí' => ['province' => 'Pichincha', 'sectors' => ['Centro', 'San Rafael', 'Triángulo']],
            'Cayambe' => ['province' => 'Pichincha', 'sectors' => ['Centro']],

            'Guaranda' => ['province' => 'Bolívar', 'sectors' => ['Centro', 'Guanujo']],
            'Azogues' => ['province' => 'Cañar', 'sectors' => ['Centro', 'Bayas']],
            'Tulcán' => ['province' => 'Carchi', 'sectors' => ['Centro', 'González Suárez']],
            'Riobamba' => ['province' => 'Chimborazo', 'sectors' => ['Centro', 'Tapi', 'Lizarzaburu', 'Bellavista']],
            'Latacunga' => ['province' => 'Cotopaxi', 'sectors' => ['Centro', 'La Estación', 'Ignacio Flores']],
            'Machala' => ['province' => 'El Oro', 'sectors' => ['Centro', 'Puerto Bolívar', 'Unioro']],
            'Esmeraldas' => ['province' => 'Esmeraldas', 'sectors' => ['Centro', 'Las Palmas', 'Bella Vista']],
            'Puerto Baquerizo Moreno' => ['province' => 'Galápagos', 'sectors' => ['Centro']],
            'Ibarra' => ['province' => 'Imbabura', 'sectors' => ['Centro', 'Yacucalle', 'Caranqui', 'El Olivo']],
            'Otavalo' => ['province' => 'Imbabura', 'sectors' => ['Centro', 'Plaza de Ponchos']],
            'Loja' => ['province' => 'Loja', 'sectors' => ['Centro', 'La Argelia', 'Zamora Huayco']],
            'Babahoyo' => ['province' => 'Los Ríos', 'sectors' => ['Centro', 'Camilo Ponce']],
            'Quevedo' => ['province' => 'Los Ríos', 'sectors' => ['Centro', 'Siete de Octubre']],
            'Portoviejo' => ['province' => 'Manabí', 'sectors' => ['Centro', 'Andrés de Vera', 'Colón']],
            'Manta' => ['province' => 'Manabí', 'sectors' => ['Centro', 'Tarqui', 'Los Esteros', 'Barbasquillo']],
            'Macas' => ['province' => 'Morona Santiago', 'sectors' => ['Centro']],
            'Tena' => ['province' => 'Napo', 'sectors' => ['Centro']],
            'Puerto Francisco de Orellana' => ['province' => 'Orellana', 'sectors' => ['Centro']],
            'Puyo' => ['province' => 'Pastaza', 'sectors' => ['Centro']],
            'Santa Elena' => ['province' => 'Santa Elena', 'sectors' => ['Centro']],
            'Salinas' => ['province' => 'Santa Elena', 'sectors' => ['Centro', 'Chipipe', 'Punta Carnero']],
            'La Libertad' => ['province' => 'Santa Elena', 'sectors' => ['Centro']],
            'Santo Domingo' => ['province' => 'Santo Domingo de los Tsáchilas', 'sectors' => ['Centro', 'Las Palmas', 'Río Verde']],
            'Nueva Loja' => ['province' => 'Sucumbíos', 'sectors' => ['Centro']],
            'Ambato' => ['province' => 'Tungurahua', 'sectors' => ['Centro', 'La Delicia', 'Huachi', 'Miraflores']],
            'Zamora' => ['province' => 'Zamora Chinchipe', 'sectors' => ['Centro']],
        ];

        foreach ($catalog as $cityName => $data) {
            // Por si esta migración se corre más de una vez sobre datos ya
            // cargados a mano por el admin — no duplica la ciudad.
            $cityId = DB::table('cities')->where('name', $cityName)->value('id');

            if (! $cityId) {
                $cityId = DB::table('cities')->insertGetId([
                    'name' => $cityName,
                    'province' => $data['province'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($data['sectors'] as $sector) {
                $exists = DB::table('sectors')->where('city_id', $cityId)->where('name', $sector)->exists();

                if (! $exists) {
                    DB::table('sectors')->insert([
                        'city_id' => $cityId,
                        'name' => $sector,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // No revierte: el admin puede haber seguido agregando sectores propios
        // sobre estas ciudades y borrarlas de nuevo se llevaría eso puesto.
    }
};
