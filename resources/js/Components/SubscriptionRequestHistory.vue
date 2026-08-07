<script setup>
// Historial de pedidos de plan — aprobados, rechazados y pendientes
// (consideración agregada al alcance: "módulo de suscripción con el
// detalle de mi plan y el historial de pagos"). Reutilizado por Mi plan de
// conductor y de cliente.
defineProps({
    requestHistory: { type: Array, required: true },
});

const STATUS_LABEL = {
    awaiting_proof: 'Falta el comprobante',
    pending_review: 'Esperando revisión',
    approved: 'Aprobado',
    rejected: 'Rechazado',
};

const STATUS_CLASS = {
    awaiting_proof: 'bg-arka-warning/15 text-arka-warning',
    pending_review: 'bg-arka-primary/15 text-arka-primary-bright',
    approved: 'bg-arka-lime/15 text-arka-lime',
    rejected: 'bg-arka-danger/15 text-arka-danger',
};
</script>

<template>
    <div v-if="requestHistory.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
        <h3 class="text-lg font-medium text-arka-text mb-4">Historial de pagos</h3>
        <ul class="divide-y divide-arka-text-muted/10">
            <li v-for="req in requestHistory" :key="req.id" class="py-3 flex items-center justify-between gap-4">
                <div>
                    <p class="text-arka-text font-medium">{{ req.plan.name }}</p>
                    <p class="text-xs text-arka-text-muted">
                        {{ new Date(req.created_at).toLocaleDateString() }}
                        <span v-if="req.reviewed_by"> · revisado por {{ req.reviewed_by.name }}</span>
                    </p>
                    <p v-if="req.status === 'rejected' && req.admin_note" class="text-xs text-arka-danger mt-1">
                        {{ req.admin_note }}
                    </p>
                </div>
                <span class="px-2.5 py-1 rounded-full text-xs font-medium shrink-0" :class="STATUS_CLASS[req.status]">
                    {{ STATUS_LABEL[req.status] }}
                </span>
            </li>
        </ul>
    </div>
</template>
