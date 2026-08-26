<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import UserAvatar from '@/Components/UserAvatar.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
    envFallback: { type: Object, required: true },
    auditLogs: { type: Array, required: true },
    // Pedido explícito del usuario: "ayudame a configurar los modulos que
    // yo active de envios de whatsapp... y coloquemos precios estimados por
    // las cantidades de mensajes enviados quiero ver indicadores alli".
    notificationTypes: { type: Array, required: true },
    messageStats: { type: Object, required: true },
});

// Pedido explícito del usuario: nunca se manda el valor real de un campo
// sensible al navegador — el form arranca vacío para esos tres, "en blanco"
// significa "no tocar" al guardar (ver Admin\WhatsAppSettingController::update()).
const form = useForm({
    token: '',
    phone_number_id: props.settings.phone_number_id ?? '',
    verification_template: props.settings.verification_template ?? '',
    business_number: props.settings.business_number ?? '',
    webhook_verify_token: '',
    app_secret: '',
    ride_notifications_enabled: props.settings.ride_notifications_enabled,
    driver_ride_actions_enabled: props.settings.driver_ride_actions_enabled,
    client_ride_booking_enabled: props.settings.client_ride_booking_enabled,
    privacy_notice_text: props.settings.privacy_notice_text ?? '',
    // Pedido explícito del usuario: un toggle por tipo de aviso — arranca
    // con lo que ya viene de cada uno, se manda todo junto con el resto del
    // formulario para no complicar con un submit aparte por checkbox.
    ...Object.fromEntries(props.notificationTypes.map((type) => [`notify_${type.key}`, type.enabled])),
    estimated_cost_per_message: props.settings.estimated_cost_per_message,
});

const submit = () => {
    form.patch(route('admin.integrations.whatsapp.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset('token', 'webhook_verify_token', 'app_secret'),
    });
};

function statusFor(hasInDb, envValue) {
    if (hasInDb) return { label: 'Configurado acá', class: 'text-arka-primary-bright' };
    if (envValue) return { label: 'Usando el .env', class: 'text-arka-text-muted' };
    return { label: 'Sin configurar', class: 'text-arka-warning' };
}

const NOTIFICATION_GROUP_LABEL = { cliente: 'Notificaciones al cliente', conductor: 'Notificaciones al conductor' };
const notificationGroups = ['cliente', 'conductor'].map((group) => ({
    key: group,
    label: NOTIFICATION_GROUP_LABEL[group],
    items: props.notificationTypes.filter((type) => type.group === group),
}));
</script>

<template>
    <Head title="Admin · Integraciones · WhatsApp" />

    <AdminLayout title="Integraciones · WhatsApp">
        <div class="py-12">
            <div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <p class="text-sm text-arka-text-muted mb-6">
                        Configuración de la API de WhatsApp Cloud (Meta), editable acá en vez de tocar el <code>.env</code>
                        cada vez. Si deja un campo en blanco, se usa lo que ya estaba guardado (para los sensibles) o lo
                        que haya en el <code>.env</code> del servidor (siempre queda como respaldo — nunca se elimina).
                    </p>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="rounded-arka border border-arka-primary/20 bg-arka-primary/5 p-4 space-y-4">
                            <div>
                                <h3 class="font-medium text-arka-text">Operación de carreras por WhatsApp</h3>
                                <p class="mt-1 text-xs text-arka-text-muted">Estos controles no sustituyen la app. Definen qué puede hacerse dentro de una conversación abierta con el número oficial.</p>
                            </div>
                            <label class="flex items-start gap-3">
                                <input v-model="form.ride_notifications_enabled" type="checkbox" class="mt-1 rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary" />
                                <span><strong class="block text-sm text-arka-text">Notificar carreras</strong><small class="text-arka-text-muted">Avisa al conductor cuando el navegador está en segundo plano y existe una ventana de WhatsApp activa.</small></span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input v-model="form.driver_ride_actions_enabled" type="checkbox" class="mt-1 rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary" />
                                <span><strong class="block text-sm text-arka-text">Permitir operar al conductor</strong><small class="text-arka-text-muted">Aceptar, rechazar y comunicarse con el cliente desde WhatsApp. También depende del permiso individual y de su cooperativa.</small></span>
                            </label>
                            <label class="flex items-start gap-3">
                                <input v-model="form.client_ride_booking_enabled" type="checkbox" class="mt-1 rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary" />
                                <span><strong class="block text-sm text-arka-text">Permitir solicitar carreras</strong><small class="text-arka-text-muted">Habilita el onboarding y la solicitud guiada para clientes por WhatsApp.</small></span>
                            </label>
                            <div>
                                <InputLabel value="Aviso de privacidad para el primer uso" />
                                <textarea v-model="form.privacy_notice_text" rows="4" class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text" placeholder="Explique qué datos se usan para gestionar la solicitud y cómo consultar la política completa." />
                                <InputError class="mt-1" :message="form.errors.privacy_notice_text" />
                            </div>
                        </div>
                        <!-- Pedido explícito del usuario: "dame las cantidades de
                             mensajes y ayudame a configurar los modulos que yo
                             active de envios de whatsapp... y coloquemos precios
                             estimados... quiero ver indicadores alli" -->
                        <div class="rounded-arka border border-arka-primary/20 bg-arka-primary/5 p-4 space-y-4">
                            <div>
                                <h3 class="font-medium text-arka-text">Notificaciones por WhatsApp</h3>
                                <p class="mt-1 text-xs text-arka-text-muted">
                                    Active o desactive cada aviso por separado — si lo apaga, esa notificación puntual
                                    deja de mandarse (el resto sigue funcionando igual). Meta cobra por mensaje
                                    enviado; el costo de acá es un estimado editable, no la tarifa oficial.
                                </p>
                            </div>

                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="rounded-arka bg-arka-base/50 p-3 text-center">
                                    <p class="text-xl font-semibold text-arka-text">{{ messageStats.today }}</p>
                                    <p class="text-[11px] text-arka-text-muted">Hoy</p>
                                </div>
                                <div class="rounded-arka bg-arka-base/50 p-3 text-center">
                                    <p class="text-xl font-semibold text-arka-text">{{ messageStats.last_7_days }}</p>
                                    <p class="text-[11px] text-arka-text-muted">Últimos 7 días</p>
                                </div>
                                <div class="rounded-arka bg-arka-base/50 p-3 text-center">
                                    <p class="text-xl font-semibold text-arka-text">{{ messageStats.last_30_days }}</p>
                                    <p class="text-[11px] text-arka-text-muted">${{ messageStats.estimated_cost_last_30_days.toFixed(4) }} · 30 días</p>
                                </div>
                                <div class="rounded-arka bg-arka-base/50 p-3 text-center">
                                    <p class="text-xl font-semibold text-arka-text">{{ messageStats.all_time }}</p>
                                    <p class="text-[11px] text-arka-text-muted">${{ messageStats.estimated_cost_all_time.toFixed(4) }} · histórico</p>
                                </div>
                            </div>

                            <div>
                                <InputLabel for="estimated_cost_per_message" value="Costo estimado por mensaje (USD)" />
                                <TextInput
                                    id="estimated_cost_per_message"
                                    type="number"
                                    step="0.0001"
                                    min="0"
                                    class="mt-1 block w-full sm:w-40"
                                    v-model="form.estimated_cost_per_message"
                                />
                                <InputError class="mt-1" :message="form.errors.estimated_cost_per_message" />
                            </div>

                            <div v-for="group in notificationGroups" :key="group.key">
                                <p class="text-sm font-medium text-arka-text mb-2">{{ group.label }}</p>
                                <ul class="divide-y divide-arka-text-muted/10">
                                    <li v-for="type in group.items" :key="type.key" class="py-2.5 flex items-center justify-between gap-3">
                                        <label class="flex items-center gap-3 min-w-0 flex-1">
                                            <input
                                                v-model="form[`notify_${type.key}`]"
                                                type="checkbox"
                                                class="rounded border-arka-text-muted/30 text-arka-primary focus:ring-arka-primary shrink-0"
                                            />
                                            <span class="text-sm text-arka-text truncate">{{ type.label }}</span>
                                        </label>
                                        <span class="shrink-0 text-right text-xs text-arka-text-muted">
                                            {{ type.count_last_30_days }} msj · ${{ type.estimated_cost_last_30_days.toFixed(3) }}
                                            <span class="block text-[10px]">últimos 30 días</span>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <InputLabel for="token" value="Token de acceso" />
                                <span class="text-xs" :class="statusFor(settings.has_token, envFallback.has_token).class">
                                    {{ statusFor(settings.has_token, envFallback.has_token).label }}
                                </span>
                            </div>
                            <TextInput
                                id="token"
                                type="password"
                                class="mt-1 block w-full"
                                v-model="form.token"
                                placeholder="Dejar en blanco para no cambiarlo"
                                autocomplete="off"
                            />
                            <InputError class="mt-1" :message="form.errors.token" />
                        </div>

                        <div>
                            <InputLabel for="phone_number_id" value="ID del número de teléfono (Meta)" />
                            <TextInput id="phone_number_id" type="text" class="mt-1 block w-full" v-model="form.phone_number_id" />
                            <InputError class="mt-1" :message="form.errors.phone_number_id" />
                        </div>

                        <div>
                            <InputLabel for="verification_template" value="Plantilla de verificación de teléfono" />
                            <TextInput id="verification_template" type="text" class="mt-1 block w-full" v-model="form.verification_template" />
                            <InputError class="mt-1" :message="form.errors.verification_template" />
                        </div>

                        <div>
                            <InputLabel for="business_number" value="Número de WhatsApp del negocio (para los links wa.me)" />
                            <TextInput id="business_number" type="text" class="mt-1 block w-full" v-model="form.business_number" placeholder="Ej. 593991234567" />
                            <InputError class="mt-1" :message="form.errors.business_number" />
                        </div>

                        <div class="pt-2 border-t border-arka-text-muted/10">
                            <div class="flex items-center justify-between">
                                <InputLabel for="webhook_verify_token" value="Token de verificación del webhook" />
                                <span class="text-xs" :class="statusFor(settings.has_webhook_verify_token, envFallback.has_webhook_verify_token).class">
                                    {{ statusFor(settings.has_webhook_verify_token, envFallback.has_webhook_verify_token).label }}
                                </span>
                            </div>
                            <TextInput
                                id="webhook_verify_token"
                                type="password"
                                class="mt-1 block w-full"
                                v-model="form.webhook_verify_token"
                                placeholder="Dejar en blanco para no cambiarlo"
                                autocomplete="off"
                            />
                            <InputError class="mt-1" :message="form.errors.webhook_verify_token" />
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <InputLabel for="app_secret" value="App Secret (firma del webhook)" />
                                <span class="text-xs" :class="statusFor(settings.has_app_secret, envFallback.has_app_secret).class">
                                    {{ statusFor(settings.has_app_secret, envFallback.has_app_secret).label }}
                                </span>
                            </div>
                            <TextInput
                                id="app_secret"
                                type="password"
                                class="mt-1 block w-full"
                                v-model="form.app_secret"
                                placeholder="Dejar en blanco para no cambiarlo"
                                autocomplete="off"
                            />
                            <InputError class="mt-1" :message="form.errors.app_secret" />
                        </div>

                        <p v-if="settings.updated_at" class="text-xs text-arka-text-muted">
                            Último cambio: {{ new Date(settings.updated_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                            <span v-if="settings.updated_by_name"> — {{ settings.updated_by_name }}</span>
                        </p>

                        <div class="flex items-center gap-4">
                            <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                            <Transition
                                enter-active-class="transition ease-in-out"
                                enter-from-class="opacity-0"
                                leave-active-class="transition ease-in-out"
                                leave-to-class="opacity-0"
                            >
                                <p v-if="form.recentlySuccessful" class="text-sm text-arka-text-muted">Guardado.</p>
                            </Transition>
                        </div>
                    </form>
                </div>

                <!-- Auditoría (sección 18): quién cambió qué y cuándo — nunca el
                     valor real de un campo sensible, solo si cambió o no. -->
                <div class="p-4 sm:p-6 bg-arka-card shadow rounded-arka">
                    <h3 class="text-lg font-medium text-arka-text mb-4">Historial de cambios</h3>

                    <p v-if="!auditLogs.length" class="text-sm text-arka-text-muted">Todavía no hay cambios registrados.</p>

                    <ul v-else class="divide-y divide-arka-text-muted/10">
                        <li v-for="log in auditLogs" :key="log.id" class="py-3 text-sm">
                            <div class="flex items-center gap-2">
                                <UserAvatar :user="log.admin" size-class="h-6 w-6 text-[10px] shrink-0" />
                                <span class="text-arka-text font-medium">{{ log.admin.name }}</span>
                                <span class="text-arka-text-muted">
                                    — {{ new Date(log.created_at).toLocaleString('es-EC', { dateStyle: 'medium', timeStyle: 'short' }) }}
                                </span>
                            </div>
                            <ul class="mt-1 ms-8 text-xs text-arka-text-muted space-y-0.5">
                                <li v-for="(value, key) in log.new_value" :key="key">
                                    <span class="font-mono">{{ key }}</span>:
                                    {{ log.old_value?.[key] ?? '(vacío)' }} → {{ value ?? '(vacío)' }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
