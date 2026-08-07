<script setup>
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Link } from '@inertiajs/vue3';

// "Mi suscripción" (consideración agregada al alcance): detalle del plan
// vigente de cada rol activo + llamado a la acción para cambiar de plan, con
// la sugerencia del próximo escalón hacia arriba ("por $X más, tené...").
defineProps({
    summary: { type: Object, required: true },
});

const SIDE_LABEL = { driver: 'conductor', client: 'cliente' };
const SIDE_ROUTE = { driver: 'driver.plan.edit', client: 'client.plan.edit' };

// Pedido explícito del usuario: "mostrar el estado y la fecha de vencimiento
// de la suscripción" — antes no se veía en ningún lado, solo el nombre del
// plan. Sin suscripción real detrás (plan Gratis) no hay nada que vencer.
const STATUS_LABEL = { active: 'Activa', grace: 'En gracia (por vencer)', expired: 'Vencida', cancelled: 'Cancelada' };

function subscriptionLine(current) {
    if (!current.subscription_status) return 'Plan Gratis — no vence.';

    const status = STATUS_LABEL[current.subscription_status] ?? current.subscription_status;
    if (!current.expires_at) return `${status} — sin fecha de vencimiento.`;

    const date = new Date(current.expires_at).toLocaleDateString('es-EC', { dateStyle: 'medium' });
    return `${status} — vence el ${date}.`;
}

// Pedido explícito del usuario ("explicame bien, en viñetas, qué es
// público, qué son las insignias"): antes acá solo se veía el NOMBRE del
// plan — para saber qué incluía de verdad había que ir a "Cambiar de plan" y
// todavía ahí quedaba como una etiqueta suelta ("Insignia de verificado"),
// sin explicar qué hace. Arma una viñeta explicada por cada función real del
// plan vigente, del lado que corresponda.
function bulletsFor(side, current) {
    if (side === 'driver') {
        return [
            current.max_clients === null
                ? 'Clientes de confianza ilimitados en su flota.'
                : `Hasta ${current.max_clients} cliente(s) de confianza en su flota.`,
            current.public_visibility
                ? 'Aparece en el directorio público: cualquier cliente lo puede encontrar y agregarlo a su flota, no solo quien ya lo conoce.'
                : 'No aparece en el directorio público — solo lo encuentran los clientes que ya lo agregaron directamente.',
            current.priority_listing
                ? 'Sale primero en los resultados del directorio público, antes que los conductores sin prioridad.'
                : 'Aparece en el orden normal del directorio público, sin prioridad.',
            current.verified_badge
                ? "Su plan incluye la insignia \"Conductor verificado\" en su perfil, una vez que un admin apruebe su licencia y su vehículo."
                : 'Su plan no incluye la insignia de verificado en el perfil.',
        ];
    }

    return [
        current.max_fleets === null
            ? 'Flotas ilimitadas.'
            : `Hasta ${current.max_fleets} flota(s) propia(s).`,
        current.max_drivers_per_fleet === null
            ? 'Conductores de confianza ilimitados por flota.'
            : `Hasta ${current.max_drivers_per_fleet} conductor(es) de confianza por flota.`,
    ];
}
</script>

<template>
    <section id="suscripcion" class="scroll-mt-24">
        <header>
            <h2 class="text-lg font-medium text-arka-text">Mi suscripción</h2>
            <p class="mt-1 text-sm text-arka-text-muted">El plan vigente de cada rol y qué más puede tener.</p>
        </header>

        <div class="mt-6 space-y-4">
            <div
                v-for="(data, side) in summary"
                :key="side"
                class="p-4 rounded-arka border border-arka-text-muted/20"
            >
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <p class="text-xs uppercase tracking-wide text-arka-text-muted">Como {{ SIDE_LABEL[side] }}</p>
                        <p class="text-arka-text font-medium">Plan {{ data.current.plan_name }}</p>
                        <p class="text-xs text-arka-text-muted mt-0.5">{{ subscriptionLine(data.current) }}</p>
                    </div>
                    <Link :href="route(SIDE_ROUTE[side])">
                        <PrimaryButton>Cambiar de plan</PrimaryButton>
                    </Link>
                </div>

                <!-- Qué incluye de verdad el plan vigente (pedido explícito del
                     usuario: "explicame bien, en viñetas") — antes solo se veía el
                     nombre del plan, sin decir qué significa cada función. -->
                <ul class="mt-3 space-y-1.5 text-sm text-arka-text-muted">
                    <li v-for="(bullet, i) in bulletsFor(side, data.current)" :key="i" class="flex gap-2">
                        <span class="text-arka-primary shrink-0">•</span>
                        <span>{{ bullet }}</span>
                    </li>
                </ul>

                <!-- Llamado a la acción: el precio grande es siempre el precio
                     real del próximo plan (no el incremento — decirle "por $5
                     tené el Plus" cuando el Plus cuesta $5 en total confunde,
                     el usuario lo marcó). El incremento respecto de lo que ya
                     paga queda como dato aparte, entre paréntesis. -->
                <div
                    v-if="data.next"
                    class="mt-3 p-3 rounded-arka bg-arka-primary/10 text-sm text-arka-text"
                >
                    El plan <strong>{{ data.next.name }}</strong> cuesta
                    <strong class="text-arka-primary-bright">${{ data.next.monthly_price }}/mes</strong>
                    (${{ data.next.price_diff.toFixed(2) }} más que su plan actual) y tenga {{ data.next.benefit }}.
                    <Link
                        :href="route(SIDE_ROUTE[side])"
                        class="block mt-1 text-arka-primary hover:text-arka-primary-bright font-medium"
                    >
                        Ver planes &rarr;
                    </Link>
                </div>
                <p v-else class="mt-3 text-sm text-arka-text-muted">Ya está en el plan más alto de este lado.</p>
            </div>
        </div>
    </section>
</template>
