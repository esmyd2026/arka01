<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { playUpdateChime } from '@/Utils/liveAlert';

const props = defineProps({
    conversation: { type: Object, required: true },
    messages: { type: Array, required: true },
});

const messages = ref([...props.messages]);
const botPaused = ref(props.conversation.bot_paused);
const body = ref('');
const sending = ref(false);
const error = ref('');
const warning = ref('');
const listEl = ref(null);
let channel = null;

async function scrollToBottom() {
    await nextTick();
    if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight;
}

onMounted(() => {
    scrollToBottom();
    channel = window.Echo.private(`whatsapp-conversation.${props.conversation.id}`);
    channel.listen('.whatsapp.message.logged', (e) => {
        // El propio envío ya se agrega de una al confirmar la respuesta
        // (ver send() abajo) — acá solo entran mensajes de otro origen:
        // el cliente escribiendo, u otro admin respondiendo en paralelo.
        if (messages.value.some((m) => m.id === e.id)) return;
        messages.value.push(e);
        playUpdateChime();
        scrollToBottom();
    });
});

onBeforeUnmount(() => window.Echo.leave(`whatsapp-conversation.${props.conversation.id}`));

// Responder de verdad por WhatsApp (pedido explícito del usuario: "poder
// responder desde allí yo también") — mismo primitivo que ya usa el chat de
// un ticket de soporte, sin necesitar que exista un ticket.
async function send() {
    const text = body.value.trim();
    if (!text || sending.value) return;

    sending.value = true;
    error.value = '';
    warning.value = '';

    try {
        const { data } = await window.axios.post(route('admin.whatsapp-inbox.reply', props.conversation.id), { body: text });
        if (data.message) messages.value.push(data.message);
        body.value = '';
        if (!data.sent) warning.value = data.reason ?? 'No se pudo mandar por WhatsApp.';
        scrollToBottom();
    } catch (e) {
        error.value = e.response?.data?.errors?.body?.[0] ?? 'No se pudo mandar el mensaje.';
    } finally {
        sending.value = false;
    }
}

// "Activar el bot o no" (pedido explícito del usuario) — control manual
// por conversación, ver el chequeo en ChatbotEngine::process().
function toggleBot() {
    const next = !botPaused.value;
    router.patch(route('admin.whatsapp-inbox.toggle-bot', props.conversation.id), { bot_paused: next }, {
        preserveScroll: true,
        onSuccess: () => (botPaused.value = next),
    });
}

function formatTime(value) {
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Admin · Conversación de WhatsApp" />

    <AdminLayout title="WhatsApp">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <Link :href="route('admin.whatsapp-inbox.index')" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                    &larr; Volver al inbox
                </Link>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-center gap-4">
                    <UserAvatar v-if="conversation.user" :user="conversation.user" size-class="h-12 w-12 text-base shrink-0" />
                    <div v-else class="h-12 w-12 rounded-full bg-arka-base grid place-items-center text-arka-text-muted shrink-0">?</div>
                    <div class="flex-1 min-w-0">
                        <p class="text-arka-text font-medium">
                            {{ conversation.user?.name ?? 'Número sin cuenta' }}
                        </p>
                        <p class="text-sm text-arka-text-muted">{{ conversation.phone }}</p>
                        <Link v-if="conversation.user" :href="route('admin.users.show', conversation.user.id)" class="text-xs text-arka-primary hover:text-arka-primary-bright">
                            Ver perfil completo
                        </Link>
                    </div>

                    <!-- Interruptor de bot (pedido explícito del usuario:
                         "activar el bot o no") -->
                    <button
                        type="button"
                        class="shrink-0 px-3 py-1.5 rounded-arka text-sm font-medium"
                        :class="botPaused ? 'bg-arka-warning/15 text-arka-warning' : 'bg-arka-primary/15 text-arka-primary-bright'"
                        @click="toggleBot"
                    >
                        {{ botPaused ? 'Bot pausado — reactivar' : 'Bot activo — pausar' }}
                    </button>
                </div>

                <p v-if="botPaused" class="text-xs text-arka-warning -mt-3">
                    El bot no le va a contestar nada a este número hasta que lo reactive — solo usted ve y responde acá.
                </p>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div ref="listEl" class="max-h-96 overflow-y-auto space-y-2 pe-1">
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                            :class="message.direction === 'out'
                                ? 'ms-auto bg-arka-primary text-arka-base'
                                : 'bg-arka-base text-arka-text'"
                        >
                            <p class="whitespace-pre-wrap">{{ message.body || '[ubicación]' }}</p>
                            <p class="mt-1 text-[10px] opacity-70">{{ formatTime(message.created_at) }}</p>
                        </div>
                    </div>

                    <p v-if="!messages.length" class="py-6 text-center text-sm text-arka-text-muted">
                        Todavía no hay conversación con este número.
                    </p>

                    <form @submit.prevent="send" class="flex items-center gap-2">
                        <TextInput v-model="body" type="text" class="flex-1" placeholder="Responder…" maxlength="1000" />
                        <PrimaryButton :disabled="sending || !body.trim()">Enviar</PrimaryButton>
                    </form>
                    <InputError :message="error" />
                    <p v-if="warning" class="text-xs text-amber-500">{{ warning }}</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
