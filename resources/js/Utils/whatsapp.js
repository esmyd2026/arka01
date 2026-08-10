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
    const message = `Buen día, inicio mi turno en Arka01 🚗 (ref:${userId})`;
    return `https://wa.me/${businessNumber}?text=${encodeURIComponent(message)}`;
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
