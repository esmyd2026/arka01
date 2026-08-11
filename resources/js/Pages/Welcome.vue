<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import ApplicationLogo from '@/Components/ApplicationLogo.vue';

defineProps({
    canLogin: {
        type: Boolean,
    },
    canRegister: {
        type: Boolean,
    },
});

// "¿Cómo funciona ARKA01?" (pedido explícito del usuario, roadmap de mejoras
// sección 13): flujo ilustrado, no un bloque de texto — mismos pasos que
// pidió textualmente, uno por rol.
const CLIENT_STEPS = [
    { icon: '👤', text: 'Cliente' },
    { icon: '📍', text: 'Indica origen y destino' },
    { icon: '🚗', text: 'Encuentra conductores disponibles de su flota' },
    { icon: '💰', text: 'Conoce opciones y tarifas' },
    { icon: '🤝', text: 'Elige su conductor, o a toda su flota' },
    { icon: '🛣️', text: 'Realiza su viaje' },
];
const DRIVER_STEPS = [
    { icon: '🚘', text: 'Conductor' },
    { icon: '⚙️', text: 'Configura su servicio y su tarifa' },
    { icon: '📍', text: 'Recibe solicitudes cercanas' },
    { icon: '🤝', text: 'Decide qué viajes aceptar' },
    { icon: '💰', text: 'Gestiona sus servicios' },
];

// "Ayúdanos a mejorar ARKA01" (sección 14) — público, sin cuenta, nombre y
// correo opcionales a propósito.
const feedbackForm = useForm({
    name: '',
    email: '',
    type: 'sugerencia',
    comment: '',
});

function submitFeedback() {
    feedbackForm.post(route('platform-feedback.store'), {
        preserveScroll: true,
        onSuccess: () => feedbackForm.reset('name', 'email', 'comment'),
    });
}
</script>

<template>
    <Head title="Arka01 — Solo suben los suyos." />

    <div class="min-h-screen bg-arka-base flex flex-col items-center justify-center px-6 text-center">
        <ApplicationLogo size="text-5xl sm:text-6xl" class="mb-4" />

        <p class="mt-2 text-arka-text-muted max-w-md">
            «Solo suben los suyos.» Arme su propia flota de conductores de confianza y pida sus viajes dentro de
            ese círculo.
        </p>

        <div class="mt-8 flex gap-4" v-if="canLogin">
            <Link
                v-if="$page.props.auth.user"
                :href="route('dashboard')"
                class="inline-flex items-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
            >
                Ir a mi cuenta
            </Link>

            <template v-else>
                <Link
                    :href="route('login')"
                    class="inline-flex items-center px-4 py-2 bg-arka-card border border-arka-text-muted/30 rounded-arka font-semibold text-sm text-arka-text hover:bg-arka-base transition"
                >
                    Iniciar sesión
                </Link>
                <Link
                    v-if="canRegister"
                    :href="route('register')"
                    class="inline-flex items-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition"
                >
                    Crear cuenta
                </Link>
            </template>
        </div>

        <!-- "¿Cómo funciona ARKA01?" (roadmap de mejoras, sección 13) -->
        <div class="mt-16 w-full max-w-4xl">
            <h2 class="text-lg font-semibold text-arka-text mb-6">¿Cómo funciona ARKA01?</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-8 text-start">
                <div v-for="(steps, flow) in { cliente: CLIENT_STEPS, conductor: DRIVER_STEPS }" :key="flow" class="space-y-0">
                    <div v-for="(step, i) in steps" :key="step.text">
                        <div class="flex items-center gap-3">
                            <span class="h-10 w-10 rounded-full bg-arka-primary/10 flex items-center justify-center text-lg shrink-0">
                                {{ step.icon }}
                            </span>
                            <p class="text-sm" :class="i === 0 ? 'text-arka-text font-semibold' : 'text-arka-text-muted'">
                                {{ step.text }}
                            </p>
                        </div>
                        <div v-if="i < steps.length - 1" class="ms-5 h-4 border-s-2 border-arka-text-muted/20"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- "Ayúdanos a mejorar ARKA01" (roadmap de mejoras, sección 14) -->
        <div class="mt-16 w-full max-w-md text-start">
            <h2 class="text-lg font-semibold text-arka-text mb-1">Ayúdanos a mejorar ARKA01</h2>
            <p class="text-sm text-arka-text-muted mb-4">Tu opinión nos ayuda a construir una mejor experiencia.</p>

            <form v-if="!feedbackForm.recentlySuccessful" @submit.prevent="submitFeedback" class="space-y-3 p-4 sm:p-6 bg-arka-card shadow rounded-arka">
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
                <select v-model="feedbackForm.type" class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm">
                    <option value="sugerencia">Sugerencia</option>
                    <option value="problema">Problema</option>
                    <option value="nueva_idea">Nueva idea</option>
                    <option value="otro">Otro</option>
                </select>
                <textarea
                    v-model="feedbackForm.comment"
                    rows="3"
                    required
                    placeholder="Su comentario"
                    class="block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm placeholder:text-arka-text-muted"
                ></textarea>
                <p v-if="feedbackForm.errors.comment" class="text-xs text-arka-danger">{{ feedbackForm.errors.comment }}</p>
                <button
                    type="submit"
                    :disabled="feedbackForm.processing"
                    class="w-full inline-flex items-center justify-center px-4 py-2 bg-arka-primary rounded-arka font-semibold text-sm text-arka-base hover:bg-arka-primary-bright transition disabled:opacity-50"
                >
                    Enviar opinión
                </button>
            </form>
            <p v-else class="p-4 sm:p-6 bg-arka-card shadow rounded-arka text-sm text-arka-primary-bright">
                ¡Gracias! Ya recibimos su opinión.
            </p>
        </div>

        <p class="mt-10 text-xs text-arka-text-muted">
            <Link href="/terminos" class="hover:text-arka-primary-bright">Términos</Link>
            <span class="mx-2">·</span>
            <Link href="/privacidad" class="hover:text-arka-primary-bright">Privacidad</Link>
        </p>
    </div>
</template>
