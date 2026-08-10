<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';

const props = defineProps({
    fleets: { type: Array, required: true },
    // null = sin límite de flotas (plan Multi-flota con cupo personalizado).
    maxFleets: { type: Number, default: null },
    planCode: { type: String, required: true },
    planName: { type: String, required: true },
});

const atLimit = props.maxFleets !== null && props.fleets.length >= props.maxFleets;
const showCreateForm = ref(false);

const form = useForm({ name: '' });

const submit = () => {
    form.post(route('fleet.store'), {
        onSuccess: () => {
            form.reset();
            showCreateForm.value = false;
        },
    });
};

// Pedido explícito del usuario: que el botón "Crear flota" valide el cupo
// del plan y, si no alcanza, lleve directo a subir de plan en vez de
// quedarse deshabilitado con solo un texto de ayuda al costado (fácil de
// pasar por alto).
function handleCreateClick() {
    if (atLimit) {
        router.visit(route('client.plan.edit'));
        return;
    }
    showCreateForm.value = true;
}
</script>

<template>
    <Head title="Mis flotas" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-xl text-arka-text leading-tight">Mis flotas</h2>
                <span class="text-sm text-arka-text-muted">
                    Plan {{ planName }} · {{ fleets.length }} de {{ maxFleets ?? '∞' }} flotas
                </span>
            </div>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <ul class="bg-arka-card shadow rounded-arka divide-y divide-arka-text-muted/10">
                    <li v-for="fleet in fleets" :key="fleet.id" class="p-4 sm:p-6 flex items-center justify-between gap-4">
                        <Link :href="route('fleet.show', fleet.id)" class="min-w-0">
                            <span class="text-arka-text font-medium hover:text-arka-primary-bright">
                                {{ fleet.name }}
                            </span>
                            <span class="block text-sm text-arka-text-muted">
                                {{ fleet.active_members_count }} conductor{{ fleet.active_members_count === 1 ? '' : 'es' }}
                            </span>
                        </Link>
                        <!-- Pedido explícito del usuario: acceso directo y bien rotulado
                             a sumar conductores, en vez de que la única entrada a esa
                             función sea tocar el nombre de la flota y encontrar el
                             buscador ahí adentro. -->
                        <Link
                            :href="route('fleet.show', fleet.id)"
                            class="shrink-0 px-3 py-1.5 rounded-arka border border-arka-primary text-arka-primary hover:bg-arka-primary/10 text-sm font-medium"
                        >
                            + Agregar conductores
                        </Link>
                    </li>
                </ul>

                <!-- Crear otra flota: solo tiene sentido desde el plan Multi-flota
                     (sección 7.3). Pedido explícito del usuario: en vez de un botón
                     deshabilitado con solo un texto de ayuda al costado (fácil de
                     pasar por alto), el botón queda siempre activo — si no alcanza el
                     plan, lleva directo a "Mi plan" a subirlo. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <div v-if="!showCreateForm" class="flex items-center justify-between gap-4">
                        <p class="text-sm text-arka-text-muted">
                            <span v-if="atLimit">
                                Llegó al límite de flotas de su plan {{ planName }} — toque para ver los planes con más cupo.
                            </span>
                            <span v-else>¿Necesita separar sus conductores en otra flota?</span>
                        </p>
                        <PrimaryButton class="shrink-0" @click="handleCreateClick">
                            {{ atLimit ? 'Subir de plan' : 'Crear otra flota' }}
                        </PrimaryButton>
                    </div>

                    <form v-else @submit.prevent="submit" class="flex items-end gap-3">
                        <div class="flex-1">
                            <InputLabel for="name" value="Nombre de la nueva flota" />
                            <TextInput
                                id="name"
                                type="text"
                                class="mt-1 block w-full"
                                v-model="form.name"
                                placeholder="Ej: Turno noche"
                                required
                            />
                            <InputError class="mt-2" :message="form.errors.name" />
                        </div>
                        <PrimaryButton :disabled="form.processing">Crear</PrimaryButton>
                        <SecondaryButton type="button" @click="showCreateForm = false">Cancelar</SecondaryButton>
                    </form>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
