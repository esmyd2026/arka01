<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { fetchTrustedContacts, addTrustedContact, deleteTrustedContact } from '../services/trustedContacts';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);
const contacts = ref([]);

const showForm = ref(false);
const form = ref({ name: '', phone: '', email: '', relationship_label: '' });
const saving = ref(false);
const formError = ref(null);
const deletingId = ref(null);

async function load() {
    loading.value = true;
    try {
        contacts.value = await fetchTrustedContacts();
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar tus contactos.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    await load();
});

function openForm() {
    form.value = { name: '', phone: '', email: '', relationship_label: '' };
    formError.value = null;
    showForm.value = true;
}

async function save() {
    saving.value = true;
    formError.value = null;
    try {
        const contact = await addTrustedContact(form.value);
        contacts.value = [contact, ...contacts.value];
        showForm.value = false;
    } catch (e) {
        formError.value = e.message || 'No se pudo agregar el contacto.';
    } finally {
        saving.value = false;
    }
}

async function remove(contact) {
    deletingId.value = contact.id;
    try {
        await deleteTrustedContact(contact.id);
        contacts.value = contacts.value.filter((c) => c.id !== contact.id);
    } catch (e) {
        error.value = e.message || 'No se pudo eliminar el contacto.';
    } finally {
        deletingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page contacts-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Seguridad</p>
                <h1 class="mobile-title">Contactos de confianza</h1>
                <p class="welcome-copy">A ellos avisamos por correo si activas el botón SOS durante una carrera.</p>
            </section>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>

            <template v-else>
                <p v-if="!contacts.length" class="empty-copy">Todavía no agregas a nadie. Suma al menos uno para que el botón SOS tenga a quién avisar.</p>

                <div v-for="contact in contacts" :key="contact.id" class="contact-row mobile-card">
                    <span class="contact-info">
                        <strong>{{ contact.name }}</strong>
                        <small v-if="contact.relationship_label">{{ contact.relationship_label }}</small>
                        <small>{{ [contact.phone, contact.email].filter(Boolean).join(' · ') }}</small>
                    </span>
                    <button class="remove-button" :disabled="deletingId === contact.id" @click="remove(contact)">
                        {{ deletingId === contact.id ? '…' : 'Quitar' }}
                    </button>
                </div>

                <button class="mobile-button add-button" @click="openForm">+ Agregar contacto</button>
            </template>
        </main>

        <div v-if="showForm" class="sheet-backdrop" @click.self="showForm = false">
            <section class="add-sheet mobile-card">
                <span class="sheet-handle"></span>
                <h2>Nuevo contacto</h2>
                <label class="mobile-field"><span>Nombre</span><input v-model="form.name" class="mobile-input" type="text" /></label>
                <label class="mobile-field"><span>Teléfono</span><input v-model="form.phone" class="mobile-input" type="tel" /></label>
                <label class="mobile-field"><span>Correo</span><input v-model="form.email" class="mobile-input" type="email" /></label>
                <label class="mobile-field"><span>Relación (opcional)</span><input v-model="form.relationship_label" class="mobile-input" type="text" placeholder="Mamá, pareja, hermano…" /></label>
                <p v-if="formError" class="mobile-alert">{{ formError }}</p>
                <button class="mobile-button" :disabled="saving || !form.name" @click="save">{{ saving ? 'Guardando…' : 'Guardar contacto' }}</button>
                <button class="cancel-button" @click="showForm = false">Cancelar</button>
            </section>
        </div>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.contacts-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.contact-row { padding: .9rem 1rem; display: flex; align-items: center; justify-content: space-between; gap: .75rem; }
.contact-info { display: grid; gap: .15rem; min-width: 0; }
.contact-info strong { font-size: .92rem; }
.contact-info small { color: var(--arka-muted); font-size: .75rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.remove-button { flex: none; border: 0; background: transparent; color: var(--arka-danger); font-size: .78rem; font-weight: 700; padding: .4rem .2rem; }
.add-button { margin-top: .2rem; }
.sheet-backdrop { position: fixed; z-index: 100; inset: 0; display: flex; align-items: flex-end; background: rgba(0,0,0,.7); }
.add-sheet { width: 100%; max-width: 560px; margin: 0 auto; padding: .6rem 1rem calc(1rem + env(safe-area-inset-bottom)); border-radius: 1.5rem 1.5rem 0 0; display: grid; gap: .7rem; }
.sheet-handle { width: 2.5rem; height: .25rem; margin: 0 auto .3rem; display: block; border-radius: 1rem; background: rgba(147,173,162,.3); }
.add-sheet h2 { margin: 0; }
.cancel-button { width: 100%; text-align: center; border: 0; background: transparent; color: var(--arka-muted); min-height: 2.5rem; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
