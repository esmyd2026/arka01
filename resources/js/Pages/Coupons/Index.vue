<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

// Centro de cupones y beneficios (pedido explícito del usuario): apartado
// exclusivo por rol — este cliente/conductor solo ve las promociones de su
// propio lado, ya resueltas por el backend (CouponController::index()).
defineProps({
    audience: { type: String, required: true },
    coupons: { type: Array, required: true },
});
</script>

<template>
    <Head title="Cupones y beneficios" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-arka-text leading-tight">Cupones y beneficios</h2>
        </template>

        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <p class="text-sm text-arka-text-muted">
                    Descuentos y beneficios de comercios aliados para {{ audience === 'driver' ? 'conductores' : 'clientes' }} de Arka.
                </p>

                <p v-if="!coupons.length" class="text-sm text-arka-text-muted">
                    Todavía no hay cupones disponibles — vuelva a revisar pronto.
                </p>

                <div v-else class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div v-for="coupon in coupons" :key="coupon.id" class="bg-arka-card shadow rounded-arka overflow-hidden flex flex-col">
                        <img :src="coupon.image_url" :alt="coupon.title" class="w-full h-32 object-cover" />
                        <div class="p-4 flex-1 flex flex-col">
                            <p class="text-arka-text font-medium">{{ coupon.title }}</p>
                            <p v-if="coupon.description" class="mt-1 text-sm text-arka-text-muted flex-1">{{ coupon.description }}</p>
                            <p v-if="coupon.expires_at" class="mt-1 text-xs text-arka-text-muted">
                                Vence {{ new Date(coupon.expires_at).toLocaleDateString('es-EC', { dateStyle: 'medium' }) }}
                            </p>
                            <a
                                v-if="coupon.button_url"
                                :href="coupon.button_url"
                                target="_blank"
                                rel="noopener sponsored"
                                class="mt-3 inline-flex items-center justify-center px-4 py-2 bg-arka-primary border border-transparent rounded-arka font-semibold text-xs text-arka-base uppercase tracking-widest hover:bg-arka-primary-bright transition"
                            >
                                {{ coupon.button_label ?? 'Ver más' }}
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
