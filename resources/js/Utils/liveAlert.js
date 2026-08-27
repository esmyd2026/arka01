// Aviso audible + vibración para eventos en vivo importantes (pedido
// explícito del usuario: "que salga una notificación con sonido... y que
// vibre el celular si es necesario"). El tono se sintetiza con Web Audio API
// en vez de cargar un archivo de audio — nada que alojar ni que pueda
// romperse por una URL caída. Es el aviso EN LA APP (pestaña abierta), que es
// más confiable que esperar la notificación del sistema operativo: muchos
// navegadores no suenan la notificación push si la pestaña ya está enfocada.
let audioCtx = null;

function context() {
    if (!window.AudioContext && !window.webkitAudioContext) return null;
    audioCtx ??= new (window.AudioContext || window.webkitAudioContext)();
    return audioCtx;
}

/**
 * Pedido explícito del usuario: los avisos con sonido de toda la app
 * dependen de la política de autoplay del navegador (sin un gesto real del
 * usuario antes, el `AudioContext` queda "suspended" y no suena nada) — en
 * vez de esperar a que cada aviso individual choque contra eso, se
 * desbloquea acá mismo en el momento en que el usuario ya está dando su
 * consentimiento explícito para algo parecido (activar notificaciones, un
 * clic real que el navegador sí cuenta como gesto de usuario). Llamarla
 * ahí dejar el resto de los avisos de esta sesión sonando de una,
 * sin volver a depender de que la próxima alerta también tenga un clic
 * fresco detrás.
 */
export function unlockAudioContext() {
    try {
        const ctx = context();
        if (ctx && ctx.state === 'suspended') ctx.resume();
    } catch {
        // Sin Web Audio en este navegador — no pasa nada, los avisos
        // visuales siguen funcionando igual.
    }
}

let armed = false;

/**
 * Bug reportado por el usuario ("los sonidos... no estan sonando"):
 * unlockAudioContext() solo se llamaba desde un botón puntual (activar
 * notificaciones) — quien ya tenía el permiso concedido de antes, o cerró
 * ese aviso, nunca daba ese clic exacto en la sesión, así que el
 * AudioContext se quedaba "suspended" toda la sesión y cada playX()
 * fallaba en silencio (el aviso visual seguía andando, el sonido no).
 * Los navegadores solo cuentan como "gesto real" un clic/toque/tecla
 * DENTRO del handler mismo — por eso esto se engancha al primer clic,
 * toque o tecla de toda la sesión, sin importar en qué botón haya sido,
 * en vez de depender de que el usuario encuentre ese botón puntual.
 */
export function armAudioUnlockOnFirstInteraction() {
    if (armed) return;
    armed = true;

    const unlock = () => {
        unlockAudioContext();
        document.removeEventListener('pointerdown', unlock);
        document.removeEventListener('keydown', unlock);
    };

    document.addEventListener('pointerdown', unlock, { once: true, passive: true });
    document.addEventListener('keydown', unlock, { once: true });
}

function tone(ctx, frequency, startAt, durationSeconds, peakGain) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(Math.max(0.0002, peakGain), startAt + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + durationSeconds);
    osc.connect(gain).connect(ctx.destination);
    osc.start(startAt);
    osc.stop(startAt + durationSeconds);
}

/**
 * Nota + su octava por encima, apagándose más rápido — un solo `tone()`
 * plano suena a pitido; sumarle este armónico agudo por encima es lo que le
 * da el color de "campanita" en vez de "beep".
 */
function bellTone(ctx, frequency, startAt, durationSeconds, peakGain) {
    tone(ctx, frequency, startAt, durationSeconds, peakGain);
    tone(ctx, frequency * 2, startAt, durationSeconds * 0.35, peakGain * 0.25);
}

export function vibrateDevice(pattern = [200, 100, 200]) {
    if (navigator.vibrate) navigator.vibrate(pattern);
}

/**
 * Catálogo de sonidos elegibles (pedido explícito del usuario: "una lista de
 * sonidos que pueda seleccionar para las notificaciones... desde el panel
 * administrativo"). Cada uno es autocontenido: su propia secuencia de tonos
 * y, si corresponde, su propio patrón de vibración. Las claves tienen que
 * coincidir tal cual con App\Services\NotificationSoundRegistry::SOUNDS
 * (PHP) — es la lista que arma el selector del admin — y con
 * DEFAULT_CATEGORY_SOUND de acá abajo (una clave nueva en un lado sin la
 * otra no rompe nada, pero tampoco aparece/suena).
 *
 * Los `peakGain` de acá ya están pensados para sonar al máximo razonable sin
 * distorsionar (el `AudioContext` recorta cualquier pico que pase de 1.0) —
 * el volumen maestro del admin (`applyVolumeScale`) multiplica desde acá
 * hacia abajo, nunca hacia arriba.
 */
const SOUND_PRESETS = {
    attention: {
        vibrate: [200, 100, 200],
        play(ctx, now, scale) {
            tone(ctx, 880, now, 0.16, 0.55 * scale);
            tone(ctx, 1046.5, now + 0.18, 0.18, 0.55 * scale);
        },
    },
    urgent: {
        vibrate: [300, 150, 300, 150, 300],
        play(ctx, now, scale) {
            tone(ctx, 784, now, 0.16, 0.9 * scale);
            tone(ctx, 988, now + 0.18, 0.16, 0.9 * scale);
            tone(ctx, 1174.7, now + 0.36, 0.22, 0.9 * scale);
        },
    },
    cabin: {
        vibrate: null,
        play(ctx, now, scale) {
            bellTone(ctx, 1046.5, now, 0.35, 0.5 * scale);
            bellTone(ctx, 783.99, now + 0.22, 0.55, 0.5 * scale);
        },
    },
    soft: {
        vibrate: null,
        play(ctx, now, scale) {
            tone(ctx, 660, now, 0.2, 0.4 * scale);
        },
    },
    classic_bell: {
        vibrate: null,
        play(ctx, now, scale) {
            bellTone(ctx, 523.25, now, 0.6, 0.55 * scale);
        },
    },
    double_knock: {
        vibrate: [120, 80, 120],
        play(ctx, now, scale) {
            tone(ctx, 392, now, 0.09, 0.65 * scale);
            tone(ctx, 392, now + 0.14, 0.09, 0.65 * scale);
        },
    },
    marimba: {
        vibrate: null,
        play(ctx, now, scale) {
            bellTone(ctx, 523.25, now, 0.14, 0.4 * scale);
            bellTone(ctx, 659.25, now + 0.1, 0.14, 0.4 * scale);
            bellTone(ctx, 783.99, now + 0.2, 0.2, 0.4 * scale);
        },
    },
    siren: {
        vibrate: [150, 100, 150, 100, 150],
        play(ctx, now, scale) {
            tone(ctx, 600, now, 0.12, 0.7 * scale);
            tone(ctx, 850, now + 0.13, 0.12, 0.7 * scale);
            tone(ctx, 600, now + 0.26, 0.12, 0.7 * scale);
            tone(ctx, 850, now + 0.39, 0.12, 0.7 * scale);
        },
    },
    ding_dong: {
        vibrate: null,
        play(ctx, now, scale) {
            bellTone(ctx, 783.99, now, 0.3, 0.5 * scale);
            bellTone(ctx, 659.25, now + 0.28, 0.4, 0.5 * scale);
        },
    },
};

// Sonido de fábrica para cada categoría si el admin todavía no eligió nada
// (mismo comportamiento de siempre, para que nadie note un cambio hasta que
// entre a /admin/sistema a elegir algo distinto) — tiene que reflejar
// App\Services\NotificationSoundRegistry::CATEGORIES[*]['default'].
const DEFAULT_CATEGORY_SOUND = {
    attention: 'attention',
    update: 'soft',
    cabin: 'cabin',
    incoming_ride: 'urgent',
};

// Configuración vigente (pedido explícito del usuario: "y que tenga todo el
// volumen") — se carga una sola vez por sesión desde AuthenticatedLayout.vue
// con lo que ya venía en `$page.props` (HandleInertiaRequests::share()), sin
// pedirlo aparte. Mientras nadie la configure, categoryToSound queda vacío y
// volume en 100 (a todo volumen) — el comportamiento de hoy, intacto.
let categoryToSound = {};
let masterVolume = 100;

/**
 * Pedido explícito del usuario: elegir qué sonido usa cada categoría de
 * aviso y a qué volumen, desde /admin/sistema — ver
 * Admin\SystemController::index()/updateNotificationSounds().
 */
export function configureNotificationSounds(sounds = {}, volume = 100) {
    categoryToSound = sounds ?? {};
    masterVolume = Number.isFinite(volume) ? volume : 100;
}

function playCategory(category) {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const presetKey = categoryToSound[category] ?? DEFAULT_CATEGORY_SOUND[category] ?? 'soft';
        const preset = SOUND_PRESETS[presetKey] ?? SOUND_PRESETS.soft;
        // El tope en 1 evita que el volumen maestro, si algún día se
        // permitiera pasar de 100, sature y distorsione el sonido.
        const scale = Math.min(1, Math.max(0, masterVolume / 100));

        preset.play(ctx, ctx.currentTime, scale);
        if (preset.vibrate) vibrateDevice(preset.vibrate);
    } catch {
        // Política de autoplay del navegador u otro bloqueo: no rompemos el
        // flujo, el aviso visual (toast/badge) sigue funcionando igual.
    }
}

/**
 * Reproduce un sonido puntual del catálogo, sin pasar por ninguna categoría
 * ni vibrar por su cuenta — lo usa el botón "Probar" del panel admin para
 * escuchar un sonido antes de guardarlo.
 */
export function previewSound(presetKey, volume = 100) {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const preset = SOUND_PRESETS[presetKey] ?? SOUND_PRESETS.soft;
        const scale = Math.min(1, Math.max(0, volume / 100));
        preset.play(ctx, ctx.currentTime, scale);
    } catch {
        // Ídem playCategory(): sin Web Audio o sin gesto de usuario, no pasa nada.
    }
}

/**
 * Aviso "necesita tu atención" (ej. carrera nueva para el conductor): dos
 * tonos ascendentes, más notorio, acompañado de vibración. El sonido real
 * (y el volumen) los elige el admin — ver configureNotificationSounds().
 */
export function playAttentionAlert() {
    playCategory('attention');
}

/**
 * Aviso "algo cambió" (ej. el conductor aceptó, una carrera se completó):
 * informativo, no urgente por defecto.
 */
export function playUpdateChime() {
    playCategory('update');
}

/**
 * Aviso "el conductor avanzó la carrera" (pedido explícito del usuario: "el
 * tono de los viajes en avión cuando van a hablar por el micrófono a dar
 * indicaciones, que suena tulunnn") — usado cuando arranca, cuando el
 * conductor llega, cuando recoge al cliente y cuando se completa.
 */
export function playCabinChime() {
    playCategory('cabin');
}

/**
 * Aviso "carrera entrante" (pedido explícito del usuario: "un sonido más
 * fuerte y vibrar el cel", específicamente para esta notificación en
 * particular): el más urgente de los cuatro por defecto.
 */
export function playIncomingRideAlert() {
    playCategory('incoming_ride');
}

/**
 * Sonido de arranque para la pantalla de carga inicial (ver
 * Components/SplashScreen.vue). Pedido explícito del usuario: la primera
 * versión (bocina de barco antiguo, dos osciladores graves en diente de
 * sierra) sonaba a alarma de error, no a bienvenida — reemplazada por un
 * arpeggio corto de tres notas suaves (mismo `tone()` de acá arriba, onda
 * senoidal, volumen bajo), más parecido al "ding" discreto de abrir una
 * app que a una señal de alerta. No es configurable desde el admin (no es
 * un aviso de un evento, es la bienvenida fija de la app).
 *
 * Ojo: los navegadores bloquean el autoplay de sonido sin una interacción
 * previa del usuario en la pestaña — puede no sonar en la primera carga en
 * frío según el navegador, sin que eso rompa nada más de la pantalla.
 */
export function playStartupChime() {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const now = ctx.currentTime;
        tone(ctx, 523.25, now, 0.22, 0.14); // Do
        tone(ctx, 659.25, now + 0.14, 0.22, 0.14); // Mi
        tone(ctx, 783.99, now + 0.28, 0.32, 0.16); // Sol
    } catch {
        // Política de autoplay del navegador: sin sonido, la pantalla de
        // carga se ve igual — nunca bloquea el arranque de la app.
    }
}
