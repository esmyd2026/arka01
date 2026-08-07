<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import InputError from '@/Components/InputError.vue';
import { router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

// Pedido de plan en curso (consideración agregada al alcance: "elegir plan +
// subir comprobante, sin pasarela de pago"), reutilizado por Mi plan de
// conductor y de cliente — misma lógica en los dos lados.
const props = defineProps({
    pendingRequest: { type: Object, default: null },
});

const uploadForm = useForm({ payment_proof: null });

function submitProof() {
    uploadForm.post(route('subscription-requests.upload-proof', props.pendingRequest.id), {
        forceFormData: true,
        onSuccess: () => uploadForm.reset(),
    });
}

async function cancelRequest() {
    if (!(await confirmDialog('¿Cancelar este pedido de plan?', { danger: true }))) return;
    router.delete(route('subscription-requests.cancel', props.pendingRequest.id));
}
</script>

<template>
    <div v-if="pendingRequest" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
        <div class="flex items-center justify-between flex-wrap gap-2">
            <h3 class="text-lg font-medium text-arka-text">Pedido de plan: {{ pendingRequest.plan.name }}</h3>
            <span
                class="px-2.5 py-1 rounded-full text-xs font-medium"
                :class="{
                    'bg-arka-warning/15 text-arka-warning': pendingRequest.status === 'awaiting_proof',
                    'bg-arka-primary/15 text-arka-primary-bright': pendingRequest.status === 'pending_review',
                    'bg-arka-danger/15 text-arka-danger': pendingRequest.status === 'rejected',
                }"
            >
                {{
                    {
                        awaiting_proof: 'Falta el comprobante',
                        pending_review: 'Esperando revisión',
                        rejected: 'Comprobante rechazado',
                    }[pendingRequest.status]
                }}
            </span>
        </div>

        <p v-if="pendingRequest.status === 'rejected' && pendingRequest.admin_note" class="text-sm text-arka-danger">
            Motivo: {{ pendingRequest.admin_note }}
        </p>

        <!-- Todavía no subió nada, o se lo rechazaron: puede subir/reintentar. -->
        <form
            v-if="pendingRequest.status === 'awaiting_proof' || pendingRequest.status === 'rejected'"
            @submit.prevent="submitProof"
            class="space-y-2"
        >
            <p class="text-sm text-arka-text-muted">
                Haga la transferencia por ${{ pendingRequest.plan.monthly_price }} y suba una captura del comprobante.
                Un administrador la revisa y activa su plan.
            </p>
            <input
                type="file"
                accept="image/*"
                class="block w-full text-sm text-arka-text-muted"
                @change="uploadForm.payment_proof = $event.target.files[0]"
            />
            <InputError :message="uploadForm.errors.payment_proof" />
            <div class="flex gap-2">
                <PrimaryButton :disabled="uploadForm.processing || !uploadForm.payment_proof">
                    Subir comprobante
                </PrimaryButton>
                <SecondaryButton type="button" @click="cancelRequest">Cancelar pedido</SecondaryButton>
            </div>
        </form>

        <!-- Ya lo subió: solo queda esperar. -->
        <div v-else-if="pendingRequest.status === 'pending_review'" class="space-y-2">
            <img
                v-if="pendingRequest.payment_proof_url"
                :src="pendingRequest.payment_proof_url"
                alt="Comprobante subido"
                class="max-h-48 rounded-arka border border-arka-text-muted/20"
            />
            <SecondaryButton @click="cancelRequest">Cancelar pedido</SecondaryButton>
        </div>
    </div>
</template>
