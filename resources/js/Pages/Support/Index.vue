<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import { Head, usePage } from '@inertiajs/vue3';
import { playUpdateChime } from '@/Utils/liveAlert';

const props = defineProps({
    faqs: { type: Array, required: true },
    isDriver: { type: Boolean, required: true },
    ticket: { type: Object, default: null },
    messages: { type: Array, default: () => [] },
});

// Buscador del Centro de Ayuda (pedido explícito del usuario) — filtra en el
// navegador, la lista completa ya viene cargada de una (no son miles de filas).
const search = ref('');
const filteredFaqs = computed(() => {
    const term = search.value.trim().toLowerCase();
    if (!term) return props.faqs;
    return props.faqs.filter((faq) => faq.question.toLowerCase().includes(term) || faq.answer.toLowerCase().includes(term));
});

const categories = computed(() => [...new Set(filteredFaqs.value.map((faq) => faq.category))]);
function faqsInCategory(category) {
    return filteredFaqs.value.filter((faq) => faq.category === category);
}

const openFaqId = ref(null);
function toggleFaq(id) {
    openFaqId.value = openFaqId.value === id ? null : id;
}

// "Hablar con soporte" (sección 12 del roadmap de mejoras) — mismo patrón de
// chat que Ride/Show.vue: historial + respuestas rápidas por rol + texto libre.
const chatMessages = ref([...props.messages]);
const chatTicketId = ref(props.ticket?.id ?? null);
const chatBody = ref('');
const chatSending = ref(false);
const chatError = ref('');
const chatListEl = ref(null);
let ticketChannel = null;

const QUICK_REPLIES_DRIVER = [
    'Tengo problemas con mi suscripción.',
    'No puedo subir mis documentos.',
    'Mi cuenta sigue en revisión.',
    'Tengo problemas con una carrera.',
    'Tengo problemas con mi tarifa.',
];
const QUICK_REPLIES_CLIENT = [
    'Tengo problemas con mi suscripción.',
    'Tengo problemas solicitando una carrera.',
    'Tengo un problema con un conductor.',
    'Tengo problemas con mi cuenta.',
    'Necesito ayuda con un viaje.',
];
const quickReplies = computed(() => (props.isDriver ? QUICK_REPLIES_DRIVER : QUICK_REPLIES_CLIENT));

async function scrollChatToBottom() {
    await nextTick();
    if (chatListEl.value) chatListEl.value.scrollTop = chatListEl.value.scrollHeight;
}

function subscribeToTicket(id) {
    if (!id || ticketChannel) return;
    ticketChannel = window.Echo.private(`support-ticket.${id}`);
    ticketChannel.listen('.support.message.sent', (e) => {
        chatMessages.value.push(e);
        playUpdateChime();
        scrollChatToBottom();
    });
}

onMounted(() => {
    scrollChatToBottom();
    subscribeToTicket(chatTicketId.value);
});

onBeforeUnmount(() => {
    if (chatTicketId.value) window.Echo.leave(`support-ticket.${chatTicketId.value}`);
});

async function sendChatMessage(text) {
    const body = (text ?? chatBody.value).trim();
    if (!body || chatSending.value) return;

    chatSending.value = true;
    chatError.value = '';

    try {
        const { data } = await window.axios.post(route('support.messages.store'), { body });
        chatMessages.value.push(data);
        chatBody.value = '';
        scrollChatToBottom();

        // El primer mensaje recién ahí crea el ticket — se suscribe apenas
        // se conoce su id, para recibir en vivo lo que responda soporte.
        if (!chatTicketId.value) {
            chatTicketId.value = data.ticket_id;
            subscribeToTicket(chatTicketId.value);
        }
    } catch (error) {
        chatError.value = error.response?.data?.errors?.body?.[0] ?? 'No se pudo mandar el mensaje.';
    } finally {
        chatSending.value = false;
    }
}
</script>

<template>
    <Head title="Centro de ayuda" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Centro de ayuda</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="relative">
                    <svg class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-arka-text-muted" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="7" stroke-linecap="round" stroke-linejoin="round" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="m20 20-3.5-3.5" />
                    </svg>
                    <input
                        v-model="search"
                        type="text"
                        placeholder="Buscar en preguntas frecuentes"
                        class="w-full rounded-arka bg-arka-card border border-arka-text-muted/20 py-2.5 ps-10 pe-3 text-sm text-arka-text placeholder:text-arka-text-muted focus:outline-none focus:ring-2 focus:ring-arka-primary"
                    />
                </div>

                <p v-if="!filteredFaqs.length" class="text-sm text-arka-text-muted">
                    No encontramos nada con "{{ search }}" — pruebe con otras palabras, o hable con soporte más abajo.
                </p>

                <div v-for="category in categories" :key="category" class="space-y-2">
                    <h3 class="text-sm font-medium text-arka-text-muted uppercase tracking-wide">{{ category }}</h3>
                    <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                        <div v-for="faq in faqsInCategory(category)" :key="faq.id">
                            <button
                                type="button"
                                class="w-full p-4 text-start flex items-center justify-between gap-4"
                                @click="toggleFaq(faq.id)"
                            >
                                <span class="text-arka-text font-medium">{{ faq.question }}</span>
                                <span class="text-arka-text-muted shrink-0">{{ openFaqId === faq.id ? '−' : '+' }}</span>
                            </button>
                            <p v-if="openFaqId === faq.id" class="px-4 pb-4 text-sm text-arka-text-muted">{{ faq.answer }}</p>
                        </div>
                    </div>
                </div>

                <!-- Hablar con soporte -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">¿No encontró lo que buscaba?</h3>
                        <p class="text-sm text-arka-text-muted">Escríbanos acá mismo — un admin le responde apenas pueda.</p>
                    </div>

                    <div v-if="chatMessages.length" ref="chatListEl" class="max-h-64 overflow-y-auto space-y-2 pe-1">
                        <div
                            v-for="message in chatMessages"
                            :key="message.id"
                            class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                            :class="message.sender_user_id === $page.props.auth.user.id
                                ? 'ms-auto bg-arka-primary text-arka-base'
                                : 'bg-arka-base text-arka-text'"
                        >
                            <p v-if="message.sender_is_admin" class="text-xs font-medium opacity-70">Soporte Arka01</p>
                            <p>{{ message.body }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="reply in quickReplies"
                            :key="reply"
                            type="button"
                            class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                            :disabled="chatSending"
                            @click="sendChatMessage(reply)"
                        >
                            {{ reply }}
                        </button>
                    </div>

                    <form @submit.prevent="sendChatMessage()" class="flex items-center gap-2">
                        <TextInput v-model="chatBody" type="text" class="flex-1" placeholder="Escriba su consulta…" maxlength="1000" />
                        <PrimaryButton :disabled="chatSending || !chatBody.trim()">Enviar</PrimaryButton>
                    </form>
                    <InputError :message="chatError" />
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
