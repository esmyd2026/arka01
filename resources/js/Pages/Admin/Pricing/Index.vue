<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    countries: { type: Array, required: true },
});
</script>

<template>
    <Head title="Admin · Tarifas" />

    <AdminLayout title="Tarifas">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Cada país tiene su propia fila de tarifas, en su propia moneda — un tope puesto en dólares para
                    Ecuador no tiene por qué servir para un conductor que cobra en pesos chilenos. Elegí un país para
                    editar su cálculo de precio sugerido.
                </p>

                <div class="bg-arka-card shadow rounded-arka divide-y divide-arka-border">
                    <Link
                        v-for="country in countries"
                        :key="country.id"
                        :href="route('admin.pricing.edit', country.id)"
                        class="flex items-center justify-between gap-4 p-4 sm:p-6 hover:bg-arka-primary/5"
                    >
                        <div>
                            <p class="text-arka-text font-medium">{{ country.name }}</p>
                            <p class="text-sm text-arka-text-muted">{{ country.currency_code }} ({{ country.currency_symbol }})</p>
                        </div>
                        <span class="text-arka-primary text-sm">Editar tarifas →</span>
                    </Link>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
