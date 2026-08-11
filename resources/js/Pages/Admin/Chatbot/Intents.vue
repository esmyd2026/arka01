<script setup>
import { ref } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import Checkbox from '@/Components/Checkbox.vue';
import SearchableSelect from '@/Components/SearchableSelect.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    intents: { type: Array, required: true },
    seedPhrase: { type: String, default: null },
});

const ROLE_OPTIONS = [
    { value: 'ambos', label: 'Ambos' },
    { value: 'cliente', label: 'Cliente' },
    { value: 'conductor', label: 'Conductor' },
];

// Qué acción segura dispara cada intención (pedido explícito del usuario,
// sección 11: un admin elige ENTRE acciones ya construidas, nunca escribe
// lógica nueva) — de solo lectura acá, sirve de contexto para saber por qué
// algunas no usan el texto de respuesta libre.
const ACTION_LABEL = {
    show_menu: 'Muestra el menú principal',
    resend_code: 'Reenvía el código de verificación',
    escalate_support: 'Abre un ticket de soporte',
    answer_faq: 'Busca en Preguntas frecuentes',
};

const editingId = ref(null);
const form = useForm({
    label: '',
    role_scope: 'ambos',
    is_active: true,
    show_in_menu: true,
    menu_label: '',
    sort_order: 0,
    reply_message: '',
});

// Crear intención nueva (pedido explícito del usuario, sección 15: cerrar
// el ciclo desde "Consultas no reconocidas"). El código es de referencia
// interna (mayúsculas y guiones bajos, ej. "PROBLEMA_VAN") — nunca elige
// una acción del sistema, esas 4 son fijas y no se crean desde acá.
const creating = ref(Boolean(props.seedPhrase));
const createForm = useForm({
    code: '',
    label: props.seedPhrase ? props.seedPhrase.charAt(0).toUpperCase() + props.seedPhrase.slice(1) : '',
    role_scope: 'ambos',
    show_in_menu: false,
    menu_label: '',
    sort_order: 200,
    reply_message: '',
    seed_keyword: props.seedPhrase ?? '',
});

function startCreate() {
    creating.value = true;
    editingId.value = null;
}

function cancelCreate() {
    creating.value = false;
    createForm.reset();
    createForm.clearErrors();
}

function submitCreate() {
    createForm.post(route('admin.chatbot.intents.store'), {
        preserveScroll: true,
        onSuccess: (page) => {
            // El vocablo que originó todo esto (si vino de "Consultas no
            // reconocidas") se agrega solo, para no hacer transcribir la
            // misma frase dos veces.
            const created = page.props.intents.find((i) => i.code === createForm.code);
            if (created && createForm.seed_keyword) {
                router.post(
                    route('admin.chatbot.intents.keywords.store', created.id),
                    { phrase: createForm.seed_keyword, weight: 3 },
                    { preserveScroll: true }
                );
            }
            cancelCreate();
        },
    });
}

function startEdit(intent) {
    editingId.value = intent.id;
    form.clearErrors();
    form.label = intent.label;
    form.role_scope = intent.role_scope;
    form.is_active = intent.is_active;
    form.show_in_menu = intent.show_in_menu;
    form.menu_label = intent.menu_label ?? '';
    form.sort_order = intent.sort_order;
    form.reply_message = intent.reply_message ?? '';
}

function cancel() {
    editingId.value = null;
}

function submit() {
    form.patch(route('admin.chatbot.intents.update', editingId.value), { onSuccess: cancel, preserveScroll: true });
}

// Vocablos (pedido explícito del usuario): cada intención puede expandirse
// para agregar/quitar las frases que la disparan.
const expandedId = ref(null);
const keywordForm = useForm({ phrase: '', weight: 1 });

function toggleExpand(intentId) {
    expandedId.value = expandedId.value === intentId ? null : intentId;
    keywordForm.reset();
    keywordForm.clearErrors();
}

function addKeyword(intentId) {
    keywordForm.post(route('admin.chatbot.intents.keywords.store', intentId), {
        preserveScroll: true,
        onSuccess: () => keywordForm.reset(),
    });
}

async function removeKeyword(keyword) {
    if (!(await confirmDialog(`¿Quitar el vocablo "${keyword.phrase}"?`, { danger: true }))) return;
    router.delete(route('admin.chatbot.intents.keywords.destroy', keyword.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Chatbot · Intenciones" />

    <AdminLayout title="Chatbot">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="flex items-center gap-4 text-sm">
                    <span class="font-medium text-arka-primary-bright">Intenciones</span>
                    <Link :href="route('admin.chatbot.settings.edit')" class="text-arka-text-muted hover:text-arka-text">
                        Mensajes generales
                    </Link>
                    <Link :href="route('admin.chatbot.unrecognized.index')" class="text-arka-text-muted hover:text-arka-text">
                        Consultas no reconocidas
                    </Link>
                </div>

                <p class="text-sm text-arka-text-muted">
                    Qué entiende el asistente virtual de WhatsApp y cómo responde — el orden acá define el orden del
                    menú principal. Desactivar una intención hace que esos mensajes caigan al mensaje de "no entendí".
                </p>

                <div v-if="!creating">
                    <SecondaryButton @click="startCreate">Nueva intención</SecondaryButton>
                </div>

                <!-- Crear intención nueva: siempre solo texto de respuesta, nunca una
                     acción del sistema (esas 4 son fijas, ver ACTION_LABEL). -->
                <form v-if="creating" @submit.prevent="submitCreate" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-sm font-medium text-arka-text">Nueva intención</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <InputLabel value="Código interno (MAYUSCULAS_CON_GUIONES)" />
                            <TextInput class="mt-1 block w-full" v-model="createForm.code" placeholder="EJ_PROBLEMA_VAN" />
                            <InputError class="mt-1" :message="createForm.errors.code" />
                        </div>
                        <div>
                            <InputLabel value="Aplica a" />
                            <SearchableSelect v-model="createForm.role_scope" :options="ROLE_OPTIONS" class="mt-1" />
                        </div>
                    </div>
                    <div>
                        <InputLabel value="Nombre (referencia interna)" />
                        <TextInput class="mt-1 block w-full" v-model="createForm.label" />
                        <InputError class="mt-1" :message="createForm.errors.label" />
                    </div>
                    <div>
                        <InputLabel value="Vocablo inicial (opcional)" />
                        <TextInput class="mt-1 block w-full" v-model="createForm.seed_keyword" placeholder="ej: no me llego el codigo" />
                    </div>
                    <div>
                        <InputLabel value="Respuesta" />
                        <textarea
                            v-model="createForm.reply_message"
                            rows="3"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="createForm.errors.reply_message" />
                    </div>
                    <div class="flex gap-2">
                        <PrimaryButton :disabled="createForm.processing">Crear</PrimaryButton>
                        <SecondaryButton type="button" @click="cancelCreate">Cancelar</SecondaryButton>
                    </div>
                </form>

                <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <div v-for="intent in intents" :key="intent.id" class="p-4 sm:p-6">
                        <div v-if="editingId !== intent.id" class="flex items-start justify-between gap-4">
                            <div class="min-w-0">
                                <p class="text-xs text-arka-text-muted">
                                    {{ { ambos: 'Ambos', cliente: 'Cliente', conductor: 'Conductor' }[intent.role_scope] }}
                                    <span v-if="!intent.is_active" class="text-arka-warning">· inactiva</span>
                                    <span v-if="intent.show_in_menu"> · en el menú{{ intent.menu_label ? ` ("${intent.menu_label}")` : '' }}</span>
                                </p>
                                <p class="text-arka-text font-medium">{{ intent.label }}</p>
                                <p v-if="ACTION_LABEL[intent.action]" class="text-xs text-arka-primary-bright">
                                    ⚙️ {{ ACTION_LABEL[intent.action] }}
                                </p>
                                <p v-else-if="intent.reply_message" class="text-sm text-arka-text-muted line-clamp-2">
                                    {{ intent.reply_message }}
                                </p>
                                <button
                                    type="button"
                                    class="mt-1 text-xs text-arka-primary hover:text-arka-primary-bright"
                                    @click="toggleExpand(intent.id)"
                                >
                                    {{ intent.keywords_count }} vocablo(s) {{ expandedId === intent.id ? '— ocultar' : '— ver/editar' }}
                                </button>
                            </div>
                            <SecondaryButton class="shrink-0" @click="startEdit(intent)">Editar</SecondaryButton>
                        </div>

                        <form v-else @submit.prevent="submit" class="space-y-3">
                            <div>
                                <InputLabel value="Nombre (referencia interna)" />
                                <TextInput class="mt-1 block w-full" v-model="form.label" />
                                <InputError class="mt-1" :message="form.errors.label" />
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Aplica a" />
                                    <SearchableSelect v-model="form.role_scope" :options="ROLE_OPTIONS" class="mt-1" />
                                </div>
                                <div>
                                    <InputLabel value="Orden en el menú" />
                                    <TextInput type="number" min="0" class="mt-1 block w-full" v-model.number="form.sort_order" />
                                </div>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-arka-text">
                                <Checkbox v-model:checked="form.is_active" /> Activa
                            </label>
                            <label class="flex items-center gap-2 text-sm text-arka-text">
                                <Checkbox v-model:checked="form.show_in_menu" /> Mostrar como botón del menú principal
                            </label>
                            <div v-if="form.show_in_menu">
                                <InputLabel value="Texto del botón (ej: 🚗 Quiero ser conductor)" />
                                <TextInput class="mt-1 block w-full" v-model="form.menu_label" />
                            </div>
                            <div v-if="!ACTION_LABEL[intent.action]">
                                <InputLabel value="Respuesta" />
                                <textarea
                                    v-model="form.reply_message"
                                    rows="4"
                                    class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                                ></textarea>
                                <InputError class="mt-1" :message="form.errors.reply_message" />
                            </div>
                            <p v-else class="text-xs text-arka-text-muted">
                                Esta intención responde con una acción fija ({{ ACTION_LABEL[intent.action] }}), no con texto libre.
                            </p>
                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>

                        <!-- Vocablos -->
                        <div v-if="expandedId === intent.id" class="mt-4 pt-4 border-t border-arka-text-muted/10 space-y-3">
                            <ul v-if="intent.keywords.length" class="flex flex-wrap gap-1.5">
                                <li
                                    v-for="keyword in intent.keywords"
                                    :key="keyword.id"
                                    class="flex items-center gap-1.5 px-2 py-1 rounded-full text-xs bg-arka-base text-arka-text-muted"
                                >
                                    {{ keyword.phrase }} <span class="text-arka-text-muted/60">×{{ keyword.weight }}</span>
                                    <button type="button" class="hover:text-arka-danger" @click="removeKeyword(keyword)">✕</button>
                                </li>
                            </ul>
                            <p v-else class="text-xs text-arka-text-muted">Todavía no tiene vocablos.</p>

                            <form @submit.prevent="addKeyword(intent.id)" class="flex items-end gap-2">
                                <div class="flex-1">
                                    <InputLabel value="Vocablo o frase nueva" />
                                    <TextInput class="mt-1 block w-full" v-model="keywordForm.phrase" placeholder="ej: no me llego el codigo" />
                                    <InputError class="mt-1" :message="keywordForm.errors.phrase" />
                                </div>
                                <div class="w-20">
                                    <InputLabel value="Peso" />
                                    <TextInput type="number" min="1" max="10" class="mt-1 block w-full" v-model.number="keywordForm.weight" />
                                </div>
                                <PrimaryButton :disabled="keywordForm.processing">Agregar</PrimaryButton>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
