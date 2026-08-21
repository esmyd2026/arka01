<?php

return [
    // Permite apagar el motor sin desplegar código. Cuando está desactivado,
    // RideDispatchCandidates conserva exactamente el orden por cercanía.
    'enabled' => env('SMART_DISPATCH_ENABLED', true),

    'version' => 'v1',

    // Pesos expresados sobre 100. La cercanía reúne distancia y ETA estimada
    // porque en esta primera versión no se hacen peticiones externas por cada
    // candidato; así el despacho sigue siendo rápido y económico.
    'weights' => [
        'proximity' => 60,
        'acceptance' => 15,
        'rating' => 10,
        'reliability' => 10,
        'idle_time' => 5,
    ],

    'maximum_relevant_distance_km' => 20,
    'minimum_history_samples' => 3,
];
