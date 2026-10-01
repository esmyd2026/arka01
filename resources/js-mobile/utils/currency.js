// Copia de resources/js/Utils/currency.js — no hay mecanismo de código
// compartido entre resources/js y resources/js-mobile hoy (vite configs
// independientes, mismo criterio ya usado en components/AddressAutocomplete.vue).

// Formato de moneda (pedido explícito del usuario: "arka01 debe funcionar en
// cualquier país") — antes cada pantalla escribía "$"+toFixed(2) a mano,
// asumiendo siempre dólares con 2 decimales. `country` es el objeto que
// trae el usuario cacheado en Preferences (ver services/auth.js,
// UserResource) o el config público (/api/v1/config), SIEMPRE explícito,
// nunca implícito/global.
export function formatCurrency(amount, country) {
    return new Intl.NumberFormat('es-'.concat(country?.iso_code ?? 'EC'), {
        style: 'currency',
        currency: country?.currency_code ?? 'USD',
        minimumFractionDigits: country?.decimal_digits ?? 2,
        maximumFractionDigits: country?.decimal_digits ?? 2,
    }).format(Number(amount ?? 0));
}
