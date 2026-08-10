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
async function resetDemo() {
    const confirmed = await confirmDialog(
        'Esto borra TODAS las cuentas @arka01.test (admin, clientes y conductores de prueba) junto con sus flotas, carreras, suscripciones y reseñas, y vuelve a crear el elenco base de 9 cuentas. Cualquier cuenta con otro correo queda intacta. Esta acción no se puede deshacer.',
        { danger: true, confirmLabel: 'Borrar demo y reiniciar' }
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
                        Hay <strong>{{ demoAccountsCount }}</strong> cuentas de prueba (correo terminado en
                        <code class="text-xs bg-arka-base px-1 py-0.5 rounded">@arka01.test</code>) en la base.
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        Este botón borra todas esas cuentas y todo lo que les pertenece (flotas, carreras,
                        suscripciones, reseñas, perfiles de conductor), y vuelve a crear el elenco base: un admin y
                        4 clientes + 4 conductores, todos con la contraseña "password". Cualquier cuenta con otro
                        correo no se toca.
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        Si alguna cuenta real llegó a compartir una carrera o una flota con una cuenta de prueba
                        (por ejemplo, agregó a un conductor demo a su flota real), ese vínculo puntual se pierde
                        junto con la cuenta demo — la cuenta real en sí no se borra.
                    </p>

                    <p class="text-sm text-arka-text-muted">
                        Si la cuenta con la que inició sesión ahora mismo es una cuenta de prueba, se va a cerrar su
                        sesión y se lo va a mandar al login después de reiniciar.
                    </p>

                    <DangerButton @click="resetDemo">Borrar demo y reiniciar</DangerButton>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
