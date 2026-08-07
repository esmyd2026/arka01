<script setup>
import Modal from './Modal.vue';
import PrimaryButton from './PrimaryButton.vue';
import DangerButton from './DangerButton.vue';
import SecondaryButton from './SecondaryButton.vue';
import { confirmDialogState, resolveConfirmDialog } from '@/Utils/confirmDialog';

// Montado una sola vez en la raíz de la app (resources/js/app.js) — cualquier
// pantalla dispara esto llamando a `confirmDialog()`, sin tener que montar un
// modal propio en cada una.
</script>

<template>
    <Modal :show="confirmDialogState.visible" max-width="sm" @close="resolveConfirmDialog(false)">
        <div class="p-6 space-y-4">
            <p class="text-arka-text">{{ confirmDialogState.message }}</p>
            <div class="flex justify-end gap-2">
                <SecondaryButton @click="resolveConfirmDialog(false)">
                    {{ confirmDialogState.cancelLabel }}
                </SecondaryButton>
                <DangerButton v-if="confirmDialogState.danger" @click="resolveConfirmDialog(true)">
                    {{ confirmDialogState.confirmLabel }}
                </DangerButton>
                <PrimaryButton v-else @click="resolveConfirmDialog(true)">
                    {{ confirmDialogState.confirmLabel }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
