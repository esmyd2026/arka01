<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import DangerButton from '@/Components/DangerButton.vue';
import { Head, router } from '@inertiajs/vue3';
import { confirmDialog } from '@/Utils/confirmDialog';

defineProps({
    demoAccountsCount: { type: Number, required: true },
});

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

    router.post(route('admin.system.reset-demo'));
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

                    <DangerButton @click="resetDemo">Borrar suscriptores demo</DangerButton>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
