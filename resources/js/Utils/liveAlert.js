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

function tone(ctx, frequency, startAt, durationSeconds, peakGain) {
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.value = frequency;
    gain.gain.setValueAtTime(0.0001, startAt);
    gain.gain.exponentialRampToValueAtTime(peakGain, startAt + 0.01);
    gain.gain.exponentialRampToValueAtTime(0.0001, startAt + durationSeconds);
    osc.connect(gain).connect(ctx.destination);
    osc.start(startAt);
    osc.stop(startAt + durationSeconds);
}

/**
 * Aviso "necesita tu atención" (ej. carrera nueva para el conductor): dos
 * tonos ascendentes, más notorio, acompañado de vibración.
 */
export function playAttentionAlert() {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const now = ctx.currentTime;
        tone(ctx, 880, now, 0.16, 0.3);
        tone(ctx, 1046.5, now + 0.18, 0.18, 0.3);
    } catch {
        // Política de autoplay del navegador u otro bloqueo: no rompemos el
        // flujo, el aviso visual (toast/badge) sigue funcionando igual.
    }

    vibrateDevice();
}

/**
 * Aviso "algo cambió" (ej. el conductor aceptó, una carrera se completó):
 * un solo tono suave, sin vibración — informativo, no urgente.
 */
export function playUpdateChime() {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        tone(ctx, 660, ctx.currentTime, 0.2, 0.22);
    } catch {
        // Ídem playAttentionAlert().
    }
}

export function vibrateDevice(pattern = [200, 100, 200]) {
    if (navigator.vibrate) navigator.vibrate(pattern);
}

/**
 * Aviso "carrera entrante" (pedido explícito del usuario: "un sonido más
 * fuerte y vibrar el cel", específicamente para esta notificación en
 * particular — más urgente que playAttentionAlert): tres tonos en vez de
 * dos, más volumen, y una vibración más larga tipo "llamada entrante".
 */
export function playIncomingRideAlert() {
    try {
        const ctx = context();
        if (!ctx) return;
        if (ctx.state === 'suspended') ctx.resume();

        const now = ctx.currentTime;
        tone(ctx, 784, now, 0.16, 0.5);
        tone(ctx, 988, now + 0.18, 0.16, 0.5);
        tone(ctx, 1174.7, now + 0.36, 0.22, 0.5);
    } catch {
        // Ídem playAttentionAlert(): política de autoplay u otro bloqueo, el
        // aviso visual (el modal en sí) sigue funcionando igual.
    }

    vibrateDevice([300, 150, 300, 150, 300]);
}
