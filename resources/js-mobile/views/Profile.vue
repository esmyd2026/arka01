<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchCities, updateProfile, updatePassword } from '../services/profile';
import { getStoredUser, fetchCurrentUser, logout, deleteAccount } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const saving = ref(false);
const saved = ref(false);
const showDeleteConfirm = ref(false);
const deletePassword = ref('');
const deleteError = ref(null);
const deleting = ref(false);
const currentPassword = ref('');
const newPassword = ref('');
const passwordConfirmation = ref('');
const passwordSaving = ref(false);
const passwordMessage = ref('');

const name = ref('');
const lastName = ref('');
const birthDate = ref('');
const email = ref('');
const cityId = ref('');
const cities = ref([]);

// Cambiar el teléfono es opcional y dispara re-verificación por WhatsApp
// (ver App\Services\Profile\ProfileUpdater::update()) — se deja aparte del
// resto de los datos, en blanco por defecto, para no reenviar sin querer
// el mismo número y disparar una verificación innecesaria.
const countryCode = ref('+593');
const phoneLocal = ref('');
const countryCodes = ['+593', '+51', '+57', '+58', '+56', '+54'];

const avatarFile = ref(null);
const avatarPreview = ref(null);

const initials = computed(() => (name.value?.[0] || '') + (lastName.value?.[0] || ''));

function onAvatarChange(event) {
    const file = event.target.files?.[0];
    if (!file) return;
    avatarFile.value = file;
    avatarPreview.value = URL.createObjectURL(file);
}

onMounted(async () => {
    user.value = await getStoredUser();
    name.value = user.value?.name || '';
    lastName.value = user.value?.last_name || '';
    email.value = user.value?.email || '';
    birthDate.value = user.value?.birth_date || '';
    cityId.value = user.value?.city_id || '';

    try {
        cities.value = await fetchCities();
    } catch (e) {
        // Sin bloquear el resto del formulario si el catálogo falla.
    } finally {
        loading.value = false;
    }
});

async function save() {
    saving.value = true;
    saved.value = false;
    error.value = null;
    try {
        await updateProfile({
            name: name.value,
            last_name: lastName.value,
            birth_date: birthDate.value,
            email: email.value,
            city_id: cityId.value,
            country_code: phoneLocal.value ? countryCode.value : null,
            phone_local: phoneLocal.value,
        }, avatarFile.value);

        user.value = await fetchCurrentUser();
        avatarFile.value = null;
        avatarPreview.value = null;
        phoneLocal.value = '';
        saved.value = true;
    } catch (e) {
        error.value = e.message || 'No se pudo actualizar el perfil.';
    } finally {
        saving.value = false;
    }
}

async function doLogout() {
    await logout();
    router.replace({ name: 'login' });
}

async function confirmDelete() {
    deleting.value = true;
    deleteError.value = null;
    try {
        const message = await deleteAccount(deletePassword.value);
        if (message) { deleteError.value = message; return; }
        router.replace({ name: 'login' });
    } catch (e) {
        deleteError.value = e.message || 'No se pudo eliminar la cuenta.';
    } finally {
        deleting.value = false;
    }
}

async function savePassword() {
    passwordSaving.value = true; passwordMessage.value = '';
    try {
        await updatePassword({ current_password: currentPassword.value, password: newPassword.value, password_confirmation: passwordConfirmation.value });
        currentPassword.value = ''; newPassword.value = ''; passwordConfirmation.value = '';
        passwordMessage.value = 'Contraseña actualizada correctamente.';
    } catch (e) { passwordMessage.value = e.message; }
    finally { passwordSaving.value = false; }
}

async function shareProfile() {
    const url = user.value?.public_profile_url;
    if (!url) return;
    if (navigator.share) await navigator.share({ title: `Perfil de ${user.value.name}`, text: 'Este es mi perfil en Arka01.', url });
    else { await navigator.clipboard?.writeText(url); passwordMessage.value = 'Enlace del perfil copiado.'; }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page profile-page">
            <section class="welcome">
                <p class="mobile-eyebrow">Cuenta</p>
                <h1 class="mobile-title">Mi perfil</h1>
            </section>

            <section class="identity-card mobile-card">
                <label class="avatar-picker">
                    <img v-if="avatarPreview || user.avatar_url" :src="avatarPreview || user.avatar_url" alt="" class="avatar-image" />
                    <span v-else class="avatar-placeholder">{{ initials || '?' }}</span>
                    <input type="file" accept="image/*" class="avatar-input" @change="onAvatarChange" />
                </label>
                <div class="identity-copy"><span class="role-chip">{{ user.role === 'conductor' ? 'Conductor' : user.role === 'cooperativa' ? 'Cooperativa' : 'Pasajero' }}</span><h2>{{ user.name }} {{ user.last_name }}</h2><p>{{ user.email || user.phone || 'Cuenta Arka01' }}</p><span class="avatar-hint">Toca la foto para cambiarla</span></div>
            </section>

            <section class="action-list mobile-card">
                <button @click="shareProfile"><span>↗</span><div><strong>Compartir mi perfil</strong><small>Envía tu perfil público y reputación</small></div><b>›</b></button>
                <button v-if="user.role === 'conductor'" @click="router.push({ name: 'driver-profile' })"><span>🚘</span><div><strong>Perfil de conductor</strong><small>Vehículo, tarifa y documentos</small></div><b>›</b></button>
                <button v-if="user.role === 'conductor'" @click="router.push({ name: 'driver-clients' })"><span>◎</span><div><strong>Mis clientes</strong><small>Invitaciones y cartera de confianza</small></div><b>›</b></button>
                <button @click="router.push({ name: 'plan' })"><span>♢</span><div><strong>Mi plan</strong><small>Beneficios y límites de tu cuenta</small></div><b>›</b></button>
                <button @click="router.push({ name: 'trusted-contacts' })"><span>♡</span><div><strong>Contactos de confianza</strong><small>Configura las alertas de seguridad</small></div><b>›</b></button>
                <button @click="router.push({ name: 'coupons' })"><span>%</span><div><strong>Cupones y beneficios</strong><small>Revisa tus promociones</small></div><b>›</b></button>
                <button @click="router.push({ name: 'support' })"><span>?</span><div><strong>Ayuda y soporte</strong><small>Escríbenos si necesitas asistencia</small></div><b>›</b></button>
            </section>

            <details class="edit-panel mobile-card">
                <summary><div><p class="mobile-eyebrow">Datos personales</p><strong>Editar mi información</strong></div><span>⌄</span></summary>
            <form class="profile-form" @submit.prevent="save">
                <div class="name-grid">
                    <label class="mobile-field"><span>Nombre</span><input v-model="name" class="mobile-input" required /></label>
                    <label class="mobile-field"><span>Apellido</span><input v-model="lastName" class="mobile-input" /></label>
                </div>
                <label class="mobile-field"><span>Correo electrónico</span><input v-model="email" class="mobile-input" type="email" autocapitalize="none" required /></label>
                <label class="mobile-field"><span>Fecha de nacimiento</span><input v-model="birthDate" class="mobile-input" type="date" /></label>
                <label class="mobile-field">
                    <span>Ciudad</span>
                    <select v-model="cityId" class="mobile-input">
                        <option value="">Sin especificar</option>
                        <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                    </select>
                </label>

                <div class="phone-section">
                    <p class="mobile-eyebrow">Cambiar teléfono (opcional)</p>
                    <p class="phone-hint">Dejar en blanco si no quieres cambiarlo. Un número nuevo pide verificación por WhatsApp.</p>
                    <div class="phone"><select v-model="countryCode" class="mobile-input"><option v-for="code in countryCodes" :key="code" :value="code">{{ code }}</option></select><input v-model="phoneLocal" class="mobile-input" inputmode="numeric" placeholder="Nuevo número" /></div>
                </div>

                <p v-if="error" class="mobile-alert">{{ error }}</p>
                <p v-if="saved" class="saved-copy">Perfil actualizado.</p>

                <button class="mobile-button" type="submit" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar cambios' }}</button>
            </form>
            </details>

            <details class="edit-panel mobile-card">
                <summary><div><p class="mobile-eyebrow">Seguridad</p><strong>Cambiar contraseña</strong></div><span>⌄</span></summary>
                <form class="profile-form" @submit.prevent="savePassword">
                    <label class="mobile-field"><span>Contraseña actual</span><input v-model="currentPassword" class="mobile-input" type="password" autocomplete="current-password" /></label>
                    <label class="mobile-field"><span>Nueva contraseña</span><input v-model="newPassword" class="mobile-input" type="password" autocomplete="new-password" required /></label>
                    <label class="mobile-field"><span>Confirmar nueva contraseña</span><input v-model="passwordConfirmation" class="mobile-input" type="password" autocomplete="new-password" required /></label>
                    <p v-if="passwordMessage" :class="passwordMessage.includes('correctamente') || passwordMessage.includes('copiado') ? 'saved-copy' : 'mobile-alert'">{{ passwordMessage }}</p>
                    <button class="mobile-button" type="submit" :disabled="passwordSaving || !newPassword || newPassword !== passwordConfirmation">{{ passwordSaving ? 'Actualizando…' : 'Actualizar contraseña' }}</button>
                </form>
            </details>

            <section class="session-card mobile-card">
                <button class="logout-button" @click="doLogout">Cerrar sesión</button>
                <button class="delete-link" @click="showDeleteConfirm = true">Eliminar mi cuenta</button>
            </section>
        </main>

        <div v-if="showDeleteConfirm" class="sheet-backdrop" @click.self="showDeleteConfirm = false">
            <section class="delete-sheet mobile-card">
                <span class="sheet-handle"></span><h2>Eliminar cuenta</h2><p>Esta acción es permanente. Escribe tu contraseña para confirmar.</p>
                <label class="mobile-field"><span>Contraseña</span><input v-model="deletePassword" class="mobile-input" type="password" autocomplete="current-password" /></label>
                <p v-if="deleteError" class="mobile-alert">{{ deleteError }}</p>
                <button class="mobile-button delete-button" :disabled="deleting || !deletePassword" @click="confirmDelete">{{ deleting ? 'Eliminando…' : 'Eliminar definitivamente' }}</button>
                <button class="cancel-button" @click="showDeleteConfirm = false">Cancelar</button>
            </section>
        </div>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.profile-page { display: grid; gap: 1.15rem; }
.welcome { padding: 0 .25rem; }
.identity-card { padding: 1.1rem; display: grid; grid-template-columns:auto 1fr; align-items:center; gap: 1rem; }
.avatar-picker { position: relative; width: 5.5rem; height: 5.5rem; border-radius: 999px; overflow: hidden; background: var(--arka-primary-soft); display: grid; place-items: center; cursor: pointer; }
.avatar-image { width: 100%; height: 100%; object-fit: cover; }
.avatar-placeholder { color: var(--arka-primary); font-size: 1.6rem; font-weight: 800; text-transform: uppercase; }
.avatar-input { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.avatar-hint { color: var(--arka-muted); font-size: .72rem; }
.identity-copy{min-width:0}.identity-copy h2{margin:.35rem 0 .2rem;font-size:1.15rem}.identity-copy p{margin:0 0 .4rem;color:var(--arka-muted);font-size:.78rem;overflow:hidden;text-overflow:ellipsis}.role-chip{padding:.2rem .5rem;border-radius:999px;background:var(--arka-primary-soft);color:var(--arka-primary);font-size:.62rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
.action-list{padding:.35rem .9rem}.action-list>button{width:100%;min-height:4.15rem;padding:.65rem 0;display:grid;grid-template-columns:auto 1fr auto;align-items:center;gap:.75rem;border:0;border-bottom:1px solid var(--arka-border);background:transparent;color:var(--arka-text);text-align:left}.action-list>button:last-child{border-bottom:0}.action-list>button>span{width:2.35rem;height:2.35rem;display:grid;place-items:center;border-radius:.75rem;background:var(--arka-primary-soft);color:var(--arka-primary);font-weight:900}.action-list div{display:grid;gap:.12rem}.action-list strong{font-size:.86rem}.action-list small{color:var(--arka-muted);font-size:.68rem}.action-list b{color:var(--arka-primary);font-size:1.2rem}
.edit-panel{overflow:hidden}.edit-panel summary{padding:1rem;display:flex;align-items:center;justify-content:space-between;list-style:none;cursor:pointer}.edit-panel summary div{display:grid;gap:.25rem}.edit-panel summary strong{font-size:.92rem}.profile-form { padding: 0 1.1rem 1.1rem; display: grid; gap: .85rem; }
.name-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
.phone-section { margin-top: .3rem; padding-top: .9rem; border-top: 1px solid rgba(147,173,162,.14); display: grid; gap: .4rem; }
.phone-hint { margin: 0; color: var(--arka-muted); font-size: .74rem; line-height: 1.45; }
.phone { display: grid; grid-template-columns: 6rem 1fr; gap: .5rem; }
.saved-copy { margin: 0; color: var(--arka-primary); font-size: .82rem; font-weight: 700; }
.session-card{padding:.65rem;display:grid;gap:.35rem}.session-card button,.cancel-button{min-height:2.8rem;border:0;border-radius:.75rem;background:transparent;font-weight:750}.logout-button{color:var(--arka-text)}.delete-link{color:var(--arka-danger)}
.sheet-backdrop{position:fixed;z-index:100;inset:0;display:flex;align-items:flex-end;background:rgba(0,0,0,.68)}.delete-sheet{width:100%;max-width:560px;margin:0 auto;padding:.6rem 1rem calc(1rem + env(safe-area-inset-bottom));border-radius:1.5rem 1.5rem 0 0}.sheet-handle{width:2.5rem;height:.25rem;margin:0 auto .9rem;display:block;border-radius:1rem;background:var(--arka-border)}.delete-sheet h2{margin:0}.delete-sheet>p{color:var(--arka-muted);font-size:.82rem;line-height:1.45}.delete-button{margin-top:.8rem;background:var(--arka-danger);color:#fff}.cancel-button{width:100%;color:var(--arka-muted)}
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
