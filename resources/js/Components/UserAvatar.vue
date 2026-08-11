<script setup>
import { ref, watch } from 'vue';

// Avatar reutilizable (sección 13: la foto de perfil debe verse en navbar,
// perfiles y cualquier lugar donde se identifique a un usuario, con un
// respaldo consistente si no tiene foto — nunca una imagen rota).
//
// Pedido explícito del usuario (roadmap de mejoras): sin foto, en vez de
// iniciales se muestra un ícono genérico distinto según el rol — conductor
// (volante) o cliente/admin (persona) — así se ve igual de "terminado" en
// cliente, conductor y admin, en vez de que se note que "falta diseño".
// `user.role` viaja tal cual en cualquier User de Eloquent (no está en
// $hidden); en los pocos lugares donde se arma un array a mano sin esa
// clave, cae al ícono de persona por defecto — nunca a algo roto.
const props = defineProps({
    user: { type: Object, required: true },
    sizeClass: { type: String, default: 'h-9 w-9 text-sm' },
});

// Bug real reportado por el usuario (captura: el texto "GREG" desbordando
// el círculo): si avatar_url existe pero la imagen falla al cargar (foto de
// Google vencida, archivo borrado del disco), el navegador dibuja SU PROPIO
// ícono de imagen rota + el atributo alt como texto plano, sin respetar el
// tamaño chico del círculo — se ve roto pase lo que pase acá adentro. Con
// @error se detecta el fallo y se cae al mismo ícono por rol de siempre, en
// vez de dejar que el navegador improvise.
const imageFailed = ref(false);
watch(() => props.user?.avatar_url, () => (imageFailed.value = false));
</script>

<template>
    <div
        :class="sizeClass"
        class="rounded-full bg-arka-primary/15 text-arka-primary flex items-center justify-center overflow-hidden shrink-0"
    >
        <img
            v-if="user?.avatar_url && !imageFailed"
            :src="user.avatar_url"
            :alt="user.name"
            class="h-full w-full object-cover"
            referrerpolicy="no-referrer"
            @error="imageFailed = true"
        />
        <svg v-else-if="user?.role === 'conductor'" class="h-3/5 w-3/5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="12" cy="12" r="2.5" stroke-linecap="round" stroke-linejoin="round" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.5v6M12 14.5v6M20.5 12h-6M9.5 12h-6M17.5 6.5l-4.2 4.2M10.7 13.3l-4.2 4.2M17.5 17.5l-4.2-4.2M10.7 10.7 6.5 6.5" />
        </svg>
        <svg v-else class="h-3/5 w-3/5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
        </svg>
    </div>
</template>
