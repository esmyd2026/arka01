<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { playUpdateChime } from '@/Utils/liveAlert';

const props = defineProps({
    conversations: { type: Object, required: true },
    filters: { type: Object, required: true },
});

const search = ref(props.filters.q ?? '');

function applyFilter() {
    router.get(route('admin.whatsapp-inbox.index'), { q: search.value }, { preserveState: true, replace: true });
}

function formatTime(value) {
    if (!value) return '';
    return new Date(value).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' });
}

// Pedido explícito del usuario ("tener a todos los que me escriben") — la
// lista se refresca sola apenas entra o sale un mensaje de CUALQUIER
// conversación, sin tener que recargar la página a mano.
let channel = null;
onMounted(() => {
    channel = window.Echo.private('admins');
    channel.listen('.whatsapp.message.logged', () => {
        playUpdateChime();
        router.reload({ only: ['conversations'], preserveScroll: true });
    });
});
onBeforeUnmount(() => window.Echo.leave('admins'));
</script>

<template>
    <Head title="Admin · WhatsApp" />

    <AdminLayout title="WhatsApp">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="bg-arka-card shadow rounded-arka p-4 sm:p-6 flex items-center gap-3">
                    <TextInput
                        v-model="search"
                        placeholder="Buscar por nombre o número"
                        class="flex-1"
                        @keyup.enter="applyFilter"
                    />
                    <span class="text-xs text-arka-text-muted shrink-0">{{ conversations.total }} conversación(es)</span>
                </div>

                <p v-if="!conversations.data.length" class="text-sm text-arka-text-muted">
                    Todavía no le escribió nadie por WhatsApp.
                </p>

                <ul v-else class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="conversation in conversations.data" :key="conversation.id">
                        <Link :href="route('admin.whatsapp-inbox.show', conversation.id)" class="p-4 sm:p-6 flex items-center gap-4 hover:bg-arka-base/40">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-arka-text font-medium">{{ conversation.user_name ?? conversation.phone }}</p>
                                    <span v-if="conversation.user_name" class="text-xs text-arka-text-muted">{{ conversation.phone }}</span>
                                    <span v-if="conversation.bot_paused" class="px-2 py-0.5 rounded-full text-xs bg-arka-warning/15 text-arka-warning">
                                        Bot pausado
                                    </span>
                                </div>
                                <p v-if="conversation.last_message_preview" class="text-sm text-arka-text-muted truncate">
                                    <span v-if="conversation.last_message_direction === 'out'" class="opacity-70">Nosotros: </span>
                                    {{ conversation.last_message_preview }}
                                </p>
                            </div>
                            <span class="text-xs text-arka-text-muted shrink-0">{{ formatTime(conversation.last_message_at) }}</span>
                        </Link>
                    </li>
                </ul>

                <div v-if="conversations.prev_page_url || conversations.next_page_url" class="flex justify-between">
                    <Link v-if="conversations.prev_page_url" :href="conversations.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="conversations.next_page_url" :href="conversations.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
