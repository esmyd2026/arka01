<script setup>
import { ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import Modal from '@/Components/Modal.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';

// Bug real reportado por el usuario (con captura): el <select> nativo del
// "Tipo" se veía blanco, con el tema del sistema operativo en vez del oscuro
// de la app — el navegador pinta el panel desplegable de un <select> por su
// cuenta, no hay CSS que lo alcance. Mismo componente que ya resuelve esto
// en el resto de la app (ej. ciudad/sector al pedir una carrera).
const FEEDBACK_TYPES = [
    { value: 'sugerencia', label: 'Sugerencia' },
    { value: 'problema', label: 'Problema' },
    { value: 'nueva_idea', label: 'Nueva idea' },
    { value: 'otro', label: 'Otro' },
];

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

const authUser = usePage().props.auth?.user ?? null;

// "Para Clientes" / "Para Conductores" (pedido explícito del usuario, mockup
// provisto) — reemplaza el flujo de pasos anterior por dos fichas con lo que
// gana cada lado, más el diagrama del medio.
const CLIENT_FEATURES = [
    { title: 'Tu propia flota', text: 'Agregue a sus conductores de confianza.' },
    { title: 'Elige con quién viajar', text: 'Usted decide quién le lleva y cuándo.' },
    { title: 'Conductores verificados', text: 'Perfiles verificados, viajes más seguros.' },
    { title: 'Viaja cuando lo necesites', text: 'Programe o solicite su viaje al instante.' },
];
const DRIVER_FEATURES = [
    { title: 'Tú fijas tu tarifa por km', text: 'Defina el precio que desea cobrar.' },
    { title: '0% comisión por viaje', text: 'Sin comisión por viaje, solo paga una tarifa fija mensual.' },
    { title: 'Clientes de confianza', text: 'Viaje con personas verificadas y con buen historial.' },
    { title: 'Tú decides qué viajes aceptar', text: 'Acepte solo los viajes que le convienen.' },
];

const WHY_ARKA01 = [
    { title: 'Seguridad', text: 'Botón SOS y contactos de confianza.' },
    { title: 'Privacidad', text: 'Tú decides quién ve tu información y tus viajes.' },
    { title: 'Precios justos', text: 'Transparente, sin sorpresas ni cobros ocultos.' },
    { title: 'Califica y mejora', text: 'Tu opinión ayuda a tener mejores experiencias.' },
    { title: 'Soporte real', text: 'Estamos para ayudarte cuando lo necesites.' },
];

// "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14) — público, sin
// cuenta, nombre y correo opcionales a propósito. Mockup provisto: barra
// discreta que abre el formulario en un modal, en vez de ocupar toda la
// página de una siempre.
const showingFeedback = ref(false);
const feedbackForm = useForm({
    name: '',
    email: '',
    type: 'sugerencia',
    comment: '',
});

function submitFeedback() {
    feedbackForm.post(route('platform-feedback.store'), {
        preserveScroll: true,
        onSuccess: () => {
            feedbackForm.reset('name', 'email', 'comment');
        },
    });
}
</script>

<template>
    <Head title="Arka01 — Tu círculo. Tus viajes. Tu decisión." />

    <div class="min-h-screen bg-arka-base">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
            <!-- Barra superior: ayuda + cuenta, solo si ya tiene sesión iniciada. -->
            <div class="flex justify-end items-center gap-3 mb-4">
                <Link
                    v-if="authUser"
                    :href="route('dashboard')"
                    class="flex items-center gap-2 text-sm text-arka-text-muted hover:text-arka-text"
                >
                    <UserAvatar :user="authUser" size-class="h-9 w-9 text-sm" />
                </Link>
            </div>

            <!-- Encabezado -->
            <div class="text-center max-w-2xl mx-auto">
                <ApplicationLogo size="text-5xl sm:text-6xl" />
                <h1 class="mt-5 text-2xl sm:text-3xl font-bold text-arka-text leading-tight">
                    Tu círculo - Tus viajes - <span class="text-arka-primary">Tu decisión</span>
                </h1>
                <p class="mt-3 text-arka-text-muted">
                    Arme su propia red de conductores de confianza o genere nuevas oportunidades como conductor
                    independiente.
                </p>

                <div class="mt-6 flex justify-center gap-3" v-if="canLogin">
                    <Link
                        v-if="authUser"
                        :href="route('dashboard')"
                        class="inline-flex items-center px-5 py-2.5 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
                    >
                        Ir a mi cuenta
                    </Link>
                    <template v-else>
                        <Link
                            :href="route('login')"
                            class="inline-flex items-center px-5 py-2.5 bg-transparent border border-arka-text-muted/30 rounded-arka font-semibold text-sm text-arka-text hover:bg-arka-card transition"
                        >
                            Iniciar sesión
                        </Link>
                        <Link
                            v-if="canRegister"
                            :href="route('register')"
                            class="inline-flex items-center px-5 py-2.5 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
                        >
                            Crear cuenta
                        </Link>
                    </template>
                </div>
            </div>

            <!-- Para Clientes / diagrama / Para Conductores (pedido explícito del
                 usuario, mockup provisto) -->
            <div class="mt-16 grid grid-cols-1 lg:grid-cols-[1fr_auto_1fr] items-center gap-8">
                <!-- Para Clientes -->
                <div class="p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center gap-3 mb-1">
                        <span class="h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="9" cy="8" r="3" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.5 19a6.5 6.5 0 0 1 13 0" />
                                <circle cx="17" cy="8" r="2.4" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 12.5c2.4 0 4.5 1.9 5 6.5" />
                            </svg>
                        </span>
                        <h2 class="text-lg font-semibold text-arka-text">
                            Para <span class="text-arka-primary">Clientes</span>
                        </h2>
                    </div>
                    <p class="text-sm text-arka-text-muted mb-4">Viaje con personas que conoce y en quienes confía.</p>

                    <ul>
                        <li v-for="(feature, i) in CLIENT_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <!-- Pedido explícito del usuario: una línea que una los
                                 puntos, no puntos sueltos — mismo criterio que el
                                 diagrama del medio, pero vertical. -->
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="mt-1.5 h-2 w-2 rounded-full bg-arka-primary shrink-0"></span>
                                <div v-if="i < CLIENT_FEATURES.length - 1" class="w-px flex-1 min-h-[1.5rem] bg-arka-primary/25 mt-1"></div>
                            </div>
                            <div class="pb-3">
                                <p class="text-sm font-medium text-arka-text">{{ feature.title }}</p>
                                <p class="text-xs text-arka-text-muted">{{ feature.text }}</p>
                            </div>
                        </li>
                    </ul>

                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-arka-primary hover:text-arka-primary-bright"
                    >
                        Soy cliente
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>

                <!-- Diagrama del medio -->
                <div class="text-center px-2">
                    <p class="text-sm font-medium text-arka-text-muted mb-4 max-w-[14rem] mx-auto">
                        Conectamos a su círculo de <span class="text-arka-primary">confianza</span>
                    </p>

                    <div class="flex items-center justify-center gap-2 lg:flex-col lg:gap-3">
                        <div class="flex flex-col items-center gap-1">
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-text-muted/20 flex items-center justify-center">
                                <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                                </svg>
                            </span>
                            <span class="text-[10px] text-arka-text-muted uppercase tracking-wide">Clientes</span>
                        </div>

                        <div class="w-10 lg:w-px lg:h-8 border-t-2 lg:border-t-0 lg:border-s-2 border-dashed border-arka-primary/30"></div>

                        <div class="flex flex-col items-center gap-1">
                            <span class="h-16 w-16 rounded-full bg-arka-primary/15 border-2 border-arka-primary flex items-center justify-center shadow-[0_0_20px_rgba(52,211,153,0.25)]">
                                <ApplicationLogo size="text-base" />
                            </span>
                        </div>

                        <div class="w-10 lg:w-px lg:h-8 border-t-2 lg:border-t-0 lg:border-s-2 border-dashed border-arka-primary/30"></div>

                        <div class="flex flex-col items-center gap-1">
                            <span class="h-12 w-12 rounded-full bg-arka-card border border-arka-text-muted/20 flex items-center justify-center">
                                <svg class="h-5 w-5 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                                </svg>
                            </span>
                            <span class="text-[10px] text-arka-text-muted uppercase tracking-wide">Conductores</span>
                        </div>
                    </div>

                    <ul class="mt-6 space-y-2 text-start max-w-[16rem] mx-auto">
                        <li v-for="point in ['Red privada y segura', 'Control total para usted', 'Viajes sin intermediarios']" :key="point" class="flex items-center gap-2 text-sm text-arka-text-muted">
                            <svg class="h-4 w-4 text-arka-primary shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m5 13 4 4L19 7" />
                            </svg>
                            {{ point }}
                        </li>
                    </ul>
                </div>

                <!-- Para Conductores -->
                <div class="p-6 bg-arka-card shadow rounded-arka">
                    <div class="flex items-center gap-3 mb-1">
                        <span class="h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                            <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16l2.5-6.5A2 2 0 0 1 8.35 8.2h7.3a2 2 0 0 1 1.85 1.3L20 16" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 16h16v2.5a1 1 0 0 1-1 1h-1a1 1 0 0 1-1-1V17H7v1.5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V16Z" />
                            </svg>
                        </span>
                        <h2 class="text-lg font-semibold text-arka-text">
                            Para <span class="text-arka-primary">Conductores</span>
                        </h2>
                    </div>
                    <p class="text-sm text-arka-text-muted mb-4">Usted maneja su negocio, nosotros le conectamos.</p>

                    <ul>
                        <li v-for="(feature, i) in DRIVER_FEATURES" :key="feature.title" class="flex items-start gap-3">
                            <div class="flex flex-col items-center self-stretch shrink-0">
                                <span class="mt-1.5 h-2 w-2 rounded-full bg-arka-primary shrink-0"></span>
                                <div v-if="i < DRIVER_FEATURES.length - 1" class="w-px flex-1 min-h-[1.5rem] bg-arka-primary/25 mt-1"></div>
                            </div>
                            <div class="pb-3">
                                <p class="text-sm font-medium text-arka-text">{{ feature.title }}</p>
                                <p class="text-xs text-arka-text-muted">{{ feature.text }}</p>
                            </div>
                        </li>
                    </ul>

                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="mt-5 inline-flex items-center gap-1.5 text-sm font-semibold text-arka-primary hover:text-arka-primary-bright"
                    >
                        Soy conductor
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14M13 6l6 6-6 6" />
                        </svg>
                    </Link>
                </div>
            </div>

            <!-- ¿Por qué elegir Arka01? -->
            <div class="mt-16">
                <h2 class="text-center text-lg font-semibold text-arka-text mb-6">
                    ¿Por qué elegir <span class="text-arka-primary">Arka01</span>?
                </h2>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-4">
                    <div v-for="item in WHY_ARKA01" :key="item.title" class="text-center">
                        <span class="mx-auto h-11 w-11 rounded-full bg-arka-primary/15 flex items-center justify-center mb-2">
                            <svg v-if="item.title === 'Seguridad'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
                            </svg>
                            <svg v-else-if="item.title === 'Privacidad'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5" />
                            </svg>
                            <svg v-else-if="item.title === 'Precios justos'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="8.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 7v10M14.5 9.5a2.5 2.5 0 0 0-2.5-1.5c-1.4 0-2.5.9-2.5 2s1.1 1.7 2.5 2 2.5.9 2.5 2-1.1 2-2.5 2a2.5 2.5 0 0 1-2.5-1.5" />
                            </svg>
                            <svg v-else-if="item.title === 'Califica y mejora'" class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m12 3.5 2.6 5.6 6.1.7-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6-4.5-4.2 6.1-.7L12 3.5Z" />
                            </svg>
                            <svg v-else class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                            </svg>
                        </span>
                        <p class="text-sm font-medium text-arka-text">{{ item.title }}</p>
                        <p class="text-xs text-arka-text-muted">{{ item.text }}</p>
                    </div>
                </div>
            </div>

            <!-- "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14): barra
                 discreta, el formulario en sí vive en un modal. -->
            <button
                type="button"
                class="mt-16 w-full flex items-center gap-3 p-4 bg-arka-card shadow rounded-arka text-start hover:bg-arka-card/70 transition"
                @click="showingFeedback = true"
            >
                <span class="h-10 w-10 rounded-full bg-arka-primary/15 flex items-center justify-center shrink-0">
                    <svg class="h-5 w-5 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 5.5h16v10.5H8.5L4 20V5.5Z" />
                    </svg>
                </span>
                <span class="flex-1 min-w-0">
                    <span class="block text-sm font-medium text-arka-text">Ayúdanos a mejorar Arka01</span>
                    <span class="block text-xs text-arka-text-muted">Su opinión nos ayuda a construir una mejor experiencia.</span>
                </span>
                <span class="shrink-0 px-4 py-2 rounded-arka bg-arka-primary text-arka-base text-xs font-semibold uppercase tracking-wide">
                    Enviar sugerencia
                </span>
            </button>

            <p class="mt-10 text-center text-xs text-arka-text-muted">
                <Link href="/terminos" class="hover:text-arka-primary-bright">Términos</Link>
                <span class="mx-2">·</span>
                <Link href="/privacidad" class="hover:text-arka-primary-bright">Privacidad</Link>
            </p>
        </div>

        <Modal :show="showingFeedback" max-width="md" @close="showingFeedback = false">
            <div class="p-6">
                <h3 class="text-lg font-medium text-arka-text mb-1">Ayúdanos a mejorar Arka01</h3>
                <p class="text-sm text-arka-text-muted mb-4">Su opinión nos ayuda a construir una mejor experiencia.</p>

                <form v-if="!feedbackForm.recentlySuccessful" @submit.prevent="submitFeedback" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <input
                            v-model="feedbackForm.name"
                            type="text"
                            placeholder="Nombre (opcional)"
                            class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                        <input
                            v-model="feedbackForm.email"
                            type="email"
                            placeholder="Correo (opcional)"
                            class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                        />
                    </div>
                    <SearchableSelect v-model="feedbackForm.type" :options="FEEDBACK_TYPES" placeholder="Tipo" />
                    <textarea
                        v-model="feedbackForm.comment"
                        rows="3"
                        required
                        placeholder="Su comentario"
                        class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                    ></textarea>
                    <p v-if="feedbackForm.errors.comment" class="text-xs text-arka-danger">{{ feedbackForm.errors.comment }}</p>
                    <div class="flex justify-end gap-2">
                        <button
                            type="button"
                            class="px-4 py-2 rounded-arka text-sm text-arka-text-muted hover:text-arka-text"
                            @click="showingFeedback = false"
                        >
                            Cancelar
                        </button>
                        <button
                            type="submit"
                            :disabled="feedbackForm.processing"
                            class="inline-flex items-center justify-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition disabled:opacity-50"
                        >
                            Enviar opinión
                        </button>
                    </div>
                </form>
                <div v-else class="text-center py-4">
                    <p class="text-sm text-arka-primary-bright">¡Gracias! Ya recibimos su opinión.</p>
                    <button type="button" class="mt-4 text-sm text-arka-text-muted hover:text-arka-text" @click="showingFeedback = false">
                        Cerrar
                    </button>
                </div>
            </div>
        </Modal>
    </div>
</template>
