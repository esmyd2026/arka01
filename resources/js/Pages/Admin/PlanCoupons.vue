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

const props = defineProps({
    coupons: { type: Array, required: true },
});

const OWNER_TYPE_LABEL = { driver: 'Conductor', client: 'Cliente', cooperative: 'Cooperativa' };

const editingId = ref(null);
const creating = ref(false);

const blankForm = () => ({
    code: '',
    owner_type: 'client',
    discount_percent: 50,
    max_redemptions: '',
    expires_at: '',
    is_active: true,
    label: '',
    referrer_user_id: null,
});

const form = useForm(blankForm());

// Referido del cupón (pedido explícito del usuario: "colocarle un usuario
// para que cuando se registren tenga a ese usuario que le dio el cupon
// como referido") — buscador de cualquier usuario (no solo clientes: el
// que reparte el cupón puede ser conductor o cooperativa también), mismo
// patrón de búsqueda con debounce que ReferFleetModal.vue.
const referrerSearchTerm = ref('');
const referrerResults = ref([]);
const referrerLabel = ref('');
let referrerSearchTimeout = null;

function searchReferrer() {
    clearTimeout(referrerSearchTimeout);
    if (referrerSearchTerm.value.trim().length < 2) {
        referrerResults.value = [];
        return;
    }
    referrerSearchTimeout = setTimeout(async () => {
        const { data } = await window.axios.get(route('admin.plan-coupons.search-referrer'), { params: { q: referrerSearchTerm.value } });
        referrerResults.value = data.users;
    }, 300);
}

function chooseReferrer(user) {
    form.referrer_user_id = user.id;
    referrerLabel.value = `${user.name} (@${user.username ?? 's/u'} · #${user.member_code ?? 's/c'})`;
    referrerResults.value = [];
    referrerSearchTerm.value = '';
}

function clearReferrer() {
    form.referrer_user_id = null;
    referrerLabel.value = '';
}

// Pedido implícito de comodidad: generar un código legible al toque, en vez
// de obligar al admin a inventar uno a mano cada vez — igual puede
// sobreescribirlo si prefiere algo puntual (ej. "BIENVENIDA50").
function suggestCode() {
    const random = Math.random().toString(36).slice(2, 8).toUpperCase();
    form.code = `ARKA-${random}`;
}

function startEdit(coupon) {
    editingId.value = coupon.id;
    creating.value = false;
    form.clearErrors();
    form.code = coupon.code;
    form.owner_type = coupon.owner_type;
    form.discount_percent = coupon.discount_percent;
    form.max_redemptions = coupon.max_redemptions ?? '';
    form.expires_at = coupon.expires_at ? coupon.expires_at.slice(0, 10) : '';
    form.is_active = coupon.is_active;
    form.label = coupon.label ?? '';
    form.referrer_user_id = coupon.referrer_user_id ?? null;
    referrerLabel.value = coupon.referrer ? `${coupon.referrer.name} (@${coupon.referrer.username ?? 's/u'} · #${coupon.referrer.member_code ?? 's/c'})` : '';
}

function startCreate() {
    creating.value = true;
    editingId.value = null;
    form.clearErrors();
    Object.assign(form, blankForm());
    referrerLabel.value = '';
    suggestCode();
}

function cancel() {
    editingId.value = null;
    creating.value = false;
}

function submit() {
    if (editingId.value) {
        form.patch(route('admin.plan-coupons.update', editingId.value), { onSuccess: cancel });
    } else {
        form.post(route('admin.plan-coupons.store'), { onSuccess: cancel });
    }
}

async function destroyCoupon(coupon) {
    if (!(await confirmDialog(`¿Eliminar el cupón "${coupon.code}"? Esto no afecta a quien ya lo usó, solo deja de poder usarse de nuevo.`, { danger: true }))) return;
    router.delete(route('admin.plan-coupons.destroy', coupon.id));
}

function toggleActive(coupon) {
    router.post(route('admin.plan-coupons.toggle', coupon.id), {}, { preserveScroll: true });
}

function formatDate(value) {
    if (!value) return null;
    return new Date(value).toLocaleDateString('es-EC', { day: 'numeric', month: 'short', year: 'numeric' });
}
</script>

<template>
    <Head title="Admin · Cupones de descuento" />

    <AdminLayout title="Cupones de descuento">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Código que un conductor, cliente o cooperativa escribe al elegir un plan en "Mi plan" — descuenta un
                    % del precio de lista (puede cubrir el 100%, y activa el plan directo sin pedir comprobante). Cada
                    cupón aplica a UN solo lado (conductor, cliente o cooperativa) y cada persona solo puede usarlo una vez.
                </p>

                <div class="bg-arka-card shadow rounded-arka">
                    <div class="p-4 sm:p-6 flex items-center justify-between border-b border-arka-text-muted/10">
                        <h3 class="text-lg font-medium text-arka-text">Cupones</h3>
                        <PrimaryButton @click="startCreate">Nuevo cupón</PrimaryButton>
                    </div>

                    <div class="divide-y divide-arka-text-muted/10">
                        <div v-for="coupon in coupons" :key="coupon.id" class="p-4 sm:p-6">
                            <div v-if="editingId !== coupon.id" class="flex items-start justify-between gap-4">
                                <div class="flex-1 min-w-0">
                                    <p class="text-arka-text font-medium font-mono">
                                        {{ coupon.code }}
                                        <span v-if="!coupon.is_active" class="text-xs text-arka-warning font-sans">· inactivo</span>
                                    </p>
                                    <p class="text-sm text-arka-text-muted">
                                        {{ OWNER_TYPE_LABEL[coupon.owner_type] }} — {{ coupon.discount_percent }}% de descuento
                                        <span v-if="coupon.label"> · {{ coupon.label }}</span>
                                    </p>
                                    <p class="text-xs text-arka-text-muted">
                                        {{ coupon.redemptions_count }} uso{{ coupon.redemptions_count === 1 ? '' : 's' }}
                                        <span v-if="coupon.max_redemptions"> de {{ coupon.max_redemptions }} permitidos</span>
                                        <span v-else> · sin límite de usos</span>
                                        <span v-if="coupon.expires_at"> · vence {{ formatDate(coupon.expires_at) }}</span>
                                    </p>
                                    <p v-if="coupon.referrer" class="text-xs text-arka-primary-bright">
                                        Referido: {{ coupon.referrer.name }}
                                    </p>
                                </div>
                                <div class="flex gap-2 shrink-0">
                                    <SecondaryButton @click="toggleActive(coupon)">
                                        {{ coupon.is_active ? 'Desactivar' : 'Activar' }}
                                    </SecondaryButton>
                                    <SecondaryButton @click="startEdit(coupon)">Editar</SecondaryButton>
                                    <DangerButton @click="destroyCoupon(coupon)">Eliminar</DangerButton>
                                </div>
                            </div>

                            <form v-else @submit.prevent="submit" class="space-y-3">
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Código" />
                                        <TextInput class="mt-1 block w-full font-mono uppercase" v-model="form.code" required />
                                        <InputError class="mt-1" :message="form.errors.code" />
                                    </div>
                                    <div>
                                        <InputLabel value="Para" />
                                        <select v-model="form.owner_type" class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text">
                                            <option value="client">Cliente</option>
                                            <option value="driver">Conductor</option>
                                            <option value="cooperative">Cooperativa</option>
                                        </select>
                                        <InputError class="mt-1" :message="form.errors.owner_type" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Descuento (%)" />
                                        <TextInput type="number" min="1" max="100" class="mt-1 block w-full" v-model="form.discount_percent" required />
                                        <InputError class="mt-1" :message="form.errors.discount_percent" />
                                    </div>
                                    <div>
                                        <InputLabel value="Usos máximos (opcional, en blanco = sin límite)" />
                                        <TextInput type="number" min="1" class="mt-1 block w-full" v-model="form.max_redemptions" />
                                        <InputError class="mt-1" :message="form.errors.max_redemptions" />
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <InputLabel value="Vence (opcional)" />
                                        <TextInput type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                        <InputError class="mt-1" :message="form.errors.expires_at" />
                                    </div>
                                    <div>
                                        <InputLabel value="Nota interna (opcional, no la ve el usuario)" />
                                        <TextInput class="mt-1 block w-full" v-model="form.label" />
                                    </div>
                                </div>

                                <!-- Pedido explícito del usuario: quien canjea este cupón queda
                                     como referido de este usuario — opcional, cualquier rol. -->
                                <div>
                                    <InputLabel value="Referido por (opcional)" />
                                    <div v-if="form.referrer_user_id" class="mt-1 flex items-center gap-2">
                                        <span class="text-sm text-arka-text">{{ referrerLabel }}</span>
                                        <SecondaryButton type="button" @click="clearReferrer">Quitar</SecondaryButton>
                                    </div>
                                    <template v-else>
                                        <TextInput
                                            v-model="referrerSearchTerm"
                                            class="mt-1 block w-full"
                                            placeholder="Nombre, usuario o código de socio"
                                            @input="searchReferrer"
                                        />
                                        <ul v-if="referrerResults.length" class="mt-1 max-h-40 divide-y divide-arka-text-muted/10 overflow-y-auto rounded-arka border border-arka-text-muted/10">
                                            <li
                                                v-for="candidate in referrerResults"
                                                :key="candidate.id"
                                                class="cursor-pointer p-2 text-sm text-arka-text hover:bg-arka-base"
                                                @click="chooseReferrer(candidate)"
                                            >
                                                {{ candidate.name }}
                                                <span class="text-arka-text-muted">· @{{ candidate.username ?? 's/u' }} · #{{ candidate.member_code ?? 's/c' }}</span>
                                            </li>
                                        </ul>
                                    </template>
                                    <InputError class="mt-1" :message="form.errors.referrer_user_id" />
                                </div>

                                <label class="flex items-center gap-2 text-sm text-arka-text">
                                    <Checkbox v-model:checked="form.is_active" /> Activo
                                </label>

                                <div class="flex gap-2">
                                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                                    <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                                </div>
                            </form>
                        </div>

                        <form v-if="creating" @submit.prevent="submit" class="p-4 sm:p-6 space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Código" />
                                    <div class="mt-1 flex gap-2">
                                        <TextInput class="block w-full font-mono uppercase" v-model="form.code" required />
                                        <SecondaryButton type="button" @click="suggestCode">Sugerir</SecondaryButton>
                                    </div>
                                    <InputError class="mt-1" :message="form.errors.code" />
                                </div>
                                <div>
                                    <InputLabel value="Para" />
                                    <select v-model="form.owner_type" class="mt-1 block w-full rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text">
                                        <option value="client">Cliente</option>
                                        <option value="driver">Conductor</option>
                                        <option value="cooperative">Cooperativa</option>
                                    </select>
                                    <InputError class="mt-1" :message="form.errors.owner_type" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Descuento (%)" />
                                    <TextInput type="number" min="1" max="100" class="mt-1 block w-full" v-model="form.discount_percent" required />
                                    <InputError class="mt-1" :message="form.errors.discount_percent" />
                                </div>
                                <div>
                                    <InputLabel value="Usos máximos (opcional, en blanco = sin límite)" />
                                    <TextInput type="number" min="1" class="mt-1 block w-full" v-model="form.max_redemptions" />
                                    <InputError class="mt-1" :message="form.errors.max_redemptions" />
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <InputLabel value="Vence (opcional)" />
                                    <TextInput type="date" class="mt-1 block w-full" v-model="form.expires_at" />
                                    <InputError class="mt-1" :message="form.errors.expires_at" />
                                </div>
                                <div>
                                    <InputLabel value="Nota interna (opcional, no la ve el usuario)" />
                                    <TextInput class="mt-1 block w-full" v-model="form.label" />
                                </div>
                            </div>

                            <!-- Pedido explícito del usuario: quien canjea este cupón queda
                                 como referido de este usuario — opcional, cualquier rol. -->
                            <div>
                                <InputLabel value="Referido por (opcional)" />
                                <div v-if="form.referrer_user_id" class="mt-1 flex items-center gap-2">
                                    <span class="text-sm text-arka-text">{{ referrerLabel }}</span>
                                    <SecondaryButton type="button" @click="clearReferrer">Quitar</SecondaryButton>
                                </div>
                                <template v-else>
                                    <TextInput
                                        v-model="referrerSearchTerm"
                                        class="mt-1 block w-full"
                                        placeholder="Nombre, usuario o código de socio"
                                        @input="searchReferrer"
                                    />
                                    <ul v-if="referrerResults.length" class="mt-1 max-h-40 divide-y divide-arka-text-muted/10 overflow-y-auto rounded-arka border border-arka-text-muted/10">
                                        <li
                                            v-for="candidate in referrerResults"
                                            :key="candidate.id"
                                            class="cursor-pointer p-2 text-sm text-arka-text hover:bg-arka-base"
                                            @click="chooseReferrer(candidate)"
                                        >
                                            {{ candidate.name }}
                                            <span class="text-arka-text-muted">· @{{ candidate.username ?? 's/u' }} · #{{ candidate.member_code ?? 's/c' }}</span>
                                        </li>
                                    </ul>
                                </template>
                                <InputError class="mt-1" :message="form.errors.referrer_user_id" />
                            </div>

                            <div class="flex gap-2">
                                <PrimaryButton :disabled="form.processing">Crear cupón</PrimaryButton>
                                <SecondaryButton type="button" @click="cancel">Cancelar</SecondaryButton>
                            </div>
                        </form>

                        <p v-if="!coupons.length && !creating" class="p-4 sm:p-6 text-sm text-arka-text-muted">
                            Todavía no hay ningún cupón.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
