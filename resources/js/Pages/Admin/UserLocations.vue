<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import FleetMap from '@/Components/FleetMap.vue';
import { Head } from '@inertiajs/vue3';

const props = defineProps({
    // [{ city_id, city, province, lat, lng, total }] — ordenado de mayor a
    // menor, "Sin ciudad" incluido para quien nunca dio ubicación ni la
    // eligió a mano en su perfil.
    byCity: { type: Array, required: true },
    totalUsers: { type: Number, required: true },
    // Cuántos de esos dieron permiso de ubicación real al registrarse (no
    // solo eligieron una ciudad a mano después) — pedido explícito del
    // usuario, para saber qué tan confiable es el resto de la pantalla.
    usersWithPreciseLocation: { type: Number, required: true },
    topNeighborhoods: { type: Array, required: true },
});

// Sin librería de gráficos en el proyecto (mismo criterio que Admin/Operations.vue):
// barra simple con ancho proporcional al máximo.
const maxCityTotal = Math.max(1, ...props.byCity.map((c) => c.total));

// Un marcador por CIUDAD (no por usuario individual): las coordenadas de
// cada persona no se muestran nunca en este mapa a propósito — sería
// exponer la ubicación exacta de cada cuenta (probablemente su casa) a
// cualquiera con acceso al panel admin. La ciudad ya alcanza para responder
// "de dónde se registra la gente".
const cityMarkers = props.byCity
    .filter((c) => c.lat != null && c.lng != null)
    .map((c) => ({ id: c.city_id, lat: c.lat, lng: c.lng, label: `${c.city} (${c.total})` }));
</script>

<template>
    <Head title="Admin · Registros por ubicación" />

    <AdminLayout title="Registros por ubicación">
        <div class="py-12">
            <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <p class="text-sm text-arka-text-muted">Usuarios con ciudad conocida</p>
                        <p class="text-2xl font-semibold text-arka-text">{{ totalUsers }}</p>
                    </div>
                    <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                        <p class="text-sm text-arka-text-muted">Con ubicación exacta dada al registrarse</p>
                        <p class="text-2xl font-semibold text-arka-text">{{ usersWithPreciseLocation }}</p>
                        <p class="text-xs text-arka-text-muted">El resto eligió su ciudad a mano en el perfil, o no la completó.</p>
                    </div>
                </div>

                <!-- Mapa por ciudad (nunca por usuario individual, ver
                     comentario de cityMarkers arriba: no se expone la
                     ubicación exacta de ninguna cuenta). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-3">
                    <h3 class="text-lg font-medium text-arka-text">Mapa por ciudad</h3>
                    <p v-if="!cityMarkers.length" class="text-sm text-arka-text-muted">
                        Todavía no hay suficientes registros con ciudad conocida.
                    </p>
                    <FleetMap v-else :markers="cityMarkers" height="360px" />
                </div>

                <!-- Tabla ordenada, con barra proporcional (mismo criterio que
                     "Demanda histórica" en Operaciones). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-2">
                    <h3 class="text-lg font-medium text-arka-text mb-2">Por ciudad</h3>
                    <div v-for="c in byCity" :key="c.city_id ?? 'sin-ciudad'" class="flex items-center gap-3 text-sm">
                        <span class="w-40 shrink-0 text-arka-text truncate" :title="c.province ? `${c.city}, ${c.province}` : c.city">
                            {{ c.city }}
                        </span>
                        <div class="flex-1 bg-arka-base/60 rounded-full h-3 overflow-hidden">
                            <div class="h-full bg-arka-primary rounded-full" :style="{ width: `${(c.total / maxCityTotal) * 100}%` }" />
                        </div>
                        <span class="w-10 text-right text-arka-text">{{ c.total }}</span>
                    </div>
                </div>

                <!-- Barrio/zona aproximado, informativo (pedido explícito del
                     usuario, resuelto vía OpenStreetMap — ver
                     App\Jobs\ResolveRegistrationNeighborhood). Distinto del
                     catálogo propio de sectores/barrios (ese lo elige la
                     gente a mano al pedir una carrera, este sale solo de la
                     ubicación real dada al registrarse). -->
                <div v-if="topNeighborhoods.length" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-1">Barrios más frecuentes (aproximado)</h3>
                    <p class="text-xs text-arka-text-muted mb-3">
                        Resuelto automáticamente a partir de la ubicación dada al registrarse — puede no coincidir
                        exactamente con los nombres del catálogo de zonas.
                    </p>
                    <table class="w-full text-sm">
                        <tbody class="divide-y divide-arka-text-muted/10">
                            <tr v-for="n in topNeighborhoods" :key="n.registration_neighborhood">
                                <td class="py-2 text-arka-text">{{ n.registration_neighborhood }}</td>
                                <td class="py-2 text-right text-arka-text-muted">{{ n.total }} registro(s)</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
