// Mensaje inicial para abrir la ventana de 24h de WhatsApp (pedido explícito
// del usuario: "algo profesional", no un simple "Hola") — el texto en sí no
// le importa a Meta (cualquier mensaje del usuario abre la ventana), pero
// este se ve mejor en el chat del conductor. Mismo texto en todos lados
// (Dashboard.vue, Driver/Profile.vue) para que sea reconocible.
//
// El "(ref:ID)" al final NO es cosmético: es lo que le permite al webhook
// (WhatsAppWebhookController::receive()) saber quién dijo "quiero conectar
// mi WhatsApp" incluso si el número desde el que escribe no coincide con el
// que tiene guardado en su perfil — sin esto, un número que no matchea
// exacto se descartaba en silencio, sin poder avisarle a nadie qué pasó
// (pedido explícito del usuario: "si el número es diferente, indicále").
export function buildWhatsAppOptInUrl(businessNumber, userId) {
    if (!businessNumber) return null;
    const message = `Buen día, inicio mi turno en Arka01  (ref:${userId})`;
    return `https://wa.me/${businessNumber}?text=${encodeURIComponent(message)}`;
}

// Pedido explícito del usuario: "cuando manda a whatsapp está mandando
// siempre a los whatsapp business y debería ser al normal en tal caso
// primero" — en Android, si además del WhatsApp normal tiene instalado
// WhatsApp Business, el enlace universal `wa.me` a veces lo termina abriendo
// en Business (lo decide el sistema operativo, no esta app). Acá se fuerza
// el paquete `com.whatsapp` (el normal) con un intent nativo de Android, con
// el mismo link `wa.me` de siempre como respaldo si no lo tiene instalado.
// Fuera de Android esta ambigüedad no existe (no hay "WhatsApp Business" de
// escritorio/iOS en este flujo), así que ahí se abre el link de siempre tal
// cual. Sin número de destino a propósito (mismo criterio que ya usaban
// estos botones): abre el selector de contacto de WhatsApp, no le manda el
// mensaje a nadie en particular.
export function openWhatsAppChooser(message) {
    const fallbackUrl = `https://wa.me/?text=${encodeURIComponent(message)}`;

    if (!/Android/i.test(navigator.userAgent)) {
        window.open(fallbackUrl, '_blank');
        return;
    }

    window.location.href = `intent://send?text=${encodeURIComponent(message)}#Intent;scheme=whatsapp;package=com.whatsapp;S.browser_fallback_url=${encodeURIComponent(fallbackUrl)};end`;
}

// Sesión única por cuenta (pedido explícito del usuario): antes de "Pedir
// código" en Auth/Login.vue, se ofrece escribirle primero al WhatsApp
// oficial con esta frase exacta — abre la ventana de 24h, y
// WhatsAppWebhookController::receive() la reconoce (comparación de texto,
// sin "(ref:ID)" a propósito: acá el usuario todavía no probó nada, exponer
// un ID filtraría si la cuenta existe) para responder con un mensaje
// distinto al de "activarme" del conductor, guiándolo de vuelta a la web.
// El texto tiene que coincidir con SESSION_RECOVERY_TRIGGER_PHRASE en
// App\Http\Controllers\WhatsAppWebhookController — si se cambia acá, hay que
// cambiarlo ahí también.
export function buildSessionRecoveryWhatsAppUrl(businessNumber) {
    if (!businessNumber) return null;
    return `https://wa.me/${businessNumber}?text=${encodeURIComponent('Necesito recuperar mi sesión')}`;
}
