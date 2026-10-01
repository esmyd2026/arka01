// Antes cada pantalla (Register.vue, Driver/Profile.vue...) tenía su propia
// lista fija de prefijos telefónicos con nombre y bandera — ahora el
// catálogo llega dinámico desde el backend (App\Models\Country::active(),
// administrable en /admin/paises) y esta es la única función que lo
// convierte en las opciones que espera SearchableSelect.

// Truco estándar: cada letra ISO 3166-1 alpha-2 tiene un "regional indicator
// symbol" en Unicode a partir de U+1F1E6 (127462) para la 'A' (65) — el
// desplazamiento 127397 hace que 'A'+127397 = 127462. Evita tener que
// mantener una bandera por país a mano en la base de datos.
export function flagEmoji(isoCode) {
    if (!isoCode || isoCode.length !== 2) return '';

    return isoCode
        .toUpperCase()
        .replace(/./g, (char) => String.fromCodePoint(127397 + char.charCodeAt(0)));
}

// countries: array de Country::publicPayload() (name, iso_code, phone_prefix...).
// Devuelve el shape que espera SearchableSelect — shortLabel angosto para no
// aplastar el campo del número en móvil (bug real reportado por el usuario).
export function buildCountryCodeOptions(countries) {
    return (countries ?? []).map((country) => ({
        value: country.phone_prefix,
        label: `${flagEmoji(country.iso_code)} ${country.phone_prefix} ${country.name}`,
        shortLabel: `${flagEmoji(country.iso_code)} ${country.phone_prefix}`,
    }));
}

// Mismo criterio que App\Rules\ValidPhoneNumberLocal (backend): si el país
// no tiene un formato propio (phone_local_regex nulo), cualquier cadena de
// 7 a 10 dígitos alcanza — igual que antes para todo lo que no fuera
// Ecuador. Se descartan además los "de relleno" obvios (999999999...).
export function isValidPhoneLocal(value, country) {
    const pattern = country?.phone_local_regex ? new RegExp(country.phone_local_regex) : /^[0-9]{7,10}$/;

    return pattern.test(value) && !/^(\d)\1+$/.test(value);
}

// El máximo de dígitos que de verdad puede aceptar phone_local_regex, para
// cortar el campo en vivo (bug real reportado por el usuario: el campo
// dejaba escribir de más). Prueba longitudes de mayor a menor con puros '9'
// (calza con cualquier patrón tipo "empieza en 9, N dígitos") — si el país
// no tiene regex propio, o el patrón no matchea con dígitos, cae a 10 como
// hacía el genérico antes de este cambio.
export function maxPhoneLocalLength(country) {
    if (!country?.phone_local_regex) return 10;

    let pattern;
    try {
        pattern = new RegExp(country.phone_local_regex);
    } catch {
        return 10;
    }

    for (let length = 15; length >= 1; length--) {
        if (pattern.test('9'.repeat(length))) return length;
    }

    return 10;
}
