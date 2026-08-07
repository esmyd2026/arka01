<script setup>
import LegalLayout from '@/Components/LegalLayout.vue';

defineProps({
    contactEmail: { type: String, default: null },
    updatedAt: { type: String, required: true },
});
</script>

<template>
    <LegalLayout title="Política de privacidad" :updated-at="updatedAt">
        <p>
            Esta política explica qué datos recolecta Arka01, para qué los usa y qué opciones tiene sobre ellos.
        </p>

        <section>
            <h2>1. Qué datos recolectamos</h2>
            <ul>
                <li><strong>Datos de cuenta:</strong> nombre, correo, teléfono, ciudad.</li>
                <li><strong>Ubicación:</strong> solo mientras un conductor está "disponible" o durante un viaje en curso — para mostrarlo en el mapa y calcular distancias.</li>
                <li><strong>Datos de conductor:</strong> licencia, datos del vehículo, fotos de verificación.</li>
                <li><strong>Historial de viajes y calificaciones.</strong></li>
                <li><strong>Mensajes de WhatsApp:</strong> usamos la API oficial de WhatsApp Business para verificarle el teléfono y avisarle de carreras nuevas — no leemos ni guardamos el contenido de conversaciones fuera de eso.</li>
                <li><strong>Comprobantes de pago:</strong> si contrata un plan pago, la imagen que sube para que un admin la revise.</li>
                <li><strong>Contactos de confianza:</strong> si carga uno para el botón SOS, guardamos el nombre y la forma de contactarlo que usted defina.</li>
            </ul>
        </section>

        <section>
            <h2>2. Para qué los usamos</h2>
            <ul>
                <li>Conectarlo con conductores/clientes de su flota y del directorio público.</li>
                <li>Mostrar viajes disponibles y calcular distancias/tarifas de referencia.</li>
                <li>Mandarle avisos importantes (carrera nueva, cambio de estado, vencimiento de su ventana de WhatsApp) por notificación push o WhatsApp.</li>
                <li>Revisar manualmente verificaciones de conductor y comprobantes de pago.</li>
                <li>Mejorar la seguridad de la plataforma (ej. detectar cuentas duplicadas por el mismo número de teléfono).</li>
            </ul>
        </section>

        <section>
            <h2>3. Con quién se comparte</h2>
            <p>
                Su nombre, foto, calificación, teléfono y — mientras está en un viaje — su ubicación en vivo son
                visibles para la otra parte del viaje (cliente o conductor), para poder coordinar el encuentro. Un
                contacto de confianza que agregue puede recibir un link de seguimiento en vivo si activa el botón
                SOS. No vendemos sus datos a terceros. Arka01 no controla ni es responsable del uso que la otra
                parte le dé a los datos de contacto una vez coordinado el viaje (ej. si le vuelve a escribir fuera de
                la app) — esa comunicación queda entre cliente y conductor.
            </p>
        </section>

        <section>
            <h2>4. Comunicaciones oficiales y seguridad</h2>
            <p>
                Nuestro único dominio oficial es <strong>arka01.com</strong>, y el correo de Arka01 siempre termina
                en ese dominio. Nunca le vamos a pedir su contraseña por WhatsApp, correo o llamada — si recibe un
                mensaje sospechoso pidiéndole datos o dinero "de parte de Arka01" desde otro dominio o número,
                repórtelo y no responda.
            </p>
        </section>

        <section>
            <h2>5. Dónde se guardan</h2>
            <p>
                Los datos se guardan en la base de datos de Arka01. Las fotos (licencia, vehículo, comprobantes) se
                guardan en el almacenamiento de archivos de la plataforma.
            </p>
        </section>

        <section>
            <h2>6. Sus opciones</h2>
            <ul>
                <li>Puede editar sus datos de perfil (incluido su número de WhatsApp) en cualquier momento.</li>
                <li>Puede desactivar su disponibilidad como conductor para dejar de compartir ubicación.</li>
                <li>Puede eliminar su cuenta desde su perfil — esto borra sus datos personales de la plataforma.</li>
            </ul>
        </section>

        <section>
            <h2>7. Contacto</h2>
            <p>
                ¿Preguntas sobre sus datos?
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">Escríbanos a {{ contactEmail }}</a>
                <span v-else>Escríbanos por los canales de contacto de la app.</span>
            </p>
        </section>

        <p class="text-xs italic pt-4 border-t border-arka-text-muted/10">
            Esta política es una base de referencia y no reemplaza asesoría legal — antes de un lanzamiento con
            usuarios reales, conviene que un abogado la revise contra la normativa ecuatoriana de protección de
            datos personales vigente.
        </p>
    </LegalLayout>
</template>
