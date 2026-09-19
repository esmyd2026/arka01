import { createRouter, createWebHistory } from 'vue-router';
import { getToken } from './services/auth';
import Login from './views/Login.vue';
import Register from './views/Register.vue';
import Home from './views/Home.vue';
import Fleet from './views/Fleet.vue';
import RequestRide from './views/RequestRide.vue';
import RideStatus from './views/RideStatus.vue';
import DriverStatus from './views/DriverStatus.vue';
import IncomingRides from './views/IncomingRides.vue';
import ActiveRide from './views/ActiveRide.vue';
import Plan from './views/Plan.vue';
import TrustedContacts from './views/TrustedContacts.vue';
import Coupons from './views/Coupons.vue';
import DriverStats from './views/DriverStats.vue';
import Directory from './views/Directory.vue';
import Profile from './views/Profile.vue';
import DriverProfile from './views/DriverProfile.vue';
import Cooperatives from './views/Cooperatives.vue';
import CooperativeInvitations from './views/CooperativeInvitations.vue';
import TrustCircle from './views/TrustCircle.vue';
import SavedRoutes from './views/SavedRoutes.vue';
import VanTrips from './views/VanTrips.vue';
import Support from './views/Support.vue';
import ExpressRoutes from './views/ExpressRoutes.vue';
import RideHistory from './views/RideHistory.vue';
import DriverClients from './views/DriverClients.vue';

const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/login', name: 'login', component: Login, meta: { guest: true } },
        { path: '/registro', name: 'register', component: Register, meta: { guest: true } },
        { path: '/', name: 'home', component: Home, meta: { requiresAuth: true } },
        { path: '/flota', name: 'fleet', component: Fleet, meta: { requiresAuth: true } },
        { path: '/pedir-carrera', name: 'request-ride', component: RequestRide, meta: { requiresAuth: true } },
        { path: '/mis-carreras', name: 'ride-history', component: RideHistory, meta: { requiresAuth: true } },
        { path: '/carreras/:id', name: 'ride-status', component: RideStatus, meta: { requiresAuth: true }, props: true },
        { path: '/conductor', name: 'driver-status', component: DriverStatus, meta: { requiresAuth: true } },
        { path: '/carreras-entrantes', name: 'incoming-rides', component: IncomingRides, meta: { requiresAuth: true } },
        { path: '/mis-clientes', name: 'driver-clients', component: DriverClients, meta: { requiresAuth: true } },
        { path: '/carrera-activa/:id', name: 'active-ride', component: ActiveRide, meta: { requiresAuth: true }, props: true },
        { path: '/mi-plan', name: 'plan', component: Plan, meta: { requiresAuth: true } },
        { path: '/contactos-de-confianza', name: 'trusted-contacts', component: TrustedContacts, meta: { requiresAuth: true } },
        { path: '/cupones', name: 'coupons', component: Coupons, meta: { requiresAuth: true } },
        { path: '/mis-indicadores', name: 'driver-stats', component: DriverStats, meta: { requiresAuth: true } },
        { path: '/directorio', name: 'directory', component: Directory, meta: { requiresAuth: true } },
        { path: '/perfil', name: 'profile', component: Profile, meta: { requiresAuth: true } },
        { path: '/perfil-conductor', name: 'driver-profile', component: DriverProfile, meta: { requiresAuth: true } },
        { path: '/cooperativas', name: 'cooperatives', component: Cooperatives, meta: { requiresAuth: true } },
        { path: '/cooperativas/invitaciones', name: 'cooperative-invitations', component: CooperativeInvitations, meta: { requiresAuth: true } },
        { path: '/circulo-de-confianza', name: 'trust-circle', component: TrustCircle, meta: { requiresAuth: true } },
        { path: '/mis-rutas', name: 'saved-routes', component: SavedRoutes, meta: { requiresAuth: true } },
        { path: '/van', name: 'van-trips', component: VanTrips, meta: { requiresAuth: true } },
        { path: '/soporte', name: 'support', component: Support, meta: { requiresAuth: true } },
        { path: '/expresos', name: 'express-routes', component: ExpressRoutes, meta: { requiresAuth: true } },
    ],
});

// Comprobación de sesión al abrir la app (roadmap Hito 4: "splash y
// comprobación inicial de sesión") — acá sin pantalla de splash dedicada
// todavía, solo la redirección; Home.vue revalida el token contra la API
// apenas monta, por si ya fue revocado del lado del servidor.
router.beforeEach(async (to) => {
    const token = await getToken();

    if (to.meta.requiresAuth && !token) return { name: 'login' };
    if (to.meta.guest && token) return { name: 'home' };

    return true;
});

export default router;
