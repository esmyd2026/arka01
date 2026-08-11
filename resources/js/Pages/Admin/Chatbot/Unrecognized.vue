<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import { Head, Link, router } from '@inertiajs/vue3';

const props = defineProps({
    messages: { type: Object, required: true },
    onlyPending: { type: Boolean, required: true },
});

const showingPending = ref(props.onlyPending);

function toggleFilter() {
    router.get(route('admin.chatbot.unrecognized.index'), { pending: showingPending.value ? '1' : '0' }, { preserveState: true });
}

function markReviewed(message) {
    router.post(route('admin.chatbot.unrecognized.review', message.id), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Chatbot · Consultas no reconocidas" />

    <AdminLayout title="Chatbot">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="flex items-center gap-4 text-sm">
                    <Link :href="route('admin.chatbot.intents.index')" class="text-arka-text-muted hover:text-arka-text">
                        Intenciones
                    </Link>
                    <Link :href="route('admin.chatbot.settings.edit')" class="text-arka-text-muted hover:text-arka-text">
                        Mensajes generales
                    </Link>
                    <span class="font-medium text-arka-primary-bright">Consultas no reconocidas</span>
                </div>

                <p class="text-sm text-arka-text-muted">
                    Mensajes que el asistente virtual no logró clasificar con suficiente confianza (sección 15 del
                    pedido) — sirven para descubrir vocablos, intenciones o preguntas frecuentes nuevas que hacen
                    falta.
                </p>

                <label class="flex items-center gap-2 text-sm text-arka-text">
                    <input type="checkbox" v-model="showingPending" @change="toggleFilter" class="rounded border-arka-text-muted/30" />
                    Solo pendientes de revisar
                </label>

                <div class="bg-arka-card shadow rounded-arka overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-arka-text-muted/10 text-left text-xs text-arka-text-muted uppercase tracking-wide">
                                    <th class="px-4 sm:px-6 py-3 font-medium">Mensaje</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Fecha</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Rol</th>
                                    <th class="px-4 py-3 font-medium whitespace-nowrap">Confianza</th>
                                    <th class="px-4 sm:px-6 py-3 font-medium text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="message in messages.data" :key="message.id" class="hover:bg-arka-base/40 transition">
                                    <td class="px-4 sm:px-6 py-3 max-w-xs">
                                        <p class="text-arka-text truncate">"{{ message.message }}"</p>
                                        <p v-if="message.user" class="text-xs text-arka-text-muted truncate">{{ message.user.name }}</p>
                                        <p v-else class="text-xs text-arka-text-muted">Sin cuenta ({{ message.phone }})</p>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-arka-text-muted">
                                        {{ new Date(message.created_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-arka-text-muted">
                                        {{ { cliente: 'Cliente', conductor: 'Conductor' }[message.role] ?? 'Sin cuenta' }}
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs text-arka-text-muted">{{ message.confidence }}%</td>
                                    <td class="px-4 sm:px-6 py-3 text-right space-x-2 whitespace-nowrap">
                                        <!-- Pedido explícito del usuario (sección 15): cierra el
                                             ciclo — convertir lo que no se entendió en una
                                             intención nueva, sin transcribir el texto a mano. -->
                                        <Link
                                            :href="route('admin.chatbot.intents.index', { seed_phrase: message.message })"
                                            class="text-xs text-arka-primary hover:text-arka-primary-bright"
                                        >
                                            Crear intención
                                        </Link>
                                        <SecondaryButton v-if="!message.reviewed_at" @click="markReviewed(message)">
                                            Marcar revisada
                                        </SecondaryButton>
                                        <span v-else class="text-xs text-arka-text-muted">Revisada</span>
                                    </td>
                                </tr>
                                <tr v-if="!messages.data.length">
                                    <td colspan="5" class="px-4 sm:px-6 py-8 text-center text-sm text-arka-text-muted">
                                        No hay consultas {{ showingPending ? 'pendientes' : '' }} para mostrar.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-if="messages.prev_page_url || messages.next_page_url" class="flex justify-between">
                    <Link v-if="messages.prev_page_url" :href="messages.prev_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        &larr; Anterior
                    </Link>
                    <span v-else></span>
                    <Link v-if="messages.next_page_url" :href="messages.next_page_url" class="text-sm text-arka-primary hover:text-arka-primary-bright">
                        Siguiente &rarr;
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
