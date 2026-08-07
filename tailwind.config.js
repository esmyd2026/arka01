import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // Modo oscuro fijo por clase: Arka01 siempre se ve en oscuro (sección 9.9 del alcance),
    // no seguimos la preferencia del sistema operativo del usuario.
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            // Fuente de sistema en vez de una fuente externa (Google/Bunny Fonts):
            // carga más rápido y se ve nativa en cualquier dispositivo.
            fontFamily: {
                sans: [
                    '-apple-system',
                    'BlinkMacSystemFont',
                    'Segoe UI',
                    'Roboto',
                    'Helvetica Neue',
                    'Arial',
                    'sans-serif',
                ],
            },
            // Paleta oficial de Arka01 (sección 9.9 del documento de alcance).
            // Se usan nombres semánticos ("arka-...") en vez de tonos genéricos
            // para que el significado de cada color quede claro en las clases.
            colors: {
                arka: {
                    base: '#0a0f0c', // fondo general de la app
                    card: '#121b17', // fondo de tarjetas y superficies elevadas
                    primary: '#34d399', // verde menta: botones y acciones principales
                    'primary-bright': '#6ee7b7', // menta claro: énfasis, links activos
                    lime: '#a3e635', // lima: acento puntual (insignias, "nuevo", promociones)
                    text: '#e7f4ee', // texto principal
                    'text-muted': '#93ada2', // texto secundario
                    warning: '#fbbf24',
                    danger: '#f87171',
                },
            },
            // Radios consistentes en toda la interfaz (10-14px, sección 9.9).
            borderRadius: {
                arka: '12px',
            },
        },
    },

    plugins: [forms],
};
