<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import BottomSheet from '@/Components/BottomSheet.vue';
import InputError from '@/Components/InputError.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

const props = defineProps({
    show: { type: Boolean, default: false },
    ride: { type: Object, required: true },
    accounts: { type: Array, default: () => [] },
    recipient: { type: String, default: '' },
    goesToCooperative: { type: Boolean, default: false },
    isDriver: { type: Boolean, default: false },
    paymentProofUrl: { type: String, default: null },
});

defineEmits(['close']);

const proofInput = ref(null);
const proofForm = useForm({ payment_proof: null });
const paymentStatus = computed(() => props.ride.payment_status ?? 'pending');
const paymentStatusMeta = computed(() => ({
    pending: { label: 'Falta comprobante', classes: 'border-arka-warning/30 bg-arka-warning/10 text-arka-warning' },
    proof_submitted: { label: 'Comprobante en revisión', classes: 'border-sky-400/30 bg-sky-400/10 text-sky-300' },
    confirmed: { label: 'Pagada', classes: 'border-arka-primary/30 bg-arka-primary/10 text-arka-primary' },
    rejected: { label: 'Comprobante rechazado', classes: 'border-arka-danger/30 bg-arka-danger/10 text-arka-danger' },
}[paymentStatus.value] ?? { label: 'Pago pendiente', classes: 'border-arka-warning/30 bg-arka-warning/10 text-arka-warning' }));

function selectProof(event) {
    proofForm.payment_proof = event.target.files?.[0] ?? null;
    proofForm.clearErrors();
}

function uploadProof() {
    if (!proofForm.payment_proof) {
        proofForm.setError('payment_proof', 'Seleccione una imagen del comprobante.');
        return;
    }

    proofForm.post(route('rides.payment-proof.store', props.ride.id), {
        forceFormData: true,
        preserveScroll: true,
        only: ['ride', 'paymentProofUrl', 'errors', 'flash'],
        onSuccess: () => {
            proofForm.reset();
            if (proofInput.value) proofInput.value.value = '';
        },
    });
}
</script>

<template>
    <BottomSheet :show="show" @close="$emit('close')">
        <div class="space-y-4 p-4 pb-6">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-[0.16em] text-arka-primary">Pago de la carrera</p>
                <h3 class="mt-1 text-lg font-semibold text-arka-text">Cuenta para transferir</h3>
                <p class="mt-1 text-sm text-arka-text-muted">El pago se envía a {{ recipient }}.</p>
            </div>

            <p v-if="goesToCooperative" class="rounded-xl border border-arka-primary/20 bg-arka-primary/10 px-3 py-2 text-xs leading-relaxed text-arka-text-muted">
                La cooperativa recibe el total y liquida internamente el valor correspondiente al conductor.
            </p>

            <div
                v-for="account in accounts"
                :key="account.id"
                class="rounded-xl border p-4"
                :class="account.is_favorite ? 'border-arka-primary bg-arka-primary/5' : 'border-arka-text-muted/15'"
            >
                <p class="flex items-center gap-1.5 font-semibold text-arka-text">
                    <span v-if="account.is_favorite" class="text-arka-primary" aria-label="Cuenta principal">★</span>
                    {{ account.bank_name }}
                </p>
                <dl class="mt-2 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-arka-text-muted">Titular</dt><dd class="text-right font-medium text-arka-text">{{ account.account_holder_name }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-arka-text-muted">Tipo</dt><dd class="text-right text-arka-text">{{ account.account_type === 'ahorros' ? 'Ahorros' : 'Corriente' }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-arka-text-muted">Número</dt><dd class="text-right font-medium text-arka-text">{{ account.account_number }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-arka-text-muted">Cédula/RUC</dt><dd class="text-right text-arka-text">{{ account.identity_number }}</dd></div>
                </dl>
            </div>

            <div v-if="goesToCooperative && !isDriver && ride.status === 'completed'" class="border-t border-arka-text-muted/10 pt-4">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h4 class="font-semibold text-arka-text">Comprobante</h4>
                        <p class="mt-0.5 text-xs text-arka-text-muted">Se comprime automáticamente antes de guardarse.</p>
                    </div>
                    <span class="shrink-0 rounded-full border px-2.5 py-1 text-[11px] font-semibold" :class="paymentStatusMeta.classes">{{ paymentStatusMeta.label }}</span>
                </div>

                <p v-if="paymentStatus === 'rejected'" class="mt-3 rounded-xl border border-arka-danger/30 bg-arka-danger/10 px-3 py-2 text-sm text-arka-danger">
                    {{ ride.payment_rejection_reason }}
                </p>

                <form v-if="['pending', 'rejected'].includes(paymentStatus)" class="mt-3 space-y-3" @submit.prevent="uploadProof">
                    <label class="block cursor-pointer rounded-xl border border-dashed border-arka-primary/35 bg-arka-primary/5 p-4 text-center transition hover:bg-arka-primary/10">
                        <input ref="proofInput" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" @change="selectProof" />
                        <svg class="mx-auto h-6 w-6 text-arka-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4 4 4M5 14v4a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4"/></svg>
                        <span class="mt-2 block text-sm font-semibold text-arka-text">{{ proofForm.payment_proof?.name || 'Seleccionar comprobante' }}</span>
                        <span class="mt-1 block text-xs text-arka-text-muted">JPG, PNG o WebP · máximo 8 MB</span>
                    </label>
                    <InputError :message="proofForm.errors.payment_proof" />
                    <PrimaryButton class="w-full justify-center" :disabled="proofForm.processing">
                        {{ proofForm.processing ? 'Comprimiendo y enviando…' : 'Enviar comprobante' }}
                    </PrimaryButton>
                </form>

                <div v-else class="mt-3 space-y-2">
                    <a v-if="paymentProofUrl" :href="paymentProofUrl" target="_blank" class="flex w-full items-center justify-center rounded-xl border border-arka-text-muted/20 px-3 py-2.5 text-sm font-semibold text-arka-text hover:border-arka-primary/40">Ver comprobante enviado</a>
                    <p v-if="paymentStatus === 'proof_submitted'" class="text-center text-xs text-arka-text-muted">La cooperativa lo revisará y le avisará al confirmarlo.</p>
                    <p v-if="paymentStatus === 'confirmed'" class="text-center text-xs font-medium text-arka-primary">Pago verificado por la cooperativa.</p>
                </div>
            </div>
        </div>
    </BottomSheet>
</template>
