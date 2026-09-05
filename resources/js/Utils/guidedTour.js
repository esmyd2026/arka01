import { driver } from 'driver.js';
// El CSS de Driver.js se importa una sola vez, en resources/css/app.css
// (junto al de Leaflet) — el ajuste al tema oscuro vive ahí también,
// en `.arka-tour-popover`.

/**
 * Pedido explícito del usuario: el tour de pedir carrera arranca en el
 * Dashboard ("¿A dónde vamos?", el buscador de Inicio) y continúa en
 * Ride/Request.vue (destino, forma de pago, paradas, invertir) — pero son
 * dos páginas de Inertia distintas, cada una con su propia instancia de
 * Driver.js. Esta clave en sessionStorage es la posta entre las dos: el
 * paso del Dashboard la deja escrita antes de navegar, Ride/Request.vue la
 * lee al montar para seguir automáticamente en vez de quedarse esperando a
 * que se cumpla su condición normal de "primera vez".
 */
export const RIDE_TOUR_RESUME_KEY = 'arka:ride-tour-resume';

/**
 * Envoltorio fino sobre Driver.js (pedido explícito del usuario: tutoriales
 * guiados anclados a elementos reales de la pantalla, no solo texto como
 * OnboardingTour.vue) con el tema oscuro de Arka01 y textos en español
 * consistentes en todos los tours. Los colores van hardcodeados porque
 * Driver.js inyecta su propio DOM fuera del árbol de Vue (directo a
 * `document.body`), así que las clases de Tailwind no le aplican — el ajuste
 * fino de esos colores vive en resources/css/app.css (`.arka-tour-popover`).
 *
 * @param {Array} steps - pasos de Driver.js (`{ element, popover }`).
 * @param {{ onFinish?: () => void }} options - `onFinish` corre quiera que
 *   se haya cerrado el tour (terminarlo, tocar afuera, Escape) — mismo
 *   criterio que OnboardingController::complete().
 */
export function startGuidedTour(steps, { onFinish } = {}) {
    const tour = driver({
        showProgress: true,
        overlayColor: '#0a0f0c',
        overlayOpacity: 0.75,
        stagePadding: 6,
        stageRadius: 12,
        popoverClass: 'arka-tour-popover',
        nextBtnText: 'Siguiente',
        prevBtnText: 'Atrás',
        doneBtnText: 'Listo',
        progressText: '{{current}} de {{total}}',
        steps,
        onDestroyed: () => onFinish?.(),
    });

    tour.drive();

    return tour;
}
