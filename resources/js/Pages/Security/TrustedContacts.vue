<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

defineProps({
    contacts: { type: Array, required: true },
});

const form = useForm({
    name: '',
    phone: '',
    email: '',
    relationship_label: '',
});

function submit() {
    form.post(route('trusted-contacts.store'), { onSuccess: () => form.reset() });
}

async function destroy(id) {
    if (!(await confirmDialog('¿Quitar este contacto de confianza?', { danger: true }))) return;
    router.delete(route('trusted-contacts.destroy', id));
}
</script>

<template>
    <Head title="Contactos de confianza" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Contactos de confianza</h2>
        </template>

        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    A quién avisar si algo sale mal durante un viaje (sección 8): el botón SOS les manda un correo con
                    su ubicación y los datos del conductor/vehículo, y puede compartirles el seguimiento en vivo de
                    cualquier carrera en curso.
                </p>

                <ul v-if="contacts.length" class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="c in contacts" :key="c.id" class="p-4 sm:p-6 flex items-center justify-between gap-4">
                        <div>
                            <p class="text-arka-text font-medium">
                                {{ c.name }}
                                <span v-if="c.relationship_label" class="text-xs text-arka-text-muted">({{ c.relationship_label }})</span>
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                <span v-if="c.phone">{{ c.phone }}</span>
                                <span v-if="c.phone && c.email"> · </span>
                                <span v-if="c.email">{{ c.email }}</span>
                            </p>
                        </div>
                        <DangerButton @click="destroy(c.id)">Quitar</DangerButton>
                    </li>
                </ul>
                <p v-else class="text-sm text-arka-text-muted">Todavía no agregó ningún contacto de confianza.</p>

                <form @submit.prevent="submit" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <h3 class="text-lg font-medium text-arka-text">Agregar contacto</h3>

                    <div>
                        <InputLabel value="Nombre" />
                        <TextInput class="mt-1 block w-full" v-model="form.name" required />
                        <InputError class="mt-1" :message="form.errors.name" />
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <InputLabel value="Teléfono" />
                            <TextInput class="mt-1 block w-full" v-model="form.phone" placeholder="09..." />
                        </div>
                        <div>
                            <InputLabel value="Correo (para la alerta SOS)" />
                            <TextInput type="email" class="mt-1 block w-full" v-model="form.email" />
                        </div>
                    </div>
                    <InputError :message="form.errors.phone" />

                    <div>
                        <InputLabel value="Relación (opcional)" />
                        <TextInput class="mt-1 block w-full" v-model="form.relationship_label" placeholder="Ej: Mamá, pareja..." />
                    </div>

                    <PrimaryButton :disabled="form.processing">Agregar</PrimaryButton>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
