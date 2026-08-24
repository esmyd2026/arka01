<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

// Encuesta corta de conductor/pasajero (pedido explícito del usuario:
// "aterrizar el problema actual de cada individuo... identificar si arka01
// esta abordando todo"). Pública, sin cuenta — GuestLayout se reusa tal
// cual (mismo fondo/línea de color que el login, pedido explícito del
// usuario), funciona igual con o sin sesión iniciada.
const props = defineProps({
    role: { type: String, default: null }, // 'pasajero'|'conductor', null = todavía no elegido
    questions: { type: Array, default: null },
});

// Paso 0 (sin rol elegido): dos tarjetas grandes. Recarga liviana con el
// rol en la URL (?rol=) para que refrescar la página no pierda el contexto
// — mismo criterio que el resto de flujos con query params de esta app.
function chooseRole(role) {
    router.get(route('survey.show', { rol: role }));
}

const currentIndex = ref(0);
const finished = ref(false);
const answers = ref({});

const totalQuestions = computed(() => props.questions?.length ?? 0);
const currentQuestion = computed(() => props.questions?.[currentIndex.value] ?? null);
const isLastQuestion = computed(() => currentIndex.value === totalQuestions.value - 1);

// Mismo patrón exacto de Auth/Register.vue: "Paso X de Y" + barra con el
// verde de marca — el 100% recién llega en el paso de agradecimiento.
const progressPercent = computed(() => {
    if (!totalQuestions.value) return 0;
    if (finished.value) return 100;
    return Math.round(((currentIndex.value + 1) / totalQuestions.value) * 100);
});

let autoAdvanceTimer = null;
onBeforeUnmount(() => clearTimeout(autoAdvanceTimer));

// Pedido explícito del usuario: "cuando seleccione una automaticamente vaya
// a la siguiente" — un respiro breve para que se note la selección antes
// de cambiar de pantalla (si no, se siente demasiado brusco). El botón
// "Siguiente" de abajo no depende de este timer, así que sigue sirviendo
// aunque el usuario prefiera confirmar a mano ("por si acaso").
//
// Las preguntas `multi` (pedido explícito del usuario: "en las que puede
// existir varios problemas que se junten") NO auto-avanzan: el usuario
// puede seguir marcando/desmarcando opciones, y confirma con "Siguiente"
// cuando ya eligió todas las que aplican.
function selectAnswer(optionKey) {
    if (currentQuestion.value.multi) {
        const current = answers.value[currentQuestion.value.key] ?? [];
        answers.value[currentQuestion.value.key] = current.includes(optionKey)
            ? current.filter((key) => key !== optionKey)
            : [...current, optionKey];
        return;
    }

    answers.value[currentQuestion.value.key] = optionKey;
    clearTimeout(autoAdvanceTimer);
    autoAdvanceTimer = setTimeout(() => goNext(), 350);
}

function isSelected(optionKey) {
    const current = answers.value[currentQuestion.value?.key];
    return currentQuestion.value?.multi ? (current ?? []).includes(optionKey) : current === optionKey;
}

const canGoNext = computed(() => {
    if (!currentQuestion.value) return false;
    const current = answers.value[currentQuestion.value.key];

    return currentQuestion.value.multi ? Array.isArray(current) && current.length > 0 : Boolean(current);
});

function goNext() {
    if (!canGoNext.value) return;
    clearTimeout(autoAdvanceTimer);
    if (isLastQuestion.value) {
        submit();
        return;
    }
    currentIndex.value++;
}

function goBack() {
    clearTimeout(autoAdvanceTimer);
    if (currentIndex.value > 0) currentIndex.value--;
}

const form = useForm({ role: null, answers: {} });

function submit() {
    form.role = props.role;
    form.answers = answers.value;
    form.post(route('survey.store'), {
        preserveScroll: true,
        onSuccess: () => {
            finished.value = true;
            // Pedido explícito del usuario: sin cuentas de por medio, esta
            // es la única señal razonable de "ya la respondió en este
            // dispositivo" — el Home/Login la leen para dejar de insistir,
            // sin bloquear a quien de verdad quiera entrar de nuevo a
            // /encuesta.
            localStorage.setItem('arka01_survey_done', '1');
        },
    });
}

// Botón "compartir a un amigo" (pedido explícito del usuario) — mismo
// patrón wa.me/?text= ya usado en Driver/Profile.vue (whatsappInviteUrl),
// sin depender de tener sesión iniciada (la encuesta es anónima).
const whatsappShareUrl = computed(() => {
    const text =
        'Están armando Arka01, una nueva forma de moverte en Ecuador, y quieren entender la experiencia ' +
        `actual de la gente con el transporte que ya usa. Encuesta cortita (menos de 2 minutos), sin cuenta:\n${route('survey.show')}`;

    return `https://wa.me/?text=${encodeURIComponent(text)}`;
});
</script>

<template>
    <GuestLayout>
        <Head title="Encuesta Arka01" />

        <!-- Paso 0: elegir rol. -->
        <div v-if="!role">
            <h2 class="text-xl font-bold text-arka-text text-center">Estamos por lanzar Arka01</h2>
            <p class="mt-1.5 text-sm text-arka-text-muted text-center">
                Antes de armar todo, queremos entender tu experiencia ACTUAL con el transporte que ya usas.
                Menos de 2 minutos. No hace falta tener cuenta.
            </p>

            <div class="mt-6 grid grid-cols-1 gap-3">
                <button
                    type="button"
                    class="flex items-center gap-3 rounded-arka border border-arka-primary/30 bg-arka-base/60 p-4 text-start transition hover:border-arka-primary hover:bg-arka-primary/10"
                    @click="chooseRole('pasajero')"
                >
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 text-arka-primary">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="8" r="3.5" stroke-linecap="round" stroke-linejoin="round" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 20a7.5 7.5 0 0 1 15 0" />
                        </svg>
                    </span>
                    <span class="block font-semibold text-arka-text">Soy pasajero</span>
                </button>

                <button
                    type="button"
                    class="flex items-center gap-3 rounded-arka border border-arka-primary/30 bg-arka-base/60 p-4 text-start transition hover:border-arka-primary hover:bg-arka-primary/10"
                    @click="chooseRole('conductor')"
                >
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-arka-primary/15 text-arka-primary">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" stroke-linecap="round" stroke-linejoin="round" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 10.5V7a4.5 4.5 0 0 1 9 0v3.5" />
                        </svg>
                    </span>
                    <span class="block font-semibold text-arka-text">Soy conductor</span>
                </button>
            </div>
        </div>

        <!-- Pasos 1..N: una pregunta por pantalla, con agradecimiento al final. -->
        <div v-else>
            <div class="mb-6">
                <div class="flex items-center justify-between text-xs text-arka-text-muted mb-1.5">
                    <span>{{ finished ? '¡Listo!' : `Pregunta ${currentIndex + 1} de ${totalQuestions}` }}</span>
                    <span>{{ progressPercent }}%</span>
                </div>
                <div class="h-1.5 w-full rounded-full bg-arka-text-muted/15 overflow-hidden">
                    <div class="h-full bg-arka-primary transition-all duration-300" :style="{ width: `${progressPercent}%` }" />
                </div>
            </div>

            <div v-if="!finished && currentQuestion">
                <h2 class="text-lg font-bold text-arka-text">{{ currentQuestion.text }}</h2>
                <p v-if="currentQuestion.multi" class="mt-1 text-xs text-arka-text-muted">Puedes elegir varias opciones.</p>

                <div class="mt-5 grid grid-cols-1 gap-2.5">
                    <button
                        v-for="option in currentQuestion.options"
                        :key="option.key"
                        type="button"
                        class="rounded-arka border p-3.5 text-start text-sm font-medium transition"
                        :class="isSelected(option.key)
                            ? 'border-arka-primary bg-arka-primary/15 text-arka-primary-bright'
                            : 'border-arka-text-muted/20 bg-arka-base/60 text-arka-text hover:border-arka-primary/50'"
                        @click="selectAnswer(option.key)"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <div class="mt-6 flex gap-2">
                    <SecondaryButton v-if="currentIndex > 0" @click="goBack">Volver</SecondaryButton>
                    <PrimaryButton class="flex-1 justify-center" :disabled="!canGoNext || form.processing" @click="goNext">
                        {{ isLastQuestion ? (form.processing ? 'Enviando…' : 'Terminar') : 'Siguiente' }}
                    </PrimaryButton>
                </div>
            </div>

            <!-- Agradecimiento + compartir (pedido explícito del usuario). -->
            <div v-else class="text-center py-4">
                <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-arka-primary/15 text-arka-primary">
                    <svg class="h-7 w-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m5 12.5 4.5 4.5L19 7" />
                    </svg>
                </span>
                <h2 class="mt-4 text-xl font-bold text-arka-text">¡Gracias por contarnos tu experiencia!</h2>
                <p class="mt-1.5 text-sm text-arka-text-muted">Cada respuesta nos ayuda a construir Arka01 para resolver lo que de verdad te importa.</p>

                <a
                    :href="whatsappShareUrl"
                    target="_blank"
                    rel="noopener"
                    class="mt-6 inline-flex items-center justify-center gap-2 w-full rounded-arka bg-arka-primary px-5 py-3 text-sm font-bold uppercase tracking-wide text-arka-base hover:bg-arka-primary-bright transition"
                >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="18" cy="5" r="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="6" cy="12" r="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        <circle cx="18" cy="19" r="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m8.2 10.7 7.6-4.4M8.2 13.3l7.6 4.4" />
                    </svg>
                    Compartir con un amigo
                </a>

                <!-- Pedido explícito del usuario: "coloca el boton ir al inicio pero mas
                pequeño que el otro" — más chico y sin el color sólido de marca para no
                competir con el botón de compartir, que es la acción principal acá. -->
                <Link
                    href="/"
                    class="mt-3 inline-flex items-center justify-center gap-1.5 w-full rounded-arka border border-arka-text-muted/20 px-4 py-2 text-xs font-semibold text-arka-text-muted hover:text-arka-text hover:border-arka-primary/40 transition"
                >
                    <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m4 10.5 8-6.5 8 6.5" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 9.5V19a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V9.5" />
                    </svg>
                    Ir al inicio
                </Link>
            </div>
        </div>
    </GuestLayout>
</template>
