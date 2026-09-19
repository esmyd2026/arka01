<script setup>
defineProps({ compact: { type: Boolean, default: false }, darkBackground: { type: Boolean, default: false } });

// Se importa el archivo oficial para que Vite lo incluya también dentro del
// paquete de Capacitor. No se usa una recreación SVG ni texto reconstruido.
// El ícono usa una copia redimensionada solo para el bundle móvil (se ve a
// 32px como máximo, no hace falta cargar el PNG de 1069x935 del sitio web
// completo) para mantener la app liviana; ver assets/img/README si se
// necesita regenerarla a partir del original en public/img/.
import logoUrl from '../../../public/img/logo-arka01.png';
import iconUrl from '../assets/img/logo-arka01-icono.png';
</script>

<template>
    <span v-if="compact" class="compact-brand" :class="{ 'on-dark': darkBackground }"><img :src="iconUrl" alt=""><strong>Arka<span>01</span></strong></span>
    <img v-else
        :src="logoUrl"
        class="mobile-brand"
        :class="{ compact }"
        alt="Arka01"
        decoding="async"
    >
</template>

<style scoped>
.mobile-brand {
    display: block;
    width: auto;
    height: 2.85rem;
    object-fit: contain;
    filter: drop-shadow(0 8px 18px rgba(0, 0, 0, .2));
}

.mobile-brand.compact { height: 1.8rem; }
.compact-brand{display:flex;align-items:center;gap:.28rem;color:var(--arka-text)}.compact-brand img{width:2rem;height:2rem;object-fit:contain}.compact-brand strong{font-size:1.15rem;letter-spacing:-.06em}.compact-brand strong span{color:#10b981}.compact-brand.on-dark{color:#fff}
</style>
