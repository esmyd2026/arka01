<script setup>
import { reactive } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import DangerButton from '@/Components/DangerButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    availableDrivers: { type: Array, required: true },
    allDrivers: { type: Object, required: true },
    cities: { type: Array, required: true },
    filters: { type: Object, required: true },
});

// Filtros de la tabla completa (pedido explícito del usuario: paginado +
// filtros, para no cargar/renderizar todo el directorio de una sola vez. Se
// manda por GET y conserva el estado de búsqueda entre páginas.
const form = reactive({
    q: props.filters.q ?? '',
    city_id: props.filters.city_id ?? '',
    status: props.filters.status ?? '',
});

function applyFilters() {
    router.get(route('admin.drivers.index'), form, { preserveState: true, preserveScroll: true, replace: true });
}

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleDateString('es-EC', { day: '2-digit', month: 'short', year: 'numeric' });
}

async function suspend(driver) {
    if (!(await confirmDialog(`¿Suspender a ${driver.name}? No va a poder conectarse ni recibir carreras hasta que lo reactives.`, { danger: true, confirmLabel: 'Suspender' }))) return;
    router.post(route('admin.drivers.suspend', driver.id), {}, { preserveScroll: true });
}

function reactivate(driver) {
    router.post(route('admin.drivers.reactivate', driver.id), {}, { preserveScroll: true });
}

function toggleWhatsApp(driver) {
    router.patch(route('admin.drivers.whatsapp', driver.id), { enabled: !driver.whatsapp_ride_actions_enabled }, { preserveScroll: true });
}

</script>

<template>
    <Head title="Admin · Conductores" />

    <AdminLayout title="Conductores">
        <div class="py-6 sm:py-8">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <!-- Esta pantalla administra personas. El mapa nacional y la
                     lista repetida de conectados pertenecen a Operación; acá
                     solo queda el dato necesario y un acceso directo. -->
                <section class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-arka-primary/20 bg-arka-card p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-arka-text-muted">Disponibles ahora</p>
                        <div class="mt-2 flex items-end justify-between gap-3"><strong class="text-3xl text-arka-primary-bright">{{ availableDrivers.length }}</strong><span class="mb-1 h-2.5 w-2.5 rounded-full bg-arka-primary"></span></div>
                    </div>
                    <div class="rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4">
                        <p class="text-xs font-bold uppercase tracking-[0.14em] text-arka-text-muted">Conductores registrados</p>
                        <strong class="mt-2 block text-3xl text-arka-text">{{ allDrivers.total }}</strong>
                    </div>
                    <Link :href="route('admin.live-operations.index')" class="group flex items-center justify-between gap-3 rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4 hover:border-arka-primary/35">
                        <span><span class="block text-sm font-semibold text-arka-text">Ver operación en vivo</span><span class="mt-1 block text-xs text-arka-text-muted">Mapa y carreras activas</span></span><span class="text-xl text-arka-primary-bright group-hover:translate-x-1">→</span>
                    </Link>
                </section>

                <!-- Roster completo: bloquear/deshabilitar/desconectar (pedido
                     explícito del usuario), ahora paginado y filtrable por
                     nombre/correo, ciudad y estado. -->
                <div class="space-y-4 rounded-2xl border border-arka-text-muted/10 bg-arka-card p-4 shadow sm:p-6">
                    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-arka-primary-bright">Administración</p><h3 class="mt-1 text-lg font-semibold text-arka-text">Directorio de conductores</h3><p class="mt-1 text-xs text-arka-text-muted">Busque, revise el perfil o suspenda una cuenta. Las categorías se deciden durante la verificación.</p></div><Link :href="route('admin.driver-verifications.index')" class="rounded-xl bg-arka-primary px-4 py-2.5 text-sm font-bold text-arka-base">Ir a verificaciones</Link></div>

                    <form @submit.prevent="applyFilters" class="flex flex-wrap items-end gap-3">
                        <div class="flex-1 min-w-[160px]">
                            <TextInput v-model="form.q" placeholder="Nombre o correo" class="w-full" />
                        </div>
                        <select v-model="form.city_id" class="rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm">
                            <option value="">Todas las ciudades</option>
                            <option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option>
                        </select>
                        <select v-model="form.status" class="rounded-arka border-arka-text-muted/30 bg-arka-card text-arka-text text-sm">
                            <option value="">Cualquier estado</option>
                            <option value="available">Disponible</option>
                            <option value="suspended">Suspendido</option>
                            <option value="offline">Desconectado</option>
                        </select>
                        <PrimaryButton type="submit">Filtrar</PrimaryButton>
                    </form>

                    <div class="hidden overflow-x-auto md:block">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-arka-text-muted border-b border-arka-text-muted/10">
                                    <th class="py-2 pr-3">Conductor</th>
                                    <th class="py-2 pr-3">Vehículo</th>
                                    <th class="py-2 pr-3">Operación</th>
                                    <th class="py-2 pr-3">Verificación</th>
                                    <th class="py-2 text-right">Acciones</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-arka-text-muted/10">
                                <tr v-for="d in allDrivers.data" :key="d.user_id">
                                    <td class="py-3 pr-3">
                                        <Link :href="route('admin.users.show', d.user_id)" class="text-arka-text font-medium hover:text-arka-primary-bright">
                                            {{ d.name }}
                                        </Link>
                                        <p class="text-xs text-arka-text-muted">{{ d.email }}</p>
                                        <p class="mt-1 text-[11px] text-arka-text-muted">{{ d.city ?? 'Sin ciudad' }} · Registro {{ formatDate(d.registered_at) }}</p>
                                    </td>
                                    <td class="py-3 pr-3">
                                        <p class="text-xs font-medium text-arka-text">{{ d.vehicle || 'Vehículo sin completar' }}</p>
                                        <div class="mt-1 flex flex-wrap gap-1"><span v-if="d.service_category_label" class="rounded-full bg-arka-primary/10 px-2 py-0.5 text-[10px] text-arka-primary-bright">{{ d.service_category_label }}</span><span v-if="d.public_category_label" class="rounded-full bg-arka-base px-2 py-0.5 text-[10px] text-arka-text-muted">{{ d.public_category_label }}</span></div>
                                    </td>
                                    <td class="py-3 pr-3">
                                        <span v-if="d.is_suspended" class="text-arka-danger font-medium">Suspendido</span>
                                        <span v-else-if="d.is_available" class="text-arka-primary-bright font-medium">Disponible</span>
                                        <span v-else class="text-arka-text-muted">Desconectado</span>
                                        <p class="mt-1 text-[11px] text-arka-text-muted">{{ d.completed_rides_count }} completadas · {{ d.rides_rejected_count }} rechazos</p>
                                        <button type="button" class="mt-1 text-[11px] font-medium" :class="d.whatsapp_ride_actions_enabled ? 'text-arka-primary' : 'text-arka-text-muted'" @click="toggleWhatsApp(d)">{{ d.whatsapp_ride_actions_enabled ? 'WhatsApp activo' : 'Solo avisos WhatsApp' }}</button>
                                    </td>
                                    <td class="py-3 pr-3 text-arka-text-muted">
                                        <span class="rounded-full px-2 py-1 text-xs" :class="d.verification_status === 'approved' ? 'bg-arka-primary/10 text-arka-primary-bright' : d.verification_status === 'rejected' ? 'bg-arka-danger/10 text-arka-danger' : 'bg-arka-warning/10 text-arka-warning'">{{ d.verification_status || 'Incompleta' }}</span>
                                        <p class="mt-2 text-[11px]">Actividad {{ formatDate(d.last_active_at) }}</p>
                                    </td>
                                    <td class="py-3 text-right">
                                        <div class="flex justify-end gap-2"><Link :href="route('admin.users.show', d.user_id)" class="rounded-lg border border-arka-text-muted/20 px-2.5 py-1.5 text-xs font-medium text-arka-text">Ver perfil</Link><DangerButton v-if="!d.is_suspended" size="sm" @click="suspend(d)">Suspender</DangerButton><PrimaryButton v-else size="sm" @click="reactivate(d)">Reactivar</PrimaryButton></div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="space-y-2 md:hidden">
                        <article v-for="d in allDrivers.data" :key="d.user_id" class="rounded-xl border border-arka-text-muted/10 bg-arka-base/40 p-3">
                            <div class="flex items-start justify-between gap-3"><div class="min-w-0"><Link :href="route('admin.users.show', d.user_id)" class="truncate font-semibold text-arka-text">{{ d.name }}</Link><p class="truncate text-xs text-arka-text-muted">{{ d.email }}</p></div><span class="shrink-0 rounded-full px-2 py-1 text-[10px]" :class="d.is_suspended ? 'bg-arka-danger/10 text-arka-danger' : d.is_available ? 'bg-arka-primary/10 text-arka-primary-bright' : 'bg-arka-card text-arka-text-muted'">{{ d.is_suspended ? 'Suspendido' : d.is_available ? 'Disponible' : 'Desconectado' }}</span></div>
                            <p class="mt-3 text-xs text-arka-text">{{ d.vehicle || 'Vehículo sin completar' }}</p><p class="mt-1 text-[11px] text-arka-text-muted">{{ d.completed_rides_count }} carreras · {{ d.verification_status || 'Verificación incompleta' }}</p>
                            <div class="mt-3 flex gap-2"><Link :href="route('admin.users.show', d.user_id)" class="flex-1 rounded-lg border border-arka-text-muted/20 px-3 py-2 text-center text-xs font-medium text-arka-text">Ver perfil</Link><button v-if="!d.is_suspended" type="button" class="rounded-lg px-3 py-2 text-xs font-medium text-arka-danger" @click="suspend(d)">Suspender</button><button v-else type="button" class="rounded-lg px-3 py-2 text-xs font-medium text-arka-primary" @click="reactivate(d)">Reactivar</button></div>
                        </article>
                    </div>

                    <!-- Paginado simple: nunca se carga todo de una vez (sección 9.7) -->
                    <div v-if="allDrivers.prev_page_url || allDrivers.next_page_url" class="flex justify-between pt-2">
                        <Link
                            v-if="allDrivers.prev_page_url"
                            :href="allDrivers.prev_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            &larr; Anterior
                        </Link>
                        <span v-else></span>

                        <Link
                            v-if="allDrivers.next_page_url"
                            :href="allDrivers.next_page_url"
                            preserve-state
                            class="text-sm text-arka-primary hover:text-arka-primary-bright"
                        >
                            Siguiente &rarr;
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
