<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Preguntas fijas de la encuesta corta de conductor/pasajero. Es
 * investigación de mercado PREVIA al lanzamiento (Arka01 todavía no está en
 * el mercado) sobre la experiencia ACTUAL de la gente con el transporte que
 * ya usa hoy — nunca se nombra ninguna otra plataforma (pedido explícito
 * del usuario: "no utilices nombres de otras plataformas eso no esta
 * bien"), siempre en términos genéricos ("apps de transporte", "taxis").
 * Redactadas en tuteo neutro (pedido explícito del usuario: "no como un
 * argentino o español"), nunca en voseo ("te sentís", "usás", etc.).
 *
 * Cubre las dimensiones pedidas explícitamente: seguridad, confianza,
 * costo por carrera, tiempo en conseguir un vehículo, la percepción actual
 * de inseguridad en el país, y viajar/trabajar de noche en particular — más
 * las de la tanda anterior (tantas plataformas a la vez, comisiones que
 * cobran esas empresas).
 *
 * Reglas de orden pedidas explícitamente por el usuario ("las preguntas
 * favorables las colocaste de primera y seria bueno que primero esten las
 * que me ayudaran a tabular"):
 * 1) Las preguntas que más aportan como indicador (mayor problema,
 *    seguridad, inseguridad país, tiempo de espera, precio/comisión) van
 *    primero; las de contexto (uso actual, plataformas actuales) y las
 *    aspiracionales ("¿te gustaría...?") van al final.
 * 2) Dentro de cada pregunta tipo escala de PROBLEMA/malestar, la opción
 *    más negativa va primero y la más positiva al final (ej. "muy
 *    inseguro" antes que "muy seguro") — el orden ya refleja qué tan grave
 *    es la situación reportada.
 * 3) Dentro de cada pregunta ASPIRACIONAL ("¿te gustaría...?"), es al
 *    revés: la opción positiva va primero (pedido explícito del usuario,
 *    tras ver la pregunta de "conductores conocidos" con la negativa
 *    arriba) — ahí lo que importa destacar es el interés real por la
 *    propuesta, no la gravedad de un problema.
 *
 * La pregunta MAIN_PROBLEM_QUESTION_KEY es la única marcada `multi: true`
 * (pedido explícito del usuario: "en las que puede existir varios
 * problemas que se junten como esa") — ahí varias causas pueden coexistir
 * a la vez, a diferencia de las de escala (seguridad, precio, etc.) donde
 * una sola respuesta tiene sentido. SurveyController valida `answers.*`
 * como array solo para las preguntas `multi`, y
 * Admin\SurveyMetricsController cuenta cada opción como "% de encuestados
 * que la marcó" en vez de repartir el 100% entre las opciones.
 *
 * Fijas en código, no en una tabla — mismo criterio que
 * RideController::CLIENT_CANCEL_REASONS: unas pocas opciones curadas, sin
 * necesidad de mantenimiento desde el panel admin. Única fuente de verdad,
 * consumida por SurveyController (arma el formulario + valida las
 * respuestas) y por Admin\SurveyMetricsController (etiquetas y los
 * indicadores destacados del panel).
 */
class SurveyQuestions
{
    public const ROLES = ['pasajero', 'conductor'];

    /**
     * Estas 3 preguntas comparten la misma `key` (y, para las dos últimas,
     * las mismas opciones) en AMBOS roles a propósito — así
     * Admin\SurveyMetricsController puede armar un indicador destacado
     * combinando pasajero + conductor sin casos especiales por rol.
     */
    public const MAIN_PROBLEM_QUESTION_KEY = 'mayor_problema';

    public const NIGHT_SAFETY_QUESTION_KEY = 'seguridad_noche';

    public const NIGHT_SAFETY_CONCERNING_OPTIONS = ['evito', 'muy_inseguro', 'algo_inseguro'];

    public const INSECURITY_PERCEPTION_QUESTION_KEY = 'inseguridad_pais';

    public const INSECURITY_PERCEPTION_HIGH_OPTIONS = ['muy_alta', 'alta'];

    /**
     * @return array<int, array{key: string, text: string, multi?: bool, options: array<int, array{key: string, label: string}>}>
     */
    public static function forRole(string $role): array
    {
        return match ($role) {
            'pasajero' => self::passengerQuestions(),
            'conductor' => self::driverQuestions(),
            default => throw new InvalidArgumentException("Rol de encuesta inválido: {$role}"),
        };
    }

    private static function passengerQuestions(): array
    {
        return [
            [
                'key' => self::MAIN_PROBLEM_QUESTION_KEY,
                'text' => '¿Cuál es tu mayor preocupación al pedir un viaje hoy?',
                'multi' => true,
                'options' => [
                    ['key' => 'confianza', 'label' => 'No sé quién me va a llevar'],
                    ['key' => 'seguridad', 'label' => 'Mi seguridad'],
                    ['key' => 'precio', 'label' => 'El precio es muy alto o cambia mucho'],
                    ['key' => 'demora', 'label' => 'La demora en llegar el conductor'],
                    ['key' => 'ninguna', 'label' => 'No tengo preocupaciones'],
                ],
            ],
            [
                'key' => 'seguridad_actual',
                'text' => '¿Qué tan seguro/a te sientes subiéndote hoy con un conductor desconocido?',
                'options' => [
                    ['key' => 'muy_inseguro', 'label' => 'Muy inseguro'],
                    ['key' => 'algo_inseguro', 'label' => 'Algo inseguro'],
                    ['key' => 'algo_seguro', 'label' => 'Algo seguro'],
                    ['key' => 'muy_seguro', 'label' => 'Muy seguro'],
                ],
            ],
            [
                'key' => self::NIGHT_SAFETY_QUESTION_KEY,
                'text' => '¿Qué tan seguro/a te sientes pidiendo un viaje de noche?',
                'options' => [
                    ['key' => 'evito', 'label' => 'Evito viajar de noche'],
                    ['key' => 'muy_inseguro', 'label' => 'Muy inseguro'],
                    ['key' => 'algo_inseguro', 'label' => 'Algo inseguro'],
                    ['key' => 'algo_seguro', 'label' => 'Algo seguro'],
                    ['key' => 'muy_seguro', 'label' => 'Muy seguro'],
                ],
            ],
            [
                'key' => self::INSECURITY_PERCEPTION_QUESTION_KEY,
                'text' => '¿Cómo calificarías la situación de inseguridad en el país hoy?',
                'options' => [
                    ['key' => 'muy_alta', 'label' => 'Muy alta'],
                    ['key' => 'alta', 'label' => 'Alta'],
                    ['key' => 'moderada', 'label' => 'Moderada'],
                    ['key' => 'baja', 'label' => 'Baja'],
                ],
            ],
            [
                'key' => 'tiempo_espera',
                'text' => '¿Cuánto tiempo sueles esperar hasta conseguir un vehículo?',
                'options' => [
                    ['key' => 'mas_15', 'label' => 'Más de 15 minutos'],
                    ['key' => 'muy_variable', 'label' => 'Muy variable, nunca sé cuánto'],
                    ['key' => 'entre_5_15', 'label' => 'Entre 5 y 15 minutos'],
                    ['key' => 'menos_5', 'label' => 'Menos de 5 minutos'],
                ],
            ],
            [
                'key' => 'precio_actual',
                'text' => '¿Te parece justo lo que pagas hoy por esos viajes?',
                'options' => [
                    ['key' => 'casi_nunca', 'label' => 'No, casi nunca'],
                    ['key' => 'a_veces', 'label' => 'A veces'],
                    ['key' => 'siempre', 'label' => 'Sí, siempre'],
                ],
            ],
            [
                'key' => 'confianza_identidad',
                'text' => '¿Confías en que esas plataformas verifican bien a sus conductores?',
                'options' => [
                    ['key' => 'no_confio', 'label' => 'No confío'],
                    ['key' => 'dudas', 'label' => 'Tengo dudas'],
                    ['key' => 'confio', 'label' => 'Sí, confío'],
                ],
            ],
            [
                'key' => 'uso_actual',
                'text' => '¿Con qué frecuencia usas taxis o aplicaciones de transporte hoy?',
                'options' => [
                    ['key' => 'a_diario', 'label' => 'A diario'],
                    ['key' => 'varias_semana', 'label' => 'Varias veces por semana'],
                    ['key' => 'rara_vez', 'label' => 'Rara vez'],
                    ['key' => 'nunca', 'label' => 'Nunca las uso'],
                ],
            ],
            [
                'key' => 'interes_confianza',
                'text' => '¿Te gustaría poder elegir viajar solo con conductores conocidos o recomendados por gente de confianza?',
                'options' => [
                    ['key' => 'me_encantaria', 'label' => 'Sí, me encantaría'],
                    ['key' => 'me_da_igual', 'label' => 'Me da igual'],
                    ['key' => 'prefiero_cualquiera', 'label' => 'No, prefiero cualquier conductor disponible'],
                ],
            ],
        ];
    }

    private static function driverQuestions(): array
    {
        return [
            [
                'key' => self::MAIN_PROBLEM_QUESTION_KEY,
                'text' => '¿Cuál es tu mayor problema trabajando con las plataformas actuales?',
                'multi' => true,
                'options' => [
                    ['key' => 'varias_apps', 'label' => 'Tener que usar varias apps a la vez'],
                    ['key' => 'comisiones', 'label' => 'Las comisiones que me cobran'],
                    ['key' => 'inseguridad', 'label' => 'La inseguridad al recoger desconocidos'],
                    ['key' => 'pocas_carreras', 'label' => 'Pocas carreras'],
                    ['key' => 'ninguno', 'label' => 'No tengo problemas'],
                ],
            ],
            [
                'key' => 'seguridad_conductor',
                'text' => '¿Qué tan seguro te sientes recogiendo pasajeros que no conoces?',
                'options' => [
                    ['key' => 'muy_inseguro', 'label' => 'Muy inseguro'],
                    ['key' => 'algo_inseguro', 'label' => 'Algo inseguro'],
                    ['key' => 'algo_seguro', 'label' => 'Algo seguro'],
                    ['key' => 'muy_seguro', 'label' => 'Muy seguro'],
                ],
            ],
            [
                'key' => self::NIGHT_SAFETY_QUESTION_KEY,
                'text' => '¿Qué tan seguro te sientes trabajando de noche?',
                'options' => [
                    ['key' => 'evito', 'label' => 'Evito trabajar de noche'],
                    ['key' => 'muy_inseguro', 'label' => 'Muy inseguro'],
                    ['key' => 'algo_inseguro', 'label' => 'Algo inseguro'],
                    ['key' => 'algo_seguro', 'label' => 'Algo seguro'],
                    ['key' => 'muy_seguro', 'label' => 'Muy seguro'],
                ],
            ],
            [
                'key' => self::INSECURITY_PERCEPTION_QUESTION_KEY,
                'text' => '¿Cómo calificarías la situación de inseguridad en el país hoy?',
                'options' => [
                    ['key' => 'muy_alta', 'label' => 'Muy alta'],
                    ['key' => 'alta', 'label' => 'Alta'],
                    ['key' => 'moderada', 'label' => 'Moderada'],
                    ['key' => 'baja', 'label' => 'Baja'],
                ],
            ],
            [
                'key' => 'tiempo_espera_carrera',
                'text' => '¿Cuánto tiempo sueles esperar entre una carrera y otra?',
                'options' => [
                    ['key' => 'mas_15', 'label' => 'Más de 15 minutos'],
                    ['key' => 'muy_variable', 'label' => 'Muy variable, nunca sé cuánto'],
                    ['key' => 'entre_5_15', 'label' => 'Entre 5 y 15 minutos'],
                    ['key' => 'menos_5', 'label' => 'Menos de 5 minutos'],
                ],
            ],
            [
                'key' => 'comision_actual',
                'text' => '¿Qué tan justa te parece la comisión que te cobran las plataformas que usas?',
                'options' => [
                    ['key' => 'muy_alta', 'label' => 'Muy alta'],
                    ['key' => 'alta', 'label' => 'Alta'],
                    ['key' => 'justa', 'label' => 'Justa'],
                    ['key' => 'no_pago', 'label' => 'No pago comisión'],
                ],
            ],
            [
                'key' => 'plataformas_actuales',
                'text' => '¿En cuántas plataformas de transporte trabajas actualmente para conseguir carreras?',
                'options' => [
                    ['key' => 'ninguna', 'label' => 'Ninguna todavía'],
                    ['key' => 'una', 'label' => '1'],
                    ['key' => 'dos_tres', 'label' => '2 o 3'],
                    ['key' => 'cuatro_mas', 'label' => '4 o más'],
                ],
            ],
            [
                'key' => 'interes_sin_comision',
                'text' => '¿Te gustaría trabajar con una plataforma que no te cobre comisión por cada carrera?',
                'options' => [
                    ['key' => 'me_interesa_mucho', 'label' => 'Sí, me interesa mucho'],
                    ['key' => 'tal_vez', 'label' => 'Tal vez'],
                    ['key' => 'no_me_importa', 'label' => 'No me importa'],
                ],
            ],
            [
                'key' => 'interes_flota_propia',
                'text' => '¿Te gustaría tener tus propios clientes de confianza en vez de depender de una bolsa general de pasajeros?',
                'options' => [
                    ['key' => 'me_interesa', 'label' => 'Sí, me interesa'],
                    ['key' => 'tal_vez', 'label' => 'Tal vez'],
                    ['key' => 'prefiero_bolsa_general', 'label' => 'Prefiero la bolsa general'],
                ],
            ],
        ];
    }
}
