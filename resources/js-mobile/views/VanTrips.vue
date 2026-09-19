<script setup>
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { browseVanTrips, reserveVanTripSeats } from '../services/vanTrips';
import { fetchCities } from '../services/profile';
import { getStoredUser } from '../services/auth';
import MobileShell from '../components/MobileShell.vue';

const router = useRouter();
const user = ref(null);
const loading = ref(true);
const error = ref(null);

const cities = ref([]);
const originCityId = ref('');
const destinationCityId = ref('');
const travelDate = ref('');

const trips = ref([]);
const fallbackTrips = ref([]);
const searchSaved = ref(false);

const reservingId = ref(null);
const seatsById = ref({});
const notice = ref(null);

function seatsAvailable(trip) {
    return trip.total_seats - (trip.reserved_seats_count || 0);
}

async function search() {
    loading.value = true;
    error.value = null;
    notice.value = null;
    try {
        const data = await browseVanTrips({
            origin_city_id: originCityId.value || null,
            destination_city_id: destinationCityId.value || null,
            travel_date: travelDate.value || null,
        });
        trips.value = data.trips;
        fallbackTrips.value = data.fallbackTrips || [];
        searchSaved.value = data.searchSaved;
    } catch (e) {
        error.value = e.message || 'No se pudieron cargar los viajes.';
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    user.value = await getStoredUser();
    if (user.value?.role === 'conductor') {
        router.replace({ name: 'home' });
        return;
    }
    try {
        cities.value = await fetchCities();
    } catch (e) {
        // Sin bloquear la búsqueda si el catálogo de ciudades falla.
    }
    await search();
});

async function reserve(trip) {
    const seats = Number(seatsById.value[trip.id] || 1);
    reservingId.value = trip.id;
    error.value = null;
    try {
        await reserveVanTripSeats(trip.id, seats);
        notice.value = `Reserva confirmada para ${trip.driver?.full_name || 'el viaje'}.`;
        await search();
    } catch (e) {
        error.value = e.message || 'No se pudo reservar.';
    } finally {
        reservingId.value = null;
    }
}
</script>

<template>
    <MobileShell v-if="user" :role="user.role" :user-name="user.name">
        <main class="mobile-page van-page">
            <button class="back-link" @click="router.back()">‹ Volver</button>

            <section class="welcome">
                <p class="mobile-eyebrow">Viajes programados</p>
                <h1 class="mobile-title">Van y buseta</h1>
                <p class="welcome-copy">Explora y reserva asientos en viajes turísticos o interprovinciales.</p>
            </section>

            <form class="filters-card mobile-card" @submit.prevent="search">
                <select v-model="originCityId" class="mobile-input"><option value="">Ciudad de origen</option><option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option></select>
                <select v-model="destinationCityId" class="mobile-input"><option value="">Ciudad de destino</option><option v-for="city in cities" :key="city.id" :value="city.id">{{ city.name }}</option></select>
                <input v-model="travelDate" type="date" class="mobile-input" />
                <button class="mobile-button" type="submit">Buscar</button>
            </form>

            <div v-if="loading" class="loading-row"><span class="mobile-spinner"></span><span>Cargando…</span></div>
            <p v-else-if="error" class="mobile-alert">{{ error }}</p>
            <p v-else-if="notice" class="notice-copy">{{ notice }}</p>

            <template v-if="!loading">
                <p v-if="searchSaved" class="empty-copy">No hay viajes para esa ruta todavía — le avisamos a los conductores que la buscas.</p>

                <article v-for="trip in trips" :key="trip.id" class="trip-card mobile-card">
                    <div class="trip-header">
                        <strong>{{ trip.origin_address || trip.origin_city?.name }} → {{ trip.destination_address || trip.destination_city?.name }}</strong>
                        <small>{{ trip.travel_date }} · {{ trip.departure_time }}</small>
                    </div>
                    <p class="trip-driver">{{ trip.driver?.full_name || trip.driver?.name }} · {{ seatsAvailable(trip) }} asiento(s) libres</p>
                    <p v-if="trip.description" class="trip-desc">{{ trip.description }}</p>
                    <div class="trip-footer">
                        <strong class="trip-price">${{ Number(trip.price_per_seat).toFixed(2) }}/asiento</strong>
                        <div class="reserve-row">
                            <input v-model="seatsById[trip.id]" type="number" min="1" :max="seatsAvailable(trip)" class="seats-input" placeholder="1" />
                            <button class="mini-button accept" :disabled="reservingId === trip.id" @click="reserve(trip)">
                                {{ reservingId === trip.id ? '…' : 'Reservar' }}
                            </button>
                        </div>
                    </div>
                </article>

                <template v-if="fallbackTrips.length">
                    <div class="section-heading"><p class="mobile-eyebrow">Mientras tanto</p><h2>Otros viajes disponibles</h2></div>
                    <article v-for="trip in fallbackTrips" :key="trip.id" class="trip-card mobile-card">
                        <div class="trip-header">
                            <strong>{{ trip.origin_address || trip.origin_city?.name }} → {{ trip.destination_address || trip.destination_city?.name }}</strong>
                            <small>{{ trip.travel_date }} · {{ trip.departure_time }}</small>
                        </div>
                        <p class="trip-driver">{{ trip.driver?.full_name || trip.driver?.name }} · {{ seatsAvailable(trip) }} asiento(s) libres<span v-if="trip.is_own_fleet"> · De tu flota</span></p>
                        <div class="trip-footer">
                            <strong class="trip-price">${{ Number(trip.price_per_seat).toFixed(2) }}/asiento</strong>
                            <div class="reserve-row">
                                <input v-model="seatsById[trip.id]" type="number" min="1" :max="seatsAvailable(trip)" class="seats-input" placeholder="1" />
                                <button class="mini-button accept" :disabled="reservingId === trip.id" @click="reserve(trip)">
                                    {{ reservingId === trip.id ? '…' : 'Reservar' }}
                                </button>
                            </div>
                        </div>
                    </article>
                </template>
            </template>
        </main>
    </MobileShell>

    <main v-else-if="loading" class="loading-screen"><span class="mobile-spinner"></span><p>Cargando…</p></main>
</template>

<style scoped>
.van-page { display: grid; gap: 1.15rem; }
.back-link { justify-self: start; border: 0; background: transparent; color: var(--arka-muted); font-size: .85rem; font-weight: 700; padding: .35rem 0; }
.welcome { padding: 0 .25rem; }
.welcome-copy { margin: .5rem 0 0; color: var(--arka-muted); font-size: .85rem; line-height: 1.5; }
.filters-card { padding: 1.1rem; display: grid; gap: .6rem; }
.loading-row { display: flex; align-items: center; gap: .6rem; padding: 1rem .25rem; color: var(--arka-muted); font-size: .88rem; }
.empty-copy { margin: 0; padding: .25rem; color: var(--arka-muted); font-size: .85rem; }
.notice-copy { margin: 0; padding: .25rem; color: var(--arka-primary); font-size: .82rem; font-weight: 700; }
.trip-card { margin-bottom: .7rem; padding: 1rem; display: grid; gap: .4rem; }
.trip-header { display: flex; flex-direction: column; gap: .15rem; }
.trip-header strong { font-size: .88rem; }
.trip-header small { color: var(--arka-muted); font-size: .72rem; text-transform: uppercase; }
.trip-driver { margin: 0; color: var(--arka-text); font-size: .8rem; }
.trip-desc { margin: 0; color: var(--arka-muted); font-size: .78rem; line-height: 1.4; }
.trip-footer { margin-top: .3rem; display: flex; align-items: center; justify-content: space-between; gap: .6rem; }
.trip-price { color: var(--arka-primary); font-size: .92rem; }
.reserve-row { display: flex; gap: .4rem; align-items: center; }
.seats-input { width: 3.4rem; min-height: 2.3rem; padding: 0 .5rem; border-radius: .6rem; border: 1px solid var(--arka-border); background:var(--arka-field); color: var(--arka-text); }
.mini-button { min-height: 2.3rem; padding: 0 .8rem; border-radius: .7rem; font-size: .76rem; font-weight: 700; border: 1px solid transparent; }
.mini-button.accept { background: var(--arka-primary); color: #062016; }
.section-heading { margin: .4rem .2rem .3rem; }
.section-heading h2 { margin: .25rem 0 0; font-size: 1.05rem; letter-spacing: -.02em; }
.loading-screen { min-height: 100dvh; display: grid; place-content: center; justify-items: center; gap: .75rem; color: var(--arka-muted); }
</style>
