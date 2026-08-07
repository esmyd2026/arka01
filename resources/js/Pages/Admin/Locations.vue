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
    cities: { type: Array, required: true },
});

// Alta de ciudad
const creatingCity = ref(false);
const cityForm = useForm({ name: '', province: '', lat: '', lng: '' });

function submitCity() {
    cityForm.post(route('admin.cities.store'), {
        onSuccess: () => {
            creatingCity.value = false;
            cityForm.reset();
        },
    });
}

// Edición de ciudad
const editingCityId = ref(null);
const editCityForm = useForm({ name: '', province: '', lat: '', lng: '', is_active: true });

function startEditCity(city) {
    editingCityId.value = city.id;
    editCityForm.clearErrors();
    editCityForm.name = city.name;
    editCityForm.province = city.province ?? '';
    editCityForm.lat = city.lat ?? '';
    editCityForm.lng = city.lng ?? '';
    editCityForm.is_active = city.is_active;
}

function submitEditCity(cityId) {
    editCityForm.patch(route('admin.cities.update', cityId), {
        onSuccess: () => (editingCityId.value = null),
    });
}

async function destroyCity(city) {
    if (! (await confirmDialog(`¿Eliminar "${city.name}"? Solo se puede si ya no tiene sectores.`, { danger: true }))) return;
    router.delete(route('admin.cities.destroy', city.id));
}

// Alta de sector, anclada a una ciudad puntual
const creatingSectorFor = ref(null);
const sectorForm = useForm({ name: '' });

function startCreateSector(cityId) {
    creatingSectorFor.value = cityId;
    sectorForm.reset();
    sectorForm.clearErrors();
}

function submitSector(cityId) {
    sectorForm.post(route('admin.sectors.store', cityId), {
        onSuccess: () => {
            creatingSectorFor.value = null;
            sectorForm.reset();
        },
    });
}

// Edición de sector
const editingSectorId = ref(null);
const editSectorForm = useForm({ name: '', is_active: true });

function startEditSector(sector) {
    editingSectorId.value = sector.id;
    editSectorForm.clearErrors();
    editSectorForm.name = sector.name;
    editSectorForm.is_active = sector.is_active;
}

function submitEditSector(sectorId) {
    editSectorForm.patch(route('admin.sectors.update', sectorId), {
        onSuccess: () => (editingSectorId.value = null),
    });
}

async function destroySector(sector) {
    if (! (await confirmDialog(`¿Eliminar el sector "${sector.name}"?`, { danger: true }))) return;
    router.delete(route('admin.sectors.destroy', sector.id));
}
</script>

<template>
    <Head title="Admin · Zonas" />

    <AdminLayout title="Zonas del Ecuador">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Ciudades y sectores/barrios que el cliente elige al pedir una carrera (además del mapa), para que
                    el conductor entienda de un vistazo dónde está y a dónde va — ej. "Sauces 1" → "Samanes 3".
                </p>

                <div class="flex justify-end">
                    <PrimaryButton v-if="!creatingCity" @click="creatingCity = true">Nueva ciudad</PrimaryButton>
                </div>

                <form v-if="creatingCity" @submit.prevent="submitCity" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Nombre de la ciudad" />
                            <TextInput class="mt-1 block w-full" v-model="cityForm.name" required />
                            <InputError class="mt-1" :message="cityForm.errors.name" />
                        </div>
                        <div>
                            <InputLabel value="Provincia (opcional)" />
                            <TextInput class="mt-1 block w-full" v-model="cityForm.province" />
                        </div>
                        <div>
                            <InputLabel value="Latitud (opcional, para centrar el mapa)" />
                            <TextInput type="number" step="0.0000001" class="mt-1 block w-full" v-model="cityForm.lat" />
                        </div>
                        <div>
                            <InputLabel value="Longitud" />
                            <TextInput type="number" step="0.0000001" class="mt-1 block w-full" v-model="cityForm.lng" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <PrimaryButton :disabled="cityForm.processing">Crear</PrimaryButton>
                        <SecondaryButton type="button" @click="creatingCity = false">Cancelar</SecondaryButton>
                    </div>
                </form>

                <div v-for="city in cities" :key="city.id" class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 border-b border-arka-text-muted/10">
                        <div v-if="editingCityId !== city.id" class="flex items-start justify-between gap-4">
                            <div>
                                <p class="text-arka-text font-medium">
                                    {{ city.name }}
                                    <span v-if="city.province" class="text-xs text-arka-text-muted">({{ city.province }})</span>
                                    <span v-if="!city.is_active" class="text-xs text-arka-warning">· inactiva</span>
                                </p>
                                <p class="text-sm text-arka-text-muted">{{ city.sectors_count }} sector(es)</p>
                            </div>
                            <div class="flex gap-2 shrink-0">
                                <SecondaryButton @click="startEditCity(city)">Editar</SecondaryButton>
                                <DangerButton @click="destroyCity(city)">Eliminar</DangerButton>
                            </div>
                        </div>

                        <form v-else @submit.prevent="submitEditCity(city.id)" class="space-y-3">
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Nombre" />
                                    <TextInput class="mt-1 block w-full" v-model="editCityForm.name" required />
                                    <InputError class="mt-1" :message="editCityForm.errors.name" />
                                </div>
                                <div>
                                    <InputLabel value="Provincia" />
                                    <TextInput class="mt-1 block w-full" v-model="editCityForm.province" />
                                </div>
                                <div>
                                    <InputLabel value="Latitud" />
                                    <TextInput type="number" step="0.0000001" class="mt-1 block w-full" v-model="editCityForm.lat" />
                                </div>
                                <div>
                                    <InputLabel value="Longitud" />
                                    <TextInput type="number" step="0.0000001" class="mt-1 block w-full" v-model="editCityForm.lng" />
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-arka-text">
                                <Checkbox v-model:checked="editCityForm.is_active" /> Activa (visible al pedir carrera)
                            </label>
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="editCityForm.processing">Guardar</PrimaryButton>
                                <SecondaryButton type="button" @click="editingCityId = null">Cancelar</SecondaryButton>
                            </div>
                        </form>
                    </div>

                    <div class="p-4 sm:p-6 space-y-2">
                        <div
                            v-for="sector in city.sectors"
                            :key="sector.id"
                            class="flex items-center justify-between gap-3 py-1"
                        >
                            <div v-if="editingSectorId !== sector.id" class="flex items-center gap-2 text-sm">
                                <span class="text-arka-text">{{ sector.name }}</span>
                                <span v-if="!sector.is_active" class="text-xs text-arka-warning">inactivo</span>
                            </div>
                            <form v-else @submit.prevent="submitEditSector(sector.id)" class="flex items-center gap-2 flex-1">
                                <TextInput class="block w-full" v-model="editSectorForm.name" required />
                                <label class="flex items-center gap-1 text-xs text-arka-text-muted whitespace-nowrap">
                                    <Checkbox v-model:checked="editSectorForm.is_active" /> Activo
                                </label>
                                <PrimaryButton :disabled="editSectorForm.processing">Guardar</PrimaryButton>
                                <SecondaryButton type="button" @click="editingSectorId = null">Cancelar</SecondaryButton>
                            </form>

                            <div v-if="editingSectorId !== sector.id" class="flex gap-2 shrink-0 text-xs">
                                <button type="button" class="text-arka-primary hover:text-arka-primary-bright" @click="startEditSector(sector)">
                                    Editar
                                </button>
                                <button type="button" class="text-arka-danger hover:opacity-80" @click="destroySector(sector)">
                                    Eliminar
                                </button>
                            </div>
                        </div>

                        <p v-if="!city.sectors.length" class="text-sm text-arka-text-muted">Todavía no tiene sectores.</p>

                        <form v-if="creatingSectorFor === city.id" @submit.prevent="submitSector(city.id)" class="flex items-center gap-2 pt-2">
                            <TextInput class="block w-full" v-model="sectorForm.name" placeholder="Ej: Sauces 1" required />
                            <PrimaryButton :disabled="sectorForm.processing">Agregar</PrimaryButton>
                            <SecondaryButton type="button" @click="creatingSectorFor = null">Cancelar</SecondaryButton>
                        </form>
                        <InputError class="mt-1" :message="sectorForm.errors.name" />

                        <div v-if="creatingSectorFor !== city.id" class="pt-2">
                            <SecondaryButton @click="startCreateSector(city.id)">Agregar sector</SecondaryButton>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
