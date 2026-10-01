<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

defineProps({
    countries: { type: Array, required: true },
});

const blankForm = () => ({
    name: '',
    iso_code: '',
    phone_prefix: '',
    currency_code: '',
    currency_symbol: '',
    decimal_digits: 2,
    phone_local_regex: '',
    phone_format_hint: '',
    strips_leading_zero: false,
    geocoding_region_code: '',
});

// Alta de país
const creating = ref(false);
const createForm = useForm(blankForm());

function submitCreate() {
    createForm.post(route('admin.countries.store'), {
        onSuccess: () => {
            creating.value = false;
            createForm.reset();
        },
    });
}

// Edición de país
const editingId = ref(null);
const editForm = useForm({ ...blankForm(), is_active: true, is_default: false });

function startEdit(country) {
    editingId.value = country.id;
    editForm.clearErrors();
    editForm.name = country.name;
    editForm.iso_code = country.iso_code;
    editForm.phone_prefix = country.phone_prefix;
    editForm.currency_code = country.currency_code;
    editForm.currency_symbol = country.currency_symbol;
    editForm.decimal_digits = country.decimal_digits;
    editForm.phone_local_regex = country.phone_local_regex ?? '';
    editForm.phone_format_hint = country.phone_format_hint ?? '';
    editForm.strips_leading_zero = country.strips_leading_zero;
    editForm.geocoding_region_code = country.geocoding_region_code;
    editForm.is_active = country.is_active;
    editForm.is_default = country.is_default;
}

function submitEdit(countryId) {
    editForm.patch(route('admin.countries.update', countryId), {
        onSuccess: () => (editingId.value = null),
    });
}

async function destroyCountry(country) {
    if (! (await confirmDialog(`¿Eliminar "${country.name}"? Solo se puede si no es el país predeterminado y no tiene tarifas configuradas.`, { danger: true }))) return;
    router.delete(route('admin.countries.destroy', country.id));
}
</script>

<template>
    <Head title="Admin · Países" />

    <AdminLayout title="Países">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Cada país activo define su propia moneda, prefijo telefónico y región de búsqueda de direcciones.
                    El país de cada usuario se deduce solo, del prefijo que eligió al registrarse — no hace falta
                    tocar nada más para que un conductor de otro país pueda registrarse correctamente.
                </p>

                <div class="flex justify-end">
                    <PrimaryButton v-if="!creating" @click="creating = true">Nuevo país</PrimaryButton>
                </div>

                <form v-if="creating" @submit.prevent="submitCreate" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Nombre" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.name" required />
                            <InputError class="mt-1" :message="createForm.errors.name" />
                        </div>
                        <div>
                            <InputLabel value="Código ISO (ej: CL)" />
                            <TextInput class="mt-1 block w-full uppercase" v-model="createForm.iso_code" maxlength="2" required />
                            <InputError class="mt-1" :message="createForm.errors.iso_code" />
                        </div>
                        <div>
                            <InputLabel value="Prefijo telefónico (ej: +56)" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.phone_prefix" required />
                            <InputError class="mt-1" :message="createForm.errors.phone_prefix" />
                        </div>
                        <div>
                            <InputLabel value="Región de búsqueda de direcciones (ej: cl)" />
                            <TextInput class="mt-1 block w-full lowercase" v-model="createForm.geocoding_region_code" maxlength="2" required />
                            <InputError class="mt-1" :message="createForm.errors.geocoding_region_code" />
                        </div>
                        <div>
                            <InputLabel value="Código de moneda (ISO 4217, ej: CLP)" />
                            <TextInput class="mt-1 block w-full uppercase" v-model="createForm.currency_code" maxlength="3" required />
                            <InputError class="mt-1" :message="createForm.errors.currency_code" />
                        </div>
                        <div>
                            <InputLabel value="Símbolo de moneda (ej: $)" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.currency_symbol" required />
                            <InputError class="mt-1" :message="createForm.errors.currency_symbol" />
                        </div>
                        <div>
                            <InputLabel value="Decimales de la moneda (USD=2, CLP=0)" />
                            <TextInput type="number" min="0" max="4" class="mt-1 block w-full" v-model="createForm.decimal_digits" required />
                            <InputError class="mt-1" :message="createForm.errors.decimal_digits" />
                        </div>
                        <div>
                            <InputLabel value="Formato del celular local (regex, opcional)" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.phone_local_regex" placeholder="^9\d{8}$" />
                        </div>
                        <div>
                            <InputLabel value="Ayuda de formato (opcional)" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.phone_format_hint" placeholder="9 dígitos, empieza en 9" />
                        </div>
                    </div>
                    <label class="flex items-center gap-2 text-sm text-arka-text">
                        <Checkbox v-model:checked="createForm.strips_leading_zero" /> El celular local antepone un 0 que hay que quitar al normalizar
                    </label>
                    <div class="flex gap-2">
                        <PrimaryButton :disabled="createForm.processing">Crear</PrimaryButton>
                        <SecondaryButton type="button" @click="creating = false">Cancelar</SecondaryButton>
                    </div>
                </form>

                <div v-for="country in countries" :key="country.id" class="bg-arka-card shadow rounded-arka p-4 sm:p-6">
                    <div v-if="editingId !== country.id" class="flex items-start justify-between gap-4">
                        <div>
                            <p class="text-arka-text font-medium">
                                {{ country.name }}
                                <span class="text-xs text-arka-text-muted">({{ country.iso_code }})</span>
                                <span v-if="country.is_default" class="text-xs text-arka-primary">· predeterminado</span>
                                <span v-if="!country.is_active" class="text-xs text-arka-warning">· inactivo</span>
                            </p>
                            <p class="text-sm text-arka-text-muted">
                                {{ country.phone_prefix }} · {{ country.currency_code }} ({{ country.currency_symbol }}, {{ country.decimal_digits }} decimales) · región de búsqueda "{{ country.geocoding_region_code }}"
                            </p>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <SecondaryButton @click="startEdit(country)">Editar</SecondaryButton>
                            <DangerButton @click="destroyCountry(country)">Eliminar</DangerButton>
                        </div>
                    </div>

                    <form v-else @submit.prevent="submitEdit(country.id)" class="space-y-3">
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <InputLabel value="Nombre" />
                                <TextInput class="mt-1 block w-full" v-model="editForm.name" required />
                                <InputError class="mt-1" :message="editForm.errors.name" />
                            </div>
                            <div>
                                <InputLabel value="Código ISO" />
                                <TextInput class="mt-1 block w-full uppercase" v-model="editForm.iso_code" maxlength="2" required />
                                <InputError class="mt-1" :message="editForm.errors.iso_code" />
                            </div>
                            <div>
                                <InputLabel value="Prefijo telefónico" />
                                <TextInput class="mt-1 block w-full" v-model="editForm.phone_prefix" required />
                                <InputError class="mt-1" :message="editForm.errors.phone_prefix" />
                            </div>
                            <div>
                                <InputLabel value="Región de búsqueda de direcciones" />
                                <TextInput class="mt-1 block w-full lowercase" v-model="editForm.geocoding_region_code" maxlength="2" required />
                                <InputError class="mt-1" :message="editForm.errors.geocoding_region_code" />
                            </div>
                            <div>
                                <InputLabel value="Código de moneda" />
                                <TextInput class="mt-1 block w-full uppercase" v-model="editForm.currency_code" maxlength="3" required />
                                <InputError class="mt-1" :message="editForm.errors.currency_code" />
                            </div>
                            <div>
                                <InputLabel value="Símbolo de moneda" />
                                <TextInput class="mt-1 block w-full" v-model="editForm.currency_symbol" required />
                                <InputError class="mt-1" :message="editForm.errors.currency_symbol" />
                            </div>
                            <div>
                                <InputLabel value="Decimales de la moneda" />
                                <TextInput type="number" min="0" max="4" class="mt-1 block w-full" v-model="editForm.decimal_digits" required />
                                <InputError class="mt-1" :message="editForm.errors.decimal_digits" />
                            </div>
                            <div>
                                <InputLabel value="Formato del celular local (regex)" />
                                <TextInput class="mt-1 block w-full" v-model="editForm.phone_local_regex" />
                            </div>
                            <div>
                                <InputLabel value="Ayuda de formato" />
                                <TextInput class="mt-1 block w-full" v-model="editForm.phone_format_hint" />
                            </div>
                        </div>
                        <label class="flex items-center gap-2 text-sm text-arka-text">
                            <Checkbox v-model:checked="editForm.strips_leading_zero" /> El celular local antepone un 0 que hay que quitar al normalizar
                        </label>
                        <label class="flex items-center gap-2 text-sm text-arka-text">
                            <Checkbox v-model:checked="editForm.is_active" /> Activo (disponible para registrarse)
                        </label>
                        <label class="flex items-center gap-2 text-sm text-arka-text">
                            <Checkbox v-model:checked="editForm.is_default" /> País predeterminado del sistema
                        </label>
                        <div class="flex gap-2">
                            <PrimaryButton :disabled="editForm.processing">Guardar</PrimaryButton>
                            <SecondaryButton type="button" @click="editingId = null">Cancelar</SecondaryButton>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
