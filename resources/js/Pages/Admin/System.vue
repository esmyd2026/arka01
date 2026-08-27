<script setup>
import { computed } from 'vue';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

const props = defineProps({
    demoAccountsCount: { type: Number, required: true },
    // Pedido explícito del usuario: "permiteme en el modulo de sistema de
    // habilitar o no estas opciones del menu tanto las del conductor como
    // las del cliente" — ver App\Services\QuickLinkRegistry.
    quickLinks: { type: Array, required: true },
    // Pedido explícito del usuario: "permiteme desde el admin poder activar
    // o no lo obligatorio para que el conductor se le haga mas facil
    // activarse" — ver App\Services\DriverVerificationRequirementRegistry.
    driverRequirements: { type: Array, required: true },
});

const QUICK_LINK_GROUP_LABEL = { conductor: 'Menú del conductor', cliente: 'Menú del cliente' };
const quickLinkGroups = ['conductor', 'cliente'].map((group) => ({
    key: group,
    label: QUICK_LINK_GROUP_LABEL[group],
    items: props.quickLinks.filter((item) => item.group === group),
}));

const quickLinksForm = useForm({
    disabled: props.quickLinks.filter((item) => !item.enabled).map((item) => item.route),
});

function isChecked(route) {
    return !quickLinksForm.disabled.includes(route);
}
function toggle(route) {
    quickLinksForm.disabled = isChecked(route)
        ? [...quickLinksForm.disabled, route]
        : quickLinksForm.disabled.filter((r) => r !== route);
}
function saveQuickLinks() {
    quickLinksForm.patch(route('admin.system.quick-links.update'), { preserveScroll: true });
}

// Pedido explícito del usuario: "permiteme desde el admin poder activar o
// no lo obligatorio para que el conductor se le haga mas facil activarse"
// — mismo patrón de toggles que los accesos rápidos de arriba, pero para
// qué le exige el registro/verificación (ver DriverProfileController::update()
// y DriverProfile::hasCompleteRegistrationInformation()).
const driverRequirementsForm = useForm({
    disabled: props.driverRequirements.filter((item) => !item.enabled).map((item) => item.key),
});

function isRequirementChecked(key) {
    return !driverRequirementsForm.disabled.includes(key);
}
function toggleRequirement(key) {
    driverRequirementsForm.disabled = isRequirementChecked(key)
        ? [...driverRequirementsForm.disabled, key]
        : driverRequirementsForm.disabled.filter((k) => k !== key);
}
function saveDriverRequirements() {
    driverRequirementsForm.patch(route('admin.system.driver-requirements.update'), { preserveScroll: true });
}

// Zona de peligro (pedido explícito del usuario): confirmación fuerte antes
// de borrar, con el texto explícito de qué se pierde — ver Admin\SystemController.
// Ajuste explícito del usuario a la versión anterior: ya no toca NINGUNA
// cuenta admin ni ninguna configuración (planes, tarifas, cupones, etc.) —
// solo los conductores y clientes de prueba.
async function resetDemo() {
    const confirmed = await confirmDialog(
        'Esto borra las cuentas @arka01.test de CLIENTES y CONDUCTORES de prueba, junto con sus carreras, reseñas, suscripciones/comprobantes y fotos, y vuelve a crear los 4 clientes + 4 conductores demo. Ninguna cuenta admin ni ninguna configuración (planes, tarifas, cupones, etc.) se toca. Esta acción no se puede deshacer.',
        { danger: true, confirmLabel: 'Borrar suscriptores demo' }
    );
    if (!confirmed) return;

    router.post(route('admin.system.reset-demo'), { enter_demo: true });
}
</script>

<template>
    <Head title="Admin · Sistema" />

    <AdminLayout title="Sistema">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka border border-arka-danger/40 space-y-4">
                    <h3 class="text-lg font-medium text-arka-danger">Zona de peligro</h3>

                    <p class="text-sm text-arka-text">
                        Hay <strong>{{ demoAccountsCount }}</strong> cuentas de prueba (clientes y conductores con
                        correo terminado en <code class="text-xs bg-arka-base px-1 py-0.5 rounded">@arka01.test</code>)
                        en la base.
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        Este botón borra esos clientes y conductores de prueba con todo lo que les pertenece
                        (flotas, carreras, reseñas, suscripciones y comprobantes de pago, fotos de perfil/licencia/
                        vehículo), y vuelve a crear los 4 clientes + 4 conductores demo, todos con la contraseña
                        "password".
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        <strong class="text-arka-text">Nunca toca:</strong> ninguna cuenta administradora (ni la
                        suya ni ninguna otra) ni las configuraciones ya hechas — planes, tarifas, cupones, banners,
                        preguntas frecuentes, medallas, zonas, integración de WhatsApp, etc. Cualquier cuenta con
                        otro correo tampoco se toca.
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        Si alguna cuenta real llegó a compartir una carrera o una flota con una cuenta de prueba
                        (por ejemplo, agregó a un conductor demo a su flota real), ese vínculo puntual se pierde
                        junto con la cuenta demo — la cuenta real en sí no se borra.
                    </p>

                    <p class="rounded-lg bg-arka-primary/10 p-3 text-sm text-arka-primary">
                        Al finalizar se cerrará esta sesión administrativa y se abrirá el login para que pueda probar como cliente, conductor o cooperativa.
                    </p>

                    <DangerButton @click="resetDemo">Reiniciar demo e ir al login</DangerButton>
                </div>

                <!-- Pedido explícito del usuario: "permiteme en el modulo de
                     sistema de habilitar o no estas opciones del menu tanto
                     las del conductor como las del cliente" — si se apaga
                     un renglón, ese acceso deja de verse en el menú de
                     cuenta, el botón "+" de móvil y el mapa de escritorio
                     (ver quickLinks en AuthenticatedLayout.vue). -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Accesos rápidos del menú</h3>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Prenda o apague cada acceso — si lo apaga, deja de verse en el menú de cuenta y en los
                            accesos rápidos, tanto en escritorio como en móvil.
                        </p>
                    </div>

                    <div v-for="group in quickLinkGroups" :key="group.key">
                        <p class="text-sm font-medium text-arka-text mb-2">{{ group.label }}</p>
                        <ul class="divide-y divide-arka-text-muted/10">
                            <li v-for="item in group.items" :key="item.route" class="py-2.5">
                                <label class="flex items-center gap-3">
                                    <input
                                        type="checkbox"
                                        :checked="isChecked(item.route)"
                                        class="rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary"
                                        @change="toggle(item.route)"
                                    />
                                    <span class="text-sm text-arka-text">{{ item.label }}</span>
                                </label>
                            </li>
                        </ul>
                    </div>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="quickLinksForm.processing" @click="saveQuickLinks">Guardar</PrimaryButton>
                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-if="quickLinksForm.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                        </Transition>
                    </div>
                </div>

                <!-- Pedido explícito del usuario: "ayudame a quitar o no
                     validaciones de los conductores... para no limitar el
                     registro de un conductor" y "permiteme desde el admin
                     poder activar o no lo obligatorio para que el conductor
                     se le haga mas facil activarse". -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <h3 class="text-lg font-medium text-arka-text">Requisitos para activarse como conductor</h3>
                        <p class="mt-1 text-sm text-arka-text-muted">
                            Si apaga uno, deja de exigirse tanto para guardar el perfil por primera vez como para
                            poder conectarse y recibir carreras — el conductor lo puede completar después de todas
                            formas, solo que ya no lo bloquea.
                        </p>
                    </div>

                    <ul class="divide-y divide-arka-text-muted/10">
                        <li v-for="item in driverRequirements" :key="item.key" class="py-2.5">
                            <label class="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    :checked="isRequirementChecked(item.key)"
                                    class="rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary"
                                    @change="toggleRequirement(item.key)"
                                />
                                <span class="text-sm text-arka-text">{{ item.label }}</span>
                            </label>
                        </li>
                    </ul>

                    <div class="flex items-center gap-4">
                        <PrimaryButton :disabled="driverRequirementsForm.processing" @click="saveDriverRequirements">Guardar</PrimaryButton>
                        <Transition
                            enter-active-class="transition ease-in-out"
                            enter-from-class="opacity-0"
                            leave-active-class="transition ease-in-out"
                            leave-to-class="opacity-0"
                        >
                            <p v-if="driverRequirementsForm.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                        </Transition>
                    </div>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
