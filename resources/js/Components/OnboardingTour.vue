<script setup>
import { ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';

// Recorrido guiado por rol, un paso a la vez (pedido explícito del usuario:
// botón "Siguiente" y botón "Saltar guía de uso") — ver Utils/onboardingSteps.js
// para el contenido de cada rol.
const props = defineProps({
    show: { type: Boolean, default: false },
    steps: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const currentStep = ref(0);

// Vuelve a empezar por el paso 1 cada vez que se abre (ej. "Ver guía de
// nuevo" desde Ayuda, después de haberla recorrido entera la vez anterior).
watch(
    () => props.show,
    (show) => {
        if (show) currentStep.value = 0;
    }
);

const isLastStep = () => currentStep.value === props.steps.length - 1;

function next() {
    if (isLastStep()) {
        emit('close');
        return;
    }

    currentStep.value++;
}

function close() {
    emit('close');
}
</script>

<template>
    <Modal :show="show" max-width="sm" @close="close">
        <div class="p-6 space-y-5">
            <div>
                <h3 class="text-lg font-semibold text-arka-text">{{ steps[currentStep].title }}</h3>
                <p class="mt-2 text-sm text-arka-text-muted leading-relaxed">{{ steps[currentStep].description }}</p>
            </div>

            <!-- Indicador de progreso: un puntito por paso, el actual relleno. -->
            <div class="flex items-center justify-center gap-1.5">
                <span
                    v-for="(step, index) in steps"
                    :key="index"
                    class="h-1.5 rounded-full transition-all"
                    :class="index === currentStep ? 'w-5 bg-arka-primary' : 'w-1.5 bg-arka-text-muted/30'"
                />
            </div>

            <div class="flex items-center justify-between">
                <button
                    type="button"
                    class="text-sm text-arka-text-muted hover:text-arka-text transition"
                    @click="close"
                >
                    Saltar guía de uso
                </button>

                <PrimaryButton type="button" @click="next">
                    {{ isLastStep() ? 'Entendido' : 'Siguiente' }}
                </PrimaryButton>
            </div>
        </div>
    </Modal>
</template>
