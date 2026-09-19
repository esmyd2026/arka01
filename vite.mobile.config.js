import { fileURLToPath, URL } from 'node:url';
import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';

// Config de Vite separada de vite.config.js (esa sigue siendo la de
// Inertia/Laravel): la app móvil (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
// Hito 3) es una SPA independiente que no depende de respuestas HTML de
// Laravel ni de rutas web — arranca sola y consume /api/v1 por JSON.
export default defineConfig({
    plugins: [vue()],
    // Sin esto, Vite copia el `public/` de Laravel entero (index.php,
    // .htaccess, sw.js, storage...) adentro de dist-mobile — es su
    // convención por defecto para "la carpeta pública del proyecto", pero
    // acá esa carpeta es la de Laravel, no la de esta SPA.
    publicDir: false,
    build: {
        outDir: 'dist-mobile',
        emptyOutDir: true,
        rollupOptions: {
            input: fileURLToPath(new URL('./index.mobile.html', import.meta.url)),
        },
    },
});
