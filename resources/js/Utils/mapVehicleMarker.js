// Única silueta del vehículo Arka01 para todos los motores y pantallas.
// El lienzo cuadrado deja espacio para rotarlo sin recortar la carrocería.
// `ringColor` es opcional y aditivo (pedido explícito del usuario: "que se
// distinga el color del vehículo de acuerdo a su estado" en el mapa
// operativo de la cooperativa) — un halo detrás de la carrocería, sin tocar
// el diseño premium blanco/discreto que ya usan Inicio y el seguimiento en
// vivo, donde `ringColor` nunca se manda.
export function arkaVehicleSvg(rotation = 0, accent = '#19B982', ringColor = null) {
    const safeRotation = Number.isFinite(Number(rotation)) ? Number(rotation) : 0;
    const ring = ringColor
        ? `<circle cx="22" cy="22" r="19.5" fill="${ringColor}" fill-opacity="0.22" stroke="${ringColor}" stroke-width="2.25"/>`
        : '';

    return `<svg width="44" height="44" viewBox="0 0 44 44" fill="none" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <filter id="vehicle-shadow" x="-35%" y="-20%" width="170%" height="155%" color-interpolation-filters="sRGB">
                <feDropShadow dx="0" dy="1.5" stdDeviation="1.35" flood-color="#0B1712" flood-opacity="0.28"/>
            </filter>
            <linearGradient id="vehicle-body" x1="15" y1="3" x2="15" y2="42" gradientUnits="userSpaceOnUse">
                <stop stop-color="#FFFFFF"/><stop offset="1" stop-color="#EEF2F0"/>
            </linearGradient>
            <linearGradient id="vehicle-glass" x1="15" y1="9" x2="15" y2="34" gradientUnits="userSpaceOnUse">
                <stop stop-color="#17211E"/><stop offset="1" stop-color="#35413D"/>
            </linearGradient>
        </defs>
        ${ring}
        <g transform="rotate(${safeRotation} 22 22)">
            <g transform="translate(8 1) scale(.9)" filter="url(#vehicle-shadow)">
                <rect x="3.1" y="10.3" width="2.1" height="7.2" rx="1.05" fill="#3B4641"/>
                <rect x="24.8" y="10.3" width="2.1" height="7.2" rx="1.05" fill="#3B4641"/>
                <rect x="3.1" y="28.5" width="2.1" height="7.2" rx="1.05" fill="#3B4641"/>
                <rect x="24.8" y="28.5" width="2.1" height="7.2" rx="1.05" fill="#3B4641"/>
                <path d="M15 2.5C10.25 2.5 7.38 4.95 6.48 9.9L5.05 18.95C4.65 21.55 4.65 24.45 5.05 27.05L6.48 36.1C7.38 41.05 10.25 43.5 15 43.5C19.75 43.5 22.62 41.05 23.52 36.1L24.95 27.05C25.35 24.45 25.35 21.55 24.95 18.95L23.52 9.9C22.62 4.95 19.75 2.5 15 2.5Z" fill="url(#vehicle-body)" stroke="#58645F" stroke-width="0.8"/>
                <path d="M8.25 11.4C8.85 7.7 10.9 5.75 15 5.75C19.1 5.75 21.15 7.7 21.75 11.4L22.1 14.1H7.9L8.25 11.4Z" fill="url(#vehicle-glass)"/>
                <path d="M7.7 17H22.3L22.72 25.15H7.28L7.7 17Z" fill="#F9FBFA" stroke="#D5DCD8" stroke-width="0.55"/>
                <path d="M8 28H22L21.55 34.55C21.25 37.55 19.15 39.15 15 39.15C10.85 39.15 8.75 37.55 8.45 34.55L8 28Z" fill="url(#vehicle-glass)"/>
                <path d="M11.25 17L12.05 14.1H17.95L18.75 17H11.25Z" fill="${accent}"/>
                <path d="M14.05 18.45H15.95V24.05H14.05V18.45Z" fill="${accent}" opacity="0.72"/>
                <path d="M6.1 21H7.75M22.25 21H23.9" stroke="${accent}" stroke-width="0.85" stroke-linecap="round"/>
                <path d="M7.6 7.2L10.25 5.1M22.4 7.2L19.75 5.1" stroke="#FFF5C5" stroke-width="1.15" stroke-linecap="round"/>
                <path d="M9.2 40.15H11.8M18.2 40.15H20.8" stroke="#F46A6A" stroke-width="0.9" stroke-linecap="round"/>
            </g>
        </g>
    </svg>`;
}
