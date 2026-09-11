<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const page = usePage();
const isDark = ref(false);

// La preferencia pertenece a la cuenta, no al dispositivo completo. Esto evita
// que un cliente y un conductor que compartan navegador se cambien el tema.
const storageKey = computed(() => `arka_theme_${page.props.auth?.user?.id ?? 'guest'}`);
const actionLabel = computed(() => (isDark.value ? 'Cambiar a ambiente claro' : 'Cambiar a ambiente oscuro'));

function applyTheme(theme, persist = true) {
    isDark.value = theme === 'dark';
    document.documentElement.classList.toggle('dark', isDark.value);
    document.documentElement.dataset.theme = isDark.value ? 'dark' : 'light';

    const themeColor = document.querySelector('meta[name="theme-color"]');
    themeColor?.setAttribute('content', isDark.value ? '#0a0f0c' : '#dfe9e4');

    if (!persist) return;

    try {
        window.localStorage.setItem(storageKey.value, isDark.value ? 'dark' : 'light');
    } catch (error) {
        // El selector continúa funcionando durante la sesión aunque el navegador
        // haya bloqueado el almacenamiento local.
    }
}

function toggleTheme() {
    applyTheme(isDark.value ? 'light' : 'dark');
}

function syncTheme(event) {
    if (event.key === storageKey.value && ['light', 'dark'].includes(event.newValue)) {
        applyTheme(event.newValue, false);
    }
}

onMounted(() => {
    applyTheme(document.documentElement.classList.contains('dark') ? 'dark' : 'light', false);
    window.addEventListener('storage', syncTheme);
});

onBeforeUnmount(() => window.removeEventListener('storage', syncTheme));
</script>

<template>
    <button
        type="button"
        class="rounded-full p-2 text-arka-text-muted transition hover:bg-arka-base hover:text-arka-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-arka-primary focus-visible:ring-offset-2 focus-visible:ring-offset-arka-card"
        :aria-label="actionLabel"
        :title="actionLabel"
        :aria-pressed="isDark"
        @click="toggleTheme"
    >
        <!-- Luna: el ambiente actual es claro y esta es la acción disponible. -->
        <svg v-if="!isDark" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20.4 15.1A8 8 0 0 1 8.9 3.6 8.5 8.5 0 1 0 20.4 15.1Z" />
        </svg>

        <!-- Sol: el ambiente actual es oscuro y permite regresar al claro. -->
        <svg v-else class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="3.5" />
            <path stroke-linecap="round" d="M12 2v2M12 20v2M4.93 4.93l1.42 1.42M17.65 17.65l1.42 1.42M2 12h2M20 12h2M4.93 19.07l1.42-1.42M17.65 6.35l1.42-1.42" />
        </svg>
    </button>
</template>
