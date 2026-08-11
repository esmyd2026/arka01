<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputError from '@/Components/InputError.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, router } from '@inertiajs/vue3';
import { playUpdateChime } from '@/Utils/liveAlert';

const props = defineProps({
    ticket: { type: Object, required: true },
});

const STATUS_LABEL = {
    nuevo: 'Nuevo',
    en_atencion: 'En atención',
    esperando_usuario: 'Esperando usuario',
    resuelto: 'Resuelto',
    cerrado: 'Cerrado',
};

const messages = ref([...props.ticket.messages]);
const status = ref(props.ticket.status);
const body = ref('');
const sending = ref(false);
const error = ref('');
const listEl = ref(null);
let channel = null;

// Mensajes rápidos del admin (mismo criterio que los del usuario, sección 12
// del roadmap de mejoras) — agiliza las respuestas más comunes.
const QUICK_REPLIES = [
    '¡Hola! ¿En qué le podemos ayudar?',
    'Ya estamos revisando su caso.',
    '¿Nos puede dar más detalle?',
    'Quedó resuelto de nuestro lado — cualquier cosa, escríbanos de nuevo.',
];

async function scrollToBottom() {
    await nextTick();
    if (listEl.value) listEl.value.scrollTop = listEl.value.scrollHeight;
}

onMounted(() => {
    scrollToBottom();
    channel = window.Echo.private(`support-ticket.${props.ticket.id}`);
    channel.listen('.support.message.sent', (e) => {
        messages.value.push(e);
        playUpdateChime();
        scrollToBottom();
    });
});

onBeforeUnmount(() => window.Echo.leave(`support-ticket.${props.ticket.id}`));

async function send(text) {
    const text_ = (text ?? body.value).trim();
    if (!text_ || sending.value) return;

    sending.value = true;
    error.value = '';

    try {
        const { data } = await window.axios.post(route('admin.support-tickets.reply', props.ticket.id), { body: text_ });
        messages.value.push(data);
        body.value = '';
        if (status.value === 'nuevo' || status.value === 'en_atencion') status.value = 'esperando_usuario';
        scrollToBottom();
    } catch (e) {
        error.value = e.response?.data?.errors?.body?.[0] ?? 'No se pudo mandar el mensaje.';
    } finally {
        sending.value = false;
    }
}

function changeStatus() {
    router.patch(route('admin.support-tickets.update-status', props.ticket.id), { status: status.value }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Ticket de soporte" />

    <AdminLayout title="Soporte">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-center gap-4">
                    <UserAvatar :user="ticket.user" size-class="h-12 w-12 text-base shrink-0" />
                    <div class="flex-1 min-w-0">
                        <p class="text-arka-text font-medium">{{ ticket.user.name }}</p>
                        <p class="text-sm text-arka-text-muted">{{ ticket.user.email }}</p>
                    </div>
                    <select v-model="status" class="rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text text-sm" @change="changeStatus">
                        <option v-for="(label, value) in STATUS_LABEL" :key="value" :value="value">{{ label }}</option>
                    </select>
                </div>

                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div ref="listEl" class="max-h-96 overflow-y-auto space-y-2 pe-1">
                        <div
                            v-for="message in messages"
                            :key="message.id"
                            class="max-w-[80%] px-3 py-2 rounded-arka text-sm"
                            :class="message.sender_is_admin
                                ? 'ms-auto bg-arka-primary text-arka-base'
                                : 'bg-arka-base text-arka-text'"
                        >
                            <p v-if="!message.sender_is_admin" class="text-xs font-medium opacity-70">{{ ticket.user.name }}</p>
                            <p>{{ message.body }}</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="reply in QUICK_REPLIES"
                            :key="reply"
                            type="button"
                            class="px-2 py-1 rounded-arka text-xs bg-arka-base text-arka-text-muted hover:text-arka-text border border-arka-text-muted/20"
                            :disabled="sending"
                            @click="send(reply)"
                        >
                            {{ reply }}
                        </button>
                    </div>

                    <form @submit.prevent="send()" class="flex items-center gap-2">
                        <TextInput v-model="body" type="text" class="flex-1" placeholder="Responder…" maxlength="1000" />
                        <PrimaryButton :disabled="sending || !body.trim()">Enviar</PrimaryButton>
                    </form>
                    <InputError :message="error" />
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
