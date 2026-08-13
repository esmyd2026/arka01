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
    peak_surcharge_percent: props.settings.peak_surcharge_percent,
    peak_morning_starts_at: props.settings.peak_morning_starts_at,
    peak_morning_ends_at: props.settings.peak_morning_ends_at,
    peak_evening_starts_at: props.settings.peak_evening_starts_at,
    peak_evening_ends_at: props.settings.peak_evening_ends_at,
    minimum_fare: props.settings.minimum_fare,
    average_ticket_price: props.settings.average_ticket_price,
    driver_stale_after_minutes: props.settings.driver_stale_after_minutes,
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
                        dentro del horario nocturno o de hora pico (sección 5) — nunca los dos juntos, solo uno a la
                        vez. Esto se aplica a toda la plataforma — no hace falta tocar código para ajustarlo.
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
                            <InputLabel value="Ticket promedio por carrera (USD)" />
                            <TextInput
                                type="number"
                                step="0.01"
                                min="0"
                                max="1000"
                                class="mt-1 block w-full"
                                v-model="form.average_ticket_price"
                            />
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Junto con las carreras estimadas de cada plan (editable en /admin/planes), arma la
                                proyección de ganancia mensual que ve el conductor al elegir su plan.
                            </p>
                            <InputError class="mt-1" :message="form.errors.average_ticket_price" />
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

                        <!-- Hora pico (pedido explícito del usuario: "subir un
                             poco las tarifas en las horas pico") — dos franjas,
                             mañana y tarde, mismo criterio que el nocturno de
                             arriba. Nunca se suma con el nocturno. -->
                        <div class="pt-2 border-t border-arka-text-muted/10">
                            <InputLabel value="Recargo de hora pico (%)" />
                            <TextInput
                                type="number"
                                min="0"
                                max="200"
                                class="mt-1 block w-full"
                                v-model="form.peak_surcharge_percent"
                            />
                            <InputError class="mt-1" :message="form.errors.peak_surcharge_percent" />
                        </div>

                        <div>
                            <p class="text-sm text-arka-text-muted mb-2">Franja de la mañana</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Empieza (hora, 0-23)" />
                                    <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.peak_morning_starts_at" />
                                    <InputError class="mt-1" :message="form.errors.peak_morning_starts_at" />
                                </div>
                                <div>
                                    <InputLabel value="Termina (hora, 0-23)" />
                                    <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.peak_morning_ends_at" />
                                    <InputError class="mt-1" :message="form.errors.peak_morning_ends_at" />
                                </div>
                            </div>
                        </div>

                        <div>
                            <p class="text-sm text-arka-text-muted mb-2">Franja de la tarde</p>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <InputLabel value="Empieza (hora, 0-23)" />
                                    <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.peak_evening_starts_at" />
                                    <InputError class="mt-1" :message="form.errors.peak_evening_starts_at" />
                                </div>
                                <div>
                                    <InputLabel value="Termina (hora, 0-23)" />
                                    <TextInput type="number" min="0" max="23" class="mt-1 block w-full" v-model="form.peak_evening_ends_at" />
                                    <InputError class="mt-1" :message="form.errors.peak_evening_ends_at" />
                                </div>
                            </div>
                        </div>
                        <p class="text-xs text-arka-text-muted">
                            Valores de fábrica: 7-9am y 5-7pm, los horarios pico típicos de una ciudad — ajústelos si
                            no calzan con la realidad local.
                        </p>

                        <div class="pt-2 border-t border-arka-text-muted/10">
                            <InputLabel value="Minutos sin ubicación antes de marcar a un conductor desconectado" />
                            <TextInput
                                type="number"
                                min="1"
                                max="60"
                                class="mt-1 block w-full"
                                v-model="form.driver_stale_after_minutes"
                            />
                            <p class="mt-1 text-xs text-arka-text-muted">
                                Un conductor "disponible" sin un ping de ubicación más reciente que esto se muestra
                                desconectado (roster de sus clientes, despacho de carreras) — salvo que siga
                                alcanzable por WhatsApp. El barrido automático que lo desconecta de verdad en la base
                                sigue corriendo cada 2 min sin importar este valor.
                            </p>
                            <InputError class="mt-1" :message="form.errors.driver_stale_after_minutes" />
                        </div>

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
