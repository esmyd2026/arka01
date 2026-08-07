<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Espacio publicitario (módulo de monetización, pedido explícito del
// usuario): slider de banners vendibles a negocios aliados — talleres,
// aseguradoras, lavadoras, restaurantes. Ubicado en el inicio, debajo del
// saludo, para no interferir con la navegación ni el resto del contenido.
const props = defineProps({
    banners: { type: Array, default: () => [] },
});

const activeIndex = ref(0);
let timer = null;

function startAutoplay() {
    stopAutoplay();
    if (props.banners.length < 2) return;
    timer = setInterval(() => {
        activeIndex.value = (activeIndex.value + 1) % props.banners.length;
    }, 6000);
}

function stopAutoplay() {
    if (timer) clearInterval(timer);
    timer = null;
}

function goTo(index) {
    activeIndex.value = index;
    startAutoplay();
}

onMounted(startAutoplay);
onBeforeUnmount(stopAutoplay);
watch(() => props.banners.length, () => {
    activeIndex.value = 0;
    startAutoplay();
});
</script>

<template>
    <div v-if="banners.length" class="relative rounded-arka overflow-hidden shadow" @mouseenter="stopAutoplay" @mouseleave="startAutoplay">
        <a
            v-for="(banner, index) in banners"
            :key="banner.id"
            :href="banner.button_url ?? undefined"
            target="_blank"
            rel="noopener sponsored"
            class="block relative w-full aspect-[16/6] sm:aspect-[16/4] bg-arka-base transition-opacity duration-500"
            :class="index === activeIndex ? 'opacity-100' : 'opacity-0 absolute inset-0 pointer-events-none'"
        >
            <img :src="banner.image_url" :alt="banner.title" class="w-full h-full object-cover" />
            <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/10 to-transparent flex flex-col justify-end p-3 sm:p-4">
                <p class="text-white font-medium text-sm sm:text-base">{{ banner.title }}</p>
                <p v-if="banner.description" class="text-white/80 text-xs sm:text-sm line-clamp-1">{{ banner.description }}</p>
                <span
                    v-if="banner.button_label"
                    class="mt-1.5 inline-block w-fit px-3 py-1 rounded-full bg-arka-primary text-arka-base text-xs font-medium"
                >
                    {{ banner.button_label }}
                </span>
            </div>
        </a>

        <!-- Puntitos de navegación manual, solo si hay más de uno. -->
        <div v-if="banners.length > 1" class="absolute bottom-2 right-2 flex gap-1.5">
            <button
                v-for="(banner, index) in banners"
                :key="banner.id"
                type="button"
                class="h-1.5 rounded-full transition-all"
                :class="index === activeIndex ? 'w-4 bg-white' : 'w-1.5 bg-white/50'"
                :aria-label="`Ver anuncio ${index + 1}`"
                @click="goTo(index)"
            />
        </div>
    </div>
</template>
