<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
});

const form = useForm({
    night_surcharge_percent: props.settings.night_surcharge_percent,
    night_starts_at: props.settings.night_starts_at,
    night_ends_at: props.settings.night_ends_at,
    minimum_fare: props.settings.minimum_fare,
});

const submit = () => {
    form.patch(route('admin.pricing.update'));
};
</script>

<template>
    <Head title="Admin · Tarifas" />

    <AdminLayout title="Cálculo de precio sugerido">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <p class="text-sm text-arka-text-muted mb-6">
                        Precio sugerido = distancia × tarifa del conductor, más un recargo cuando la carrera se pide
                        dentro del horario nocturno (sección 5). Esto se aplica a toda la plataforma — no hace falta
                        tocar código para ajustarlo.
                    </p>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <InputLabel value="Tarifa base mínima (USD)" />
                            <TextInput
                                type="number"
                                step="0.01"
                                min="0"
                                max="100"
                                class="mt-1 block w-full"
                                v-model="form.minimum_fare"
                            />
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Si distancia × tarifa del conductor da menos que esto, se cobra este mínimo — evita
                                carreras cortas que no le convienen al conductor por los km.
                            </p>
                            <InputError class="mt-1" :message="form.errors.minimum_fare" />
                        </div>

                        <div>
                            <InputLabel value="Recargo nocturno (%)" />
                            <TextInput
                                type="number"
                                min="0"
                                max="200"
                                class="mt-1 block w-full"
                                v-model="form.night_surcharge_percent"
                            />
                            <InputError class="mt-1" :message="form.errors.night_surcharge_percent" />
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <InputLabel value="Empieza (hora, 0-23)" />
                                <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.night_starts_at" />
                                <InputError class="mt-1" :message="form.errors.night_starts_at" />
                            </div>
                            <div>
                                <InputLabel value="Termina (hora, 0-23)" />
                                <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.night_ends_at" />
                                <InputError class="mt-1" :message="form.errors.night_ends_at" />
                            </div>
                        </div>
                        <p class="text-xs text-arka-text-muted">
                            Puede cruzar la medianoche: por ejemplo, empieza 20 y termina 6 cubre de 8pm a 6am.
                        </p>

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-if="form.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                            </Transition>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
