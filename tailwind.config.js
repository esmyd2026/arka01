import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    // El tema se controla con una clase en <html>; así cada cuenta puede conservar
    // su preferencia sin duplicar estilos en cada pantalla.
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
            // Los colores semánticos leen variables CSS para cambiar toda la
            // aplicación entre claro y oscuro con un solo selector.
            colors: {
                arka: {
                    base: 'rgb(var(--arka-base) / <alpha-value>)',
                    card: 'rgb(var(--arka-card) / <alpha-value>)',
                    surface: 'rgb(var(--arka-surface) / <alpha-value>)',
                    ink: 'rgb(var(--arka-ink) / <alpha-value>)',
                    primary: 'rgb(var(--arka-primary) / <alpha-value>)',
                    'primary-bright': 'rgb(var(--arka-primary-bright) / <alpha-value>)',
                    lime: 'rgb(var(--arka-lime) / <alpha-value>)',
                    text: 'rgb(var(--arka-text) / <alpha-value>)',
                    'text-muted': 'rgb(var(--arka-text-muted) / <alpha-value>)',
                    border: 'rgb(var(--arka-border) / <alpha-value>)',
                    warning: 'rgb(var(--arka-warning) / <alpha-value>)',
                    danger: 'rgb(var(--arka-danger) / <alpha-value>)',
                    cream: 'rgb(var(--arka-cream) / <alpha-value>)',
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
