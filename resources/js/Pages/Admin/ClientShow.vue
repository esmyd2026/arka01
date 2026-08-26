<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps({
    client: { type: Object, required: true },
    messages: { type: Array, required: true },
    open_ticket_id: { type: [Number, String], default: null },
});

function formatTime(value) {
    return new Date(value).toLocaleString('es-EC', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Head title="Admin · Conversación de WhatsApp" />

    <AdminLayout title="Conversación de WhatsApp">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka flex items-center gap-4">
                    <UserAvatar :user="client" size-class="h-12 w-12 text-base shrink-0" />
                    <div class="flex-1 min-w-0">
                        <p class="text-arka-text font-medium">{{ client.name }}</p>
                        <p class="text-sm text-arka-text-muted">{{ client.phone ?? client.email }}</p>
                    </div>
                    <Link
                        v-if="open_ticket_id"
                        :href="route('admin.support-tickets.show', open_ticket_id)"
                        class="shrink-0 px-3 py-1.5 rounded-arka text-sm font-medium bg-arka-primary text-arka-base hover:bg-arka-primary-bright"
                    >
                        Ir al ticket abierto
                    </Link>
                </div>

                <!-- Pedido explícito del usuario ("ayudame a ver la trazabilidad
                     en el panel administrativo... como tenemos en los bot que
                     hemos desarrollado mejor") — TODO lo que entró y salió por
                     WhatsApp con este cliente, no solo lo del bot: los avisos
                     de carrera, recordatorios, etc. también quedan acá (ver
                     ChatbotMessage y dónde se registra cada uno). De solo
                     lectura — para responder de verdad hay que hacerlo desde
                     el ticket de soporte, si tiene uno abierto. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div class="space-y-2">
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
                        Todavía no hay conversación por WhatsApp con este cliente.
                    </p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
