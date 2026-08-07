<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordenadas aproximadas del centro de cada ciudad (consideración
     * agregada al alcance: "si selecciona otra ciudad que el mapa se ubique
     * allí"). Al pedir una carrera, el mapa arranca en la ubicación real del
     * cliente (geolocalización del navegador); esto solo se usa para
     * recentrar cuando cambia de ciudad manualmente.
     */
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('province');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });

        $coordinates = [
            'Quito' => [-0.1807, -78.4678],
            'Guayaquil' => [-2.1894, -79.8891],
            'Cuenca' => [-2.9006, -79.0045],
            'Durán' => [-2.1710, -79.8296],
            'Milagro' => [-2.1344, -79.5964],
            'Samborondón' => [-1.9667, -79.7333],
            'Sangolquí' => [-0.3333, -78.4500],
            'Cayambe' => [0.0333, -78.1500],
            'Guaranda' => [-1.5833, -79.0000],
            'Azogues' => [-2.7333, -78.8333],
            'Tulcán' => [0.8167, -77.7167],
            'Riobamba' => [-1.6636, -78.6547],
            'Latacunga' => [-0.9333, -78.6167],
            'Machala' => [-3.2667, -79.9667],
            'Esmeraldas' => [0.9667, -79.6500],
            'Puerto Baquerizo Moreno' => [-0.9000, -89.6000],
            'Ibarra' => [0.3500, -78.1167],
            'Otavalo' => [0.2333, -78.2667],
            'Loja' => [-3.9931, -79.2042],
            'Babahoyo' => [-1.8017, -79.5347],
            'Quevedo' => [-1.0225, -79.4650],
            'Portoviejo' => [-1.0546, -80.4525],
            'Manta' => [-0.9500, -80.7333],
            'Macas' => [-2.3167, -78.1167],
            'Tena' => [-0.9950, -77.8117],
            'Puerto Francisco de Orellana' => [-0.4667, -76.9833],
            'Puyo' => [-1.4833, -78.0000],
            'Santa Elena' => [-2.2270, -80.8590],
            'Salinas' => [-2.2141, -80.9585],
            'La Libertad' => [-2.2333, -80.9000],
            'Santo Domingo' => [-0.2500, -79.1750],
            'Nueva Loja' => [0.0833, -76.8833],
            'Ambato' => [-1.2417, -78.6197],
            'Zamora' => [-4.0667, -78.9500],
        ];

        foreach ($coordinates as $cityName => [$lat, $lng]) {
            DB::table('cities')->where('name', $cityName)->update(['lat' => $lat, 'lng' => $lng]);
        }
    }

    public function down(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
