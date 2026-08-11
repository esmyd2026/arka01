<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import InputLabel from '@/Components/InputLabel.vue';
import InputError from '@/Components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    settings: { type: Object, required: true },
});

const form = useForm({
    welcome_message: props.settings.welcome_message,
    help_message: props.settings.help_message,
    fallback_message: props.settings.fallback_message,
    fallback_escalation_message: props.settings.fallback_escalation_message,
    farewell_message: props.settings.farewell_message,
    max_fallback_attempts: props.settings.max_fallback_attempts,
});

function submit() {
    form.patch(route('admin.chatbot.settings.update'), { preserveScroll: true });
}
</script>

<template>
    <Head title="Admin · Chatbot · Mensajes generales" />

    <AdminLayout title="Chatbot">
        <div class="py-12">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                <div class="flex items-center gap-4 text-sm">
                    <Link :href="route('admin.chatbot.intents.index')" class="text-arka-text-muted hover:text-arka-text">
                        Intenciones
                    </Link>
                    <span class="font-medium text-arka-primary-bright">Mensajes generales</span>
                    <Link :href="route('admin.chatbot.unrecognized.index')" class="text-arka-text-muted hover:text-arka-text">
                        Consultas no reconocidas
                    </Link>
                </div>

                <form @submit.prevent="submit" class="p-4 sm:p-6 bg-arka-card shadow rounded-arka space-y-4">
                    <div>
                        <InputLabel value="Mensaje de bienvenida (saludo + menú principal)" />
                        <textarea
                            v-model="form.welcome_message"
                            rows="3"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.welcome_message" />
                    </div>

                    <div>
                        <InputLabel value="Mensaje de ayuda" />
                        <textarea
                            v-model="form.help_message"
                            rows="2"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.help_message" />
                    </div>

                    <div>
                        <InputLabel value="Fallback (cuando no entiende el mensaje)" />
                        <textarea
                            v-model="form.fallback_message"
                            rows="2"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.fallback_message" />
                    </div>

                    <div>
                        <InputLabel value="Fallback tras varios intentos (ofrece soporte)" />
                        <textarea
                            v-model="form.fallback_escalation_message"
                            rows="2"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.fallback_escalation_message" />
                    </div>

                    <div>
                        <InputLabel value="Cuántos intentos sin entender antes de ofrecer soporte" />
                        <TextInput type="number" min="1" max="5" class="mt-1 block w-32" v-model.number="form.max_fallback_attempts" />
                        <InputError class="mt-1" :message="form.errors.max_fallback_attempts" />
                    </div>

                    <div>
                        <InputLabel value="Mensaje de despedida" />
                        <textarea
                            v-model="form.farewell_message"
                            rows="2"
                            class="mt-1 block w-full rounded-arka border-arka-text-muted/20 bg-transparent text-arka-text"
                        ></textarea>
                        <InputError class="mt-1" :message="form.errors.farewell_message" />
                    </div>

                    <PrimaryButton :disabled="form.processing">Guardar</PrimaryButton>
                </form>
            </div>
        </div>
    </AdminLayout>
</template>
