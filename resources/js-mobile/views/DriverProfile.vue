<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchDriverProfile, updateDriverProfile, updateCoverageSectors } from '../services/driverProfile';
import { fetchCities } from '../services/profile';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const saving = ref(false);
const saved = ref(false);

const profile = ref(null);
const vehicleTypes = ref({});
const vehicleAmenities = ref({});

const vehicleMake = ref('');
const vehicleModel = ref('');
const vehicleColor = ref('');
const vehicleType = ref('');
const vehiclePlate = ref('');
const vehicleYear = ref('');
const passengerCapacity = ref('');
const hasTrunk = ref(false);
const amenities = ref([]);

const ratePerKm = ref('');
const minimumFare = ref('');
const maxRequestDistanceKm = ref('');
const acceptsCash = ref(true);
const acceptsTransfer = ref(true);
const hasInsurance = ref(false);
const isPublic = ref(false);
const profilePublic = ref(true);

// "Zona de trabajo" (pedido explícito del usuario: "que los conductores
// puedan indicar la zona de trabajo") — formulario aparte del resto del
// perfil, mismo criterio que la web (Driver/Profile.vue::coverageSectorsForm).
const cities = ref([]);
const coverageSectorIds = ref([]);
const coverageSaving = ref(false);
const coverageSaved = ref(false);
const coverageError = ref(null);

function toggleCoverageSector(sectorId) {
    const index = coverageSectorIds.value.indexOf(sectorId);
    if (index === -1) {
        coverageSectorIds.value.push(sectorId);
    } else {
        coverageSectorIds.value.splice(index, 1);
    }
}

async function saveCoverageSectors() {
    coverageSaving.value = true;
    coverageSaved.value = false;
    coverageError.value = null;
    try {
        const updated = await updateCoverageSectors(coverageSectorIds.value);
        coverageSectorIds.value = updated.coverage_sector_ids || [];
        coverageSaved.value = true;
    } catch (e) {
        coverageError.value = e.message || 'No se pudo actualizar la zona de trabajo.';
    } finally {
        coverageSaving.value = false;
    }
}

const countryCode = ref('+593');
const phoneLocal = ref('');
const countryCodes = ['+593', '+51', '+57', '+58', '+56', '+54'];

const documentFiles = ref({ identity_document: null, license_photo: null, police_record: null });
const documentLabels = { identity_document: 'Cédula o documento de identidad', license_photo: 'Licencia de conducir', police_record: 'Certificado de antecedentes' };

function onDocumentChange(key, event) {
    documentFiles.value[key] = event.target.files?.[0] || null;
}

function fillFromProfile(data) {
    profile.value = data;
    if (!data) return;
    vehicleMake.value = data.vehicle_make || '';
    vehicleModel.value = data.vehicle_model || '';
    vehicleColor.value = data.vehicle_color || '';
    vehicleType.value = data.vehicle_type || '';
    vehiclePlate.value = data.vehicle_plate || '';
    vehicleYear.value = data.vehicle_year || '';
    passengerCapacity.value = data.passenger_capacity || '';
    hasTrunk.value = !!data.has_trunk;
    amenities.value = data.vehicle_amenities || [];
    ratePerKm.value = data.rate_per_km ?? '';
    minimumFare.value = data.minimum_fare ?? '';
    maxRequestDistanceKm.value = data.max_request_distance_km ?? '';
    acceptsCash.value = !!data.accepts_cash;
    acceptsTransfer.value = !!data.accepts_transfer;
    hasInsurance.value = !!data.has_insurance;
    isPublic.value = !!data.is_public;
    profilePublic.value = !!data.profile_public;
}

onMounted(async () => {
    user.value = await getStoredUser();
    if (user.value?.role !== 'conductor') {
        router.replace({ name: 'home' });
        return;
    }

    try {
        const data = await fetchDriverProfile();
        vehicleTypes.value = data.vehicle_types;
        vehicleAmenities.value = data.vehicle_amenities;
        fillFromProfile(data.profile);
        coverageSectorIds.value = data.profile?.coverage_sector_ids || [];
    } catch (e) {
        error.value = e.message || 'No se pudo cargar el perfil de conductor.';
    } finally {
        loading.value = false;
    }

    try {
        cities.value = await fetchCities();
    } catch (e) {
        // El selector de zona es un extra: si el catálogo falla, el resto del perfil sigue funcionando.
    }
});

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = null;
    try {
        const updated = await updateDriverProfile({
            vehicle_make: vehicleMake.value,
            vehicle_model: vehicleModel.value,
            vehicle_color: vehicleColor.value,
            vehicle_type: vehicleType.value,
            vehicle_plate: vehiclePlate.value,
            vehicle_year: vehicleYear.value,
            passenger_capacity: passengerCapacity.value,
            has_trunk: hasTrunk.value,
            vehicle_amenities: amenities.value,
            rate_per_km: ratePerKm.value,
            minimum_fare: minimumFare.value,
            max_request_distance_km: maxRequestDistanceKm.value,
            accepts_cash: acceptsCash.value,
            accepts_transfer: acceptsTransfer.value,
            has_insurance: hasInsurance.value,
            is_public: isPublic.value,
            profile_public: profilePublic.value,
            country_code: phoneLocal.value ? countryCode.value : null,
            phone_local: phoneLocal.value,
        }, documentFiles.value);

        fillFromProfile(updated);
        documentFiles.value = { identity_document: null, license_photo: null, police_record: null };
        phoneLocal.value = '';
        saved.value = true;
    } catch (e) {
        error.value = e.message || 'No se pudo actualizar el perfil de conductor.';
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page driver-profile-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Cuenta</p>
                <h1 class="mobile-title">Perfil de conductor</h1>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>

            <form v-else class="mobile-card driver-form" @submit.prevent="save">
                <p class="mobile-eyebrow">Vehículo</p>
                <p class="section-hint">Estos datos quedan fijos una vez guardados por completo — para corregirlos después, contacta a soporte.</p>
                <div class="name-grid">
                    <label class="mobile-field"><span>Marca</span><input v-model="vehicleMake" class="mobile-input" required /></label>
                    <label class="mobile-field"><span>Modelo</span><input v-model="vehicleModel" class="mobile-input" required /></label>
                </div>
                <div class="name-grid">
                    <label class="mobile-field"><span>Color</span><input v-model="vehicleColor" class="mobile-input" required /></label>
                    <label class="mobile-field"><span>Año</span><input v-model="vehicleYear" class="mobile-input" type="number" required /></label>
                </div>
                <label class="mobile-field">
                    <span>Tipo de vehículo</span>
                    <select v-model="vehicleType" class="mobile-input" required>
                        <option value="" disabled>Selecciona uno</option>
                        <option v-for="(label, key) in vehicleTypes" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>
                <label class="mobile-field"><span>Placa</span><input v-model="vehiclePlate" class="mobile-input" required /></label>
                <div class="name-grid">
                    <label class="mobile-field"><span>Capacidad de pasajeros</span><input v-model="passengerCapacity" class="mobile-input" type="number" min="1" max="8" required /></label>
                    <label class="checkbox-field"><input v-model="hasTrunk" type="checkbox" /><span>Tiene cajuela</span></label>
                </div>

                <p class="mobile-eyebrow section-spacer">Comodidades declaradas</p>
                <div class="amenities-grid">
                    <label v-for="(info, key) in vehicleAmenities" :key="key" class="checkbox-field">
                        <input v-model="amenities" type="checkbox" :value="key" />
                        <span>{{ info.label }}</span>
                    </label>
                </div>

                <p class="mobile-eyebrow section-spacer">Tarifas y cobertura</p>
                <div class="name-grid">
                    <label class="mobile-field"><span>Tarifa por km ($)</span><input v-model="ratePerKm" class="mobile-input" type="number" step="0.01" min="0" required /></label>
                    <label class="mobile-field"><span>Tarifa mínima ($)</span><input v-model="minimumFare" class="mobile-input" type="number" step="0.01" min="0" /></label>
                </div>
                <label class="mobile-field"><span>Distancia máxima de solicitudes (km, opcional)</span><input v-model="maxRequestDistanceKm" class="mobile-input" type="number" min="1" max="500" /></label>

                <p v-if="cities.length" class="section-hint">Zona de trabajo (opcional): marca los sectores donde sí aceptas carreras. Los clientes podrán encontrarte en el directorio filtrando por sector.</p>
                <div v-if="cities.length" class="coverage-sectors">
                    <div v-for="city in cities" :key="city.id" class="coverage-city">
                        <strong>{{ city.name }}</strong>
                        <div class="coverage-pills">
                            <label v-for="sector in city.sectors" :key="sector.id" class="coverage-pill" :class="{ 'is-checked': coverageSectorIds.includes(sector.id) }">
                                <input type="checkbox" :checked="coverageSectorIds.includes(sector.id)" @change="toggleCoverageSector(sector.id)" />
                                <span>{{ sector.name }}</span>
                            </label>
                        </div>
                    </div>
                    <p v-if="coverageError" class="mobile-alert">{{ coverageError }}</p>
                    <p v-if="coverageSaved" class="saved-copy">Zona de trabajo actualizada.</p>
                    <button type="button" class="mobile-button mobile-button--secondary" :disabled="coverageSaving" @click="saveCoverageSectors">
                        {{ coverageSaving ? 'Guardando…' : 'Guardar zona de trabajo' }}
                    </button>
                </div>

                <label class="checkbox-field"><input v-model="acceptsCash" type="checkbox" /><span>Acepta efectivo</span></label>
                <label class="checkbox-field"><input v-model="acceptsTransfer" type="checkbox" /><span>Acepta transferencia</span></label>
                <label class="checkbox-field"><input v-model="hasInsurance" type="checkbox" /><span>Cuenta con seguro (representante, pasajeros y vehículo)</span></label>

                <p class="mobile-eyebrow section-spacer">Visibilidad</p>
                <label class="checkbox-field"><input v-model="isPublic" type="checkbox" /><span>Aparecer en el directorio público (según tu plan)</span></label>
                <label class="checkbox-field"><input v-model="profilePublic" type="checkbox" /><span>Perfil público visible a cualquiera con el enlace</span></label>

                <p class="mobile-eyebrow section-spacer">Documentos de verificación</p>
                <div v-for="(label, key) in documentLabels" :key="key" class="document-row">
                    <div class="document-info">
                        <strong>{{ label }}</strong>
                        <small v-if="profile?.[`has_${key}`]">Ya subido</small>
                        <small v-else>Sin subir</small>
                    </div>
                    <label class="upload-button">
                        {{ documentFiles[key] ? 'Archivo listo' : 'Subir' }}
                        <input type="file" accept="image/*,.pdf" class="upload-input" @change="onDocumentChange(key, $event)" />
                    </label>
                </div>

                <p class="mobile-eyebrow section-spacer">Cambiar teléfono (opcional)</p>
                <div class="phone"><select v-model="countryCode" class="mobile-input"><option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option></select><input v-model="phoneLocal" class="mobile-input" inputmode="numeric" placeholder="Nuevo número" /></div>

                <p v-if="error" class="mobile-alert">{{ error }}</p>
                <p v-if="saved" class="saved-copy">Perfil de conductor actualizado.</p>

                <button class="mobile-button" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar cambios' }}</button>
            </form>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.driver-profile-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.driver-form { padding: 1.1rem; display: grid; gap: .8rem; }
.section-hint { margin: -.3rem 0 .2rem; color: var(--arka-muted); font-size: .74rem; line-height: 1.45; }
.section-spacer { margin-top: .6rem; padding-top: .8rem; border-top: 1px solid rgba(147,173,162,.14); }
.name-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; align-items: end; }
.checkbox-field { display: flex; align-items: center; gap: .55rem; min-height: 2.4rem; font-size: .85rem; color: var(--arka-text); }
.checkbox-field input { width: 1.15rem; height: 1.15rem; accent-color: var(--arka-primary); }
.amenities-grid { display: grid; gap: .3rem; }
.coverage-sectors { display: grid; gap: .7rem; }
.coverage-city { display: grid; gap: .4rem; }
.coverage-city strong { font-size: .78rem; color: var(--arka-muted); }
.coverage-pills { display: flex; flex-wrap: wrap; gap: .4rem; }
.coverage-pill { display: inline-flex; align-items: center; gap: .35rem; padding: .4rem .7rem; border-radius: 999px; border: 1px solid rgba(147,173,162,.3); font-size: .76rem; color: var(--arka-text); }
.coverage-pill input { width: .9rem; height: .9rem; accent-color: var(--arka-primary); }
.coverage-pill.is-checked { border-color: var(--arka-primary); background: var(--arka-primary-soft); color: var(--arka-primary); font-weight: 700; }
.phone { display: grid; grid-template-columns: 6rem 1fr; gap: .5rem; }
.document-row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid rgba(147,173,162,.1); }
.document-info { display: grid; gap: .1rem; }
.document-info strong { font-size: .85rem; }
.document-info small { color: var(--arka-muted); font-size: .7rem; }
.upload-button { position: relative; flex: none; min-height: 2.3rem; padding: 0 .8rem; display: grid; place-items: center; border: 1px solid var(--arka-primary); border-radius: .7rem; color: var(--arka-primary); font-size: .76rem; font-weight: 700; }
.upload-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.saved-copy { margin: 0; color: var(--arka-primary); font-size: .82rem; font-weight: 700; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
