<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Checkbox from '@/Components/Checkbox.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AddressAutocomplete from '@/Components/AddressAutocomplete.vue';
import FleetMap from '@/Components/FleetMap.vue';
import { computed, ref } from 'vue';

const props = defineProps({
    cooperative: { type: Object, required: true },
    cities: { type: Array, required: true },
    requiredDocuments: { type: Object, required: true },
    planLimits: { type: Object, required: true },
    banks: { type: Array, default: () => [] },
});

const form = useForm({
    name: props.cooperative.name ?? '',
    legal_name: props.cooperative.legal_name ?? '',
    ruc: props.cooperative.ruc ?? '',
    main_address: props.cooperative.main_address ?? '',
    stand_lat: props.cooperative.stand_lat ?? '',
    stand_lng: props.cooperative.stand_lng ?? '',
    city_id: props.cooperative.city_id ?? '',
    province: props.cooperative.province ?? '',
    phone: props.cooperative.phone ?? '',
    email: props.cooperative.email ?? '',
    legal_representative: props.cooperative.legal_representative ?? '',
    declared_driver_count: props.cooperative.declared_driver_count ?? 0,
    declared_unit_count: props.cooperative.declared_unit_count ?? 0,
    has_insurance: props.cooperative.has_insurance ?? false,
    // Pedido explícito del usuario ("mejoremos la privacidad de las
    // cooperativas"): empieza en true igual que en el backend — el perfil
    // público ya mostraba la flota completa de siempre, esto es para poder
    // apagarlo, no algo que arranque oculto.
    show_fleet_publicly: props.cooperative.show_fleet_publicly ?? true,
    geographic_coverage: props.cooperative.geographic_coverage ?? '',
    // Tarifa y reparto con conductores (pedido explícito del usuario): en
    // blanco = todavía usa el promedio de tarifas de sus conductores, mismo
    // criterio que ya sigue el resto de esta pantalla con campos opcionales.
    rate_per_km: props.cooperative.rate_per_km ?? '',
    driver_pay_rate_per_km: props.cooperative.driver_pay_rate_per_km ?? '',
    max_request_distance_km: props.cooperative.max_request_distance_km ?? '',
    operating_hours: props.cooperative.operating_hours ?? '',
    response_timeout_seconds: props.cooperative.response_timeout_seconds ?? 30,
    automatic_assignment_enabled: props.cooperative.automatic_assignment_enabled ?? true,
    manual_assignment_timeout_seconds: 30,
    logo: null,
    ruc_document: null,
    legal_appointment_document: null,
    operating_authorization_document: null,
    operating_permit_document: null,
    other_documents: [],
});
const logoUploading = ref(false);
const logoError = ref('');
const bankForm = useForm({
    account_holder_name: props.cooperative.legal_name || props.cooperative.name || '',
    identity_number: props.cooperative.ruc || '',
    bank_name: '',
    account_type: 'ahorros',
    account_number: '',
    is_favorite: false,
});
const baseMarkers = computed(() => form.stand_lat !== '' && form.stand_lng !== '' ? [{
    id: 'cooperative-base', type: 'base', lat: Number(form.stand_lat), lng: Number(form.stand_lng), label: `Base · ${form.name || 'Cooperativa'}`, color: '#f59e0b',
}] : []);
const baseCenter = computed(() => baseMarkers.value[0] ?? { lat: -2.1709, lng: -79.9224 });

const documentByType = (type) => props.cooperative.documents?.find((document) => document.type === type);

const statusLabel = {
    pending: 'Pendiente de validación',
    in_review: 'En revisión',
    approved: 'Cooperativa verificada',
    rejected: 'Rechazada',
    suspended: 'Suspendida',
}[props.cooperative.status] ?? 'Pendiente';

function submit(sendForReview = false) {
    form.post(route('cooperative.profile.update'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('logo', 'ruc_document', 'legal_appointment_document', 'operating_authorization_document', 'operating_permit_document', 'other_documents');
            if (sendForReview) router.post(route('cooperative.profile.submit-review'), {}, {
                preserveScroll: true,
                onError: (errors) => Object.entries(errors).forEach(([field, message]) => form.setError(field, message)),
            });
        },
    });
}

function submitBankAccount() {
    bankForm.post(route('cooperative.bank-accounts.store'), {
        preserveScroll: true,
        onSuccess: () => bankForm.reset('bank_name', 'account_type', 'account_number', 'is_favorite'),
    });
}

function markBankAccountFavorite(account) {
    router.patch(route('cooperative.bank-accounts.favorite', account.id), {}, { preserveScroll: true });
}

function deleteBankAccount(account) {
    router.delete(route('cooperative.bank-accounts.destroy', account.id), { preserveScroll: true });
}

function selectStand(place) {
    form.main_address = place.address ?? place.display_name ?? place.label ?? form.main_address;
    form.stand_lat = place.lat;
    form.stand_lng = place.lng;
}

function selectStandOnMap(point) {
    form.stand_lat = Number(point.lat).toFixed(7);
    form.stand_lng = Number(point.lng).toFixed(7);
}

function uploadLogo(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    logoUploading.value = true;
    logoError.value = '';
    router.post(route('cooperative.profile.logo.update'), { logo: file }, {
        forceFormData: true, preserveScroll: true,
        onError: (errors) => { logoError.value = errors.logo ?? 'No se pudo guardar el logo.'; },
        onFinish: () => { logoUploading.value = false; event.target.value = ''; },
    });
}
</script>

<template>
    <Head title="Perfil de cooperativa" />

    <AuthenticatedLayout>
        <template #header>
            <div>
                <p class="text-xs uppercase tracking-[0.2em] text-arka-primary">Cooperativa de transporte</p>
                <h2 class="mt-1 text-xl font-semibold text-arka-text">Registro y validación</h2>
            </div>
        </template>

        <div class="py-8 sm:py-12">
            <form class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6" @submit.prevent="submit(false)">
                <section class="overflow-hidden rounded-arka border border-arka-text-muted/10 bg-arka-card shadow-xl">
                    <div class="bg-gradient-to-r from-arka-primary/20 to-arka-lime/10 p-5 sm:p-7">
                        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="flex items-center gap-4">
                                <img
                                    v-if="cooperative.logo_url"
                                    :src="cooperative.logo_url"
                                    alt="Logo de la cooperativa"
                                    class="h-16 w-16 rounded-2xl border border-white/10 bg-white object-contain p-1"
                                />
                                <div v-else class="grid h-16 w-16 place-items-center rounded-2xl bg-arka-primary/15 text-2xl font-bold text-arka-primary">
                                    {{ (form.name || 'C').charAt(0).toUpperCase() }}
                                </div>
                                <div>
                                    <h1 class="text-xl font-semibold text-arka-text">{{ form.name || 'Complete el nombre de la cooperativa' }}</h1>
                                    <p class="mt-1 text-sm text-arka-text-muted">
                                        Plan {{ planLimits.plan_name }} · {{ planLimits.max_units ?? 'sin límite' }} unidades ·
                                        <Link :href="route('cooperative.plan.edit')" class="text-arka-primary hover:text-arka-primary-bright">Ver planes</Link>
                                    </p>
                                </div>
                            </div>
                            <span
                                class="w-fit rounded-full px-3 py-1 text-xs font-semibold"
                                :class="cooperative.status === 'approved' ? 'bg-arka-primary/15 text-arka-primary-bright' : cooperative.status === 'rejected' || cooperative.status === 'suspended' ? 'bg-arka-danger/15 text-arka-danger' : 'bg-arka-warning/15 text-arka-warning'"
                            >
                                {{ statusLabel }}
                            </span>
                        </div>
                    </div>

                    <div v-if="cooperative.rejection_reason" class="m-5 rounded-arka border border-arka-danger/30 bg-arka-danger/10 p-4 text-sm text-arka-danger">
                        <strong>Observación de administración:</strong> {{ cooperative.rejection_reason }}
                    </div>

                    <div class="grid gap-5 p-5 sm:grid-cols-2 sm:p-7">
                        <div>
                            <InputLabel for="name" value="Nombre comercial" />
                            <TextInput id="name" v-model="form.name" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.name" />
                        </div>
                        <div>
                            <InputLabel for="legal_name" value="Razón social" />
                            <TextInput id="legal_name" v-model="form.legal_name" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.legal_name" />
                        </div>
                        <div>
                            <InputLabel for="ruc" value="RUC (13 dígitos)" />
                            <TextInput id="ruc" v-model="form.ruc" maxlength="13" inputmode="numeric" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.ruc" />
                        </div>
                        <div>
                            <InputLabel for="legal_representative" value="Representante legal" />
                            <TextInput id="legal_representative" v-model="form.legal_representative" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.legal_representative" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel for="main_address" value="Dirección principal" />
                            <AddressAutocomplete id="main_address" v-model="form.main_address" class="mt-1 block w-full" @place-selected="selectStand" />
                            <p class="mt-1 text-xs text-arka-text-muted">Seleccione la parada en las sugerencias; se usará para calcular cercanía.</p>
                            <InputError class="mt-1" :message="form.errors.main_address || form.errors.stand_lat || form.errors.stand_lng" />
                            <div class="mt-3 overflow-hidden rounded-arka border border-arka-text-muted/15">
                                <FleetMap :markers="baseMarkers" :center="baseCenter" :zoom="15" :dark="false" :minimal-style="true" :clickable="true" height="280px" @map-click="selectStandOnMap" />
                            </div>
                            <p class="mt-2 text-xs text-arka-text-muted">La marca amarilla identifica la base. También puede tocar el mapa para ajustar el punto exacto.</p>
                        </div>
                        <div>
                            <InputLabel for="city_id" value="Ciudad" />
                            <select id="city_id" v-model="form.city_id" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                <option value="">Seleccione</option>
                                <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                            </select>
                            <InputError class="mt-1" :message="form.errors.city_id" />
                        </div>
                        <div>
                            <InputLabel for="province" value="Provincia" />
                            <TextInput id="province" v-model="form.province" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.province" />
                        </div>
                        <div>
                            <InputLabel for="phone" value="Teléfono institucional" />
                            <TextInput id="phone" v-model="form.phone" type="tel" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.phone" />
                        </div>
                        <div>
                            <InputLabel for="email" value="Correo institucional" />
                            <TextInput id="email" v-model="form.email" type="email" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.email" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel for="logo" value="Logo" />
                            <input id="logo" type="file" accept="image/*" :disabled="logoUploading" class="mt-2 block w-full text-sm text-arka-text-muted file:mr-3 file:rounded-full file:border-0 file:bg-arka-primary/15 file:px-4 file:py-2 file:text-arka-primary-bright disabled:opacity-50" @change="uploadLogo" />
                            <p class="mt-1 text-xs text-arka-text-muted">{{ logoUploading ? 'Guardando logo…' : 'El logo se guarda inmediatamente al seleccionarlo.' }}</p>
                            <InputError class="mt-1" :message="logoError || form.errors.logo" />
                        </div>
                    </div>
                </section>

                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-xl sm:p-7">
                    <h3 class="text-lg font-semibold text-arka-text">Información operativa</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">Estos datos ayudan a clientes y administración a entender su capacidad real.</p>
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div>
                            <InputLabel value="Conductores afiliados declarados" />
                            <TextInput v-model="form.declared_driver_count" type="number" min="0" class="mt-1 block w-full" />
                        </div>
                        <div>
                            <InputLabel value="Unidades declaradas" />
                            <TextInput v-model="form.declared_unit_count" type="number" min="0" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.declared_unit_count" />
                        </div>
                        <div class="sm:col-span-2">
                            <InputLabel value="Cobertura geográfica" />
                            <textarea v-model="form.geographic_coverage" rows="3" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary" placeholder="Ciudades, cantones o zonas donde opera" />
                            <InputError class="mt-1" :message="form.errors.geographic_coverage" />
                        </div>
                        <div>
                            <InputLabel value="Horario de operación" />
                            <textarea v-model="form.operating_hours" rows="3" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary" placeholder="Ej. lunes a domingo, 24 horas" />
                        </div>
                        <div>
                            <InputLabel value="Tiempo para responder una asignación" />
                            <select v-model="form.response_timeout_seconds" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                <option :value="15">15 segundos</option>
                                <option :value="30">30 segundos</option>
                                <option :value="60">60 segundos</option>
                            </select>
                        </div>
                    </div>
                </section>

                <!-- Tarifa y reparto con conductores (pedido explícito del
                     usuario): la cooperativa cobra su propia tarifa al
                     cliente y define cuánto le paga a sus conductores — la
                     diferencia es su margen. Mientras no configure esto, el
                     precio sigue siendo el promedio de tarifas de sus
                     conductores, como siempre. -->
                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-xl sm:p-7">
                    <h3 class="text-lg font-semibold text-arka-text">Tarifa y reparto con conductores</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">
                        En blanco, el precio sigue siendo el promedio de la tarifa de sus conductores, sin
                        billetera de por medio.
                    </p>

                    <div class="mt-5 grid gap-5 sm:grid-cols-3">
                        <div>
                            <InputLabel value="Tarifa que cobra al cliente (USD/km)" />
                            <TextInput v-model="form.rate_per_km" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                            <InputError class="mt-1" :message="form.errors.rate_per_km" />
                        </div>
                        <div>
                            <InputLabel value="Cuánto le paga a cada conductor (USD/km)" />
                            <TextInput v-model="form.driver_pay_rate_per_km" type="number" step="0.01" min="0" class="mt-1 block w-full" />
                            <p class="mt-1 text-xs text-arka-text-muted">No puede superar la tarifa de arriba.</p>
                            <InputError class="mt-1" :message="form.errors.driver_pay_rate_per_km" />
                        </div>
                        <div>
                            <InputLabel value="Distancia máxima para recibir solicitudes (km)" />
                            <TextInput v-model="form.max_request_distance_km" type="number" step="1" min="1" class="mt-1 block w-full" placeholder="Sin límite" />
                            <p class="mt-1 text-xs text-arka-text-muted">Medida desde su base. En blanco, sin límite.</p>
                            <InputError class="mt-1" :message="form.errors.max_request_distance_km" />
                        </div>
                    </div>

                    <p v-if="form.rate_per_km && form.driver_pay_rate_per_km" class="mt-4 rounded-arka bg-arka-base/50 p-3 text-xs text-arka-text-muted">
                        Ejemplo: una carrera de $10 en efectivo — el conductor se queda los $10, pero le
                        corresponden ${{ (10 * (Number(form.driver_pay_rate_per_km) / Number(form.rate_per_km))).toFixed(2) }},
                        así que le queda debiendo el resto a la cooperativa. Si esa misma carrera fuera por
                        transferencia, es la cooperativa quien le debe esa parte al conductor.
                    </p>
                </section>

                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-xl sm:p-7">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="text-lg font-semibold text-arka-text">Cuentas para recibir transferencias</h3>
                            <p class="mt-1 text-sm text-arka-text-muted">
                                En carreras de cooperativa, el cliente verá estas cuentas y podrá avisar cuando haya realizado el pago.
                            </p>
                        </div>
                        <span class="rounded-full bg-arka-primary/10 px-3 py-1 text-xs font-semibold text-arka-primary">
                            {{ cooperative.bank_accounts?.length ?? 0 }} registrada(s)
                        </span>
                    </div>

                    <div v-if="cooperative.bank_accounts?.length" class="mt-5 grid gap-3 md:grid-cols-2">
                        <article
                            v-for="account in cooperative.bank_accounts"
                            :key="account.id"
                            class="rounded-xl border p-4"
                            :class="account.is_favorite ? 'border-arka-primary/50 bg-arka-primary/5' : 'border-arka-text-muted/15 bg-arka-base/35'"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-semibold text-arka-text">{{ account.bank_name }}</p>
                                    <p class="text-xs text-arka-text-muted">{{ account.account_type === 'ahorros' ? 'Ahorros' : 'Corriente' }} · {{ account.account_number }}</p>
                                    <p class="mt-1 text-xs text-arka-text-muted">{{ account.account_holder_name }} · {{ account.identity_number }}</p>
                                </div>
                                <span v-if="account.is_favorite" class="rounded-full bg-arka-primary/15 px-2 py-1 text-[10px] font-bold uppercase text-arka-primary">Principal</span>
                            </div>
                            <div class="mt-3 flex gap-4 border-t border-arka-text-muted/10 pt-3">
                                <button v-if="!account.is_favorite" type="button" class="text-xs font-semibold text-arka-primary" @click="markBankAccountFavorite(account)">Usar como principal</button>
                                <button type="button" class="text-xs font-semibold text-arka-danger" @click="deleteBankAccount(account)">Eliminar</button>
                            </div>
                        </article>
                    </div>

                    <form class="mt-5 border-t border-arka-text-muted/10 pt-5" @submit.prevent="submitBankAccount">
                        <h4 class="font-semibold text-arka-text">Agregar cuenta</h4>
                        <div class="mt-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <InputLabel value="Titular de la cuenta" />
                                <TextInput v-model="bankForm.account_holder_name" class="mt-1 block w-full" />
                                <InputError class="mt-1" :message="bankForm.errors.account_holder_name" />
                            </div>
                            <div>
                                <InputLabel value="RUC o identificación del titular" />
                                <TextInput v-model="bankForm.identity_number" class="mt-1 block w-full" maxlength="20" />
                                <InputError class="mt-1" :message="bankForm.errors.identity_number" />
                            </div>
                            <div>
                                <InputLabel value="Banco o cooperativa" />
                                <select v-model="bankForm.bank_name" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                    <option value="" disabled>Seleccione una entidad</option>
                                    <option v-for="bank in banks" :key="bank" :value="bank">{{ bank }}</option>
                                </select>
                                <InputError class="mt-1" :message="bankForm.errors.bank_name" />
                            </div>
                            <div>
                                <InputLabel value="Tipo de cuenta" />
                                <select v-model="bankForm.account_type" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-arka-base text-arka-text focus:border-arka-primary focus:ring-arka-primary">
                                    <option value="ahorros">Ahorros</option>
                                    <option value="corriente">Corriente</option>
                                </select>
                                <InputError class="mt-1" :message="bankForm.errors.account_type" />
                            </div>
                            <div>
                                <InputLabel value="Número de cuenta" />
                                <TextInput v-model="bankForm.account_number" class="mt-1 block w-full" maxlength="30" />
                                <InputError class="mt-1" :message="bankForm.errors.account_number" />
                            </div>
                            <label class="flex items-center gap-2 self-end pb-3 text-sm text-arka-text">
                                <Checkbox v-model:checked="bankForm.is_favorite" />
                                Usar como cuenta principal
                            </label>
                        </div>
                        <PrimaryButton class="mt-4" :disabled="bankForm.processing">Agregar cuenta</PrimaryButton>
                    </form>
                </section>

                <!-- Pedido explícito del usuario ("mejoremos la privacidad de
                     las cooperativas... si quieres, pese a que tienen el
                     perfil publico, mostrar su flota de conductores"): el
                     perfil en sí ya es público apenas la cooperativa está
                     aprobada, sin toggle aparte para eso — esto es solo la
                     lista de conductores dentro de ese perfil. -->
                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-xl sm:p-7">
                    <h3 class="text-lg font-semibold text-arka-text">Privacidad</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">Controla qué ve el público en su perfil (cooperativas.show).</p>

                    <div class="mt-4">
                        <label class="flex items-start gap-2">
                            <Checkbox v-model:checked="form.show_fleet_publicly" class="mt-0.5" />
                            <span class="text-sm text-arka-text">Mostrar la lista de conductores en el perfil público</span>
                        </label>
                        <p class="mt-1 text-xs text-arka-text-muted">
                            Si lo apaga, su perfil sigue público pero la sección de conductores solo muestra la
                            cantidad, sin nombres ni fotos — usted y un admin siempre ven la flota completa.
                        </p>
                    </div>
                </section>

                <section class="rounded-arka border border-arka-text-muted/10 bg-arka-card p-5 shadow-xl sm:p-7">
                    <h3 class="text-lg font-semibold text-arka-text">Documentación legal</h3>
                    <p class="mt-1 text-sm text-arka-text-muted">
                        Por ahora no son obligatorios para enviar su cooperativa a revisión — puede completarlos
                        más adelante cuando administración se los pida.
                    </p>

                    <!-- Pedido explícito del usuario ("siento que es mucho, eso lo
                         puedo manejar luego"): RUC, nombramiento del representante,
                         documento habilitante y permiso de funcionamiento ya no son
                         obligatorios para mandar a revisión (ver
                         CooperativeProfileController::submitForReview()) y se
                         ocultan acá para no pesarle el arranque — el backend los
                         sigue aceptando igual si algún día se reactivan. -->
                    <div v-if="false" class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div v-for="(label, type) in requiredDocuments" :key="type" class="rounded-arka border border-arka-text-muted/15 p-4">
                            <InputLabel :value="label" />
                            <p v-if="documentByType(type)" class="mt-1 text-xs text-arka-primary-bright">
                                ✓ {{ documentByType(type).original_name }}
                                <Link :href="route('cooperative.documents.show', documentByType(type).id)" class="ml-1 underline">Descargar</Link>
                            </p>
                            <input
                                type="file"
                                accept="application/pdf"
                                class="mt-3 block w-full text-xs text-arka-text-muted file:mr-2 file:rounded-full file:border-0 file:bg-arka-primary/15 file:px-3 file:py-2 file:text-arka-primary-bright"
                                @change="form[`${type}_document`] = $event.target.files[0]"
                            />
                            <InputError class="mt-1" :message="form.errors[`${type}_document`]" />
                        </div>
                    </div>

                    <!-- Seguro autodeclarado (sin archivo, ya no obligatorio para
                         enviar a revisión) y documentos extra opcionales — estos dos
                         sí siguen visibles, no pesan el arranque como los 4 de
                         arriba. -->
                    <div class="mt-5 grid gap-5 sm:grid-cols-2">
                        <div class="rounded-arka border border-arka-text-muted/15 p-4 sm:col-span-2">
                            <label class="flex items-start gap-2">
                                <Checkbox v-model:checked="form.has_insurance" class="mt-0.5" />
                                <span class="text-sm text-arka-text">Contamos con un seguro vigente que protege al representante/dueño, a los conductores afiliados y a los vehículos</span>
                            </label>
                            <InputError class="mt-1" :message="form.errors.has_insurance" />
                        </div>

                        <div class="rounded-arka border border-dashed border-arka-text-muted/20 p-4 sm:col-span-2">
                            <InputLabel value="Otros documentos solicitados por administración (opcional, máximo 5)" />
                            <input type="file" accept="application/pdf" multiple class="mt-3 block w-full text-xs text-arka-text-muted" @change="form.other_documents = Array.from($event.target.files)" />
                            <InputError class="mt-1" :message="form.errors.other_documents" />
                        </div>
                    </div>
                </section>

                <InputError :message="form.errors.cooperative" />
                <div class="flex flex-col justify-end gap-3 pb-8 sm:flex-row">
                    <button type="submit" class="rounded-full border border-arka-primary px-5 py-2 text-sm font-semibold text-arka-primary disabled:opacity-50" :disabled="form.processing || cooperative.status === 'in_review'">Guardar borrador</button>
                    <PrimaryButton type="button" :disabled="form.processing || cooperative.status === 'in_review'" @click="submit(true)">
                        {{ cooperative.status === 'in_review' ? 'Documentación en revisión' : 'Guardar y enviar a validación' }}
                    </PrimaryButton>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
