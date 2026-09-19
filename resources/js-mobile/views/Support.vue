<script setup>
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { fetchSupport, sendSupportMessage } from '../services/support';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);

const faqs = ref([]);
const ticket = ref(null);
const messages = ref([]);
const body = ref('');
const sending = ref(false);
const showChat = ref(false);

let pollTimer = null;

async function load() {
    try {
        const data = await fetchSupport();
        faqs.value = data.faqs;
        ticket.value = data.ticket;
        messages.value = data.messages;
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el centro de ayuda.';
    } finally {
        loading.value = false;
    }
}

function schedulePoll() {
    clearTimeout(pollTimer);
    if (showChat.value) {
        pollTimer = setTimeout(async () => {
            await load();
            schedulePoll();
        }, 6000);
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    await load();
});

onBeforeUnmount(() => clearTimeout(pollTimer));

function openChat() {
    showChat.value = true;
    schedulePoll();
}

async function send() {
    if (!body.value.trim()) return;
    sending.value = true;
    error.value = null;
    try {
        await sendSupportMessage(body.value.trim());
        body.value = '';
        await load();
    } catch (e) {
        error.value = e.message || 'No se pudo enviar el mensaje.';
    } finally {
        sending.value = false;
    }
}

function formatTime(iso) {
    return new Date(iso).toLocaleString('es-EC', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page support-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Ayuda</p>
                <h1 class="mobile-title">Soporte</h1>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else-if="!showChat">
                <section v-if="faqs.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Preguntas frecuentes</p></div>
                    <details v-for="(faq, index) in faqs" :key="index" class="faq-card mobile-card">
                        <summary>{{ faq.question }}</summary>
                        <p>{{ faq.answer }}</p>
                    </details>
                </section>

                <button class="mobile-button chat-button" @click="openChat">
                    {{ ticket ? 'Continuar conversación' : 'Escribir a soporte' }}
                </button>
            </template>

            <template v-else>
                <button class="back-link" @click="showChat = false; clearTimeout(pollTimer)">‹ Preguntas frecuentes</button>

                <span v-if="ticket" class="status-chip" :class="ticket.status">{{ ticket.status === 'open' ? 'Abierto' : 'Cerrado' }}</span>

                <div class="messages-list">
                    <p v-if="!messages.length" class="empty-copy">Todavía no hay mensajes — escribe tu consulta abajo.</p>
                    <div v-for="message in messages" :key="message.id" class="message-row" :class="{ 'is-admin': message.sender_is_admin }">
                        <span class="message-sender">{{ message.sender_is_admin ? 'Soporte Arka01' : 'Tú' }} · {{ formatTime(message.created_at) }}</span>
                        <p class="message-body">{{ message.body }}</p>
                    </div>
                </div>

                <form class="send-row" @submit.prevent="send">
                    <input v-model="body" class="mobile-input" placeholder="Escribe tu mensaje…" />
                    <button class="mobile-button send-button" type="submit" :disabled="sending || !body.trim()">{{ sending ? '…' : 'Enviar' }}</button>
                </form>
            </template>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.support-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.section-heading { margin: .2rem .2rem .4rem; }
.faq-card { margin-bottom: .6rem; padding: .9rem 1rem; }
.faq-card summary { font-size: .85rem; font-weight: 700; cursor: pointer; }
.faq-card p { margin: .5rem 0 0; color: var(--arka-muted); font-size: .8rem; line-height: 1.5; }
.chat-button { margin-top: .3rem; }
.status-chip { justify-self: start; padding: .18rem .6rem; border-radius: 999px; font-size: .68rem; font-weight: 800; text-transform: uppercase; background: var(--arka-primary-soft); color: var(--arka-primary); }
.status-chip.closed { background: rgba(147,173,162,.15); color: var(--arka-muted); }
.messages-list { display: grid; gap: .6rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.message-row { padding: .7rem .9rem; border-radius: .9rem; background:var(--arka-surface); justify-self: start; max-width: 85%; }
.message-row.is-admin { justify-self: end; background: var(--arka-primary-soft); }
.message-sender { display: block; color: var(--arka-muted); font-size: .65rem; text-transform: uppercase; margin-bottom: .25rem; }
.message-body { margin: 0; color: var(--arka-text); font-size: .85rem; line-height: 1.45; }
.send-row { display: grid; grid-template-columns: 1fr auto; gap: .5rem; }
.send-button { width: auto; padding: 0 1.1rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
