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
            Esta política explica qué datos recolecta Arka01, para qué los usa, con quién los comparte, dónde se
            guardan y por cuánto tiempo, y qué opciones y derechos tiene usted sobre ellos. Arka01 es
            <strong>únicamente un prestador de servicios de software</strong> — el responsable del tratamiento de
            los datos que usted nos entrega es Arka01, conforme a la Ley Orgánica de Protección de Datos Personales
            del Ecuador (LOPDP) y demás normativa aplicable.
        </p>

        <section>
            <h2>1. Qué datos recolectamos</h2>
            <ul>
                <li><strong>Datos de cuenta:</strong> nombre, correo, teléfono, ciudad y sector, usuario y código de socio autogenerados.</li>
                <li><strong>Ubicación:</strong> solo mientras un conductor está "disponible" o durante un viaje en curso — para mostrarlo en el mapa, calcular distancias y armar la bitácora de ese viaje.</li>
                <li><strong>Datos de conductor:</strong> licencia, datos del vehículo (placa, capacidad), fotos de verificación.</li>
                <li><strong>Historial de viajes, calificaciones y puntos de fidelización.</strong></li>
                <li><strong>Mensajes de WhatsApp:</strong> usamos la API oficial de WhatsApp Business (Meta) para verificarle el teléfono y avisarle de carreras nuevas — no leemos ni guardamos el contenido de conversaciones fuera de eso.</li>
                <li><strong>Comprobantes de pago:</strong> si contrata un plan pago, la imagen que sube para que un admin la revise.</li>
                <li><strong>Contactos de confianza:</strong> si carga uno para el botón SOS, guardamos el nombre y la forma de contactarlo que usted defina.</li>
                <li><strong>Datos técnicos básicos:</strong> dirección IP, tipo de dispositivo y navegador — solo para seguridad (ej. detectar accesos indebidos) y diagnóstico de errores, no para perfilarlo comercialmente.</li>
            </ul>
        </section>

        <section>
            <h2>2. Base legal y principios que aplicamos</h2>
            <p>
                Tratamos sus datos porque son necesarios para prestarle el servicio que usted contrató al crear su
                cuenta (ejecución del contrato de uso de Arka01), y porque usted da su consentimiento expreso al
                registrarse. Aplicamos los principios de la LOPDP: recolectamos solo lo que hace falta para que la
                app funcione (minimización), lo usamos únicamente para las finalidades descritas acá (finalidad), y
                le informamos con claridad qué hacemos con sus datos (transparencia).
            </p>
        </section>

        <section>
            <h2>3. Para qué los usamos</h2>
            <ul>
                <li>Conectarlo con conductores/clientes de su flota y del directorio público.</li>
                <li>Mostrar viajes, Expresos y Rutas y Turismo disponibles, y calcular distancias/tarifas de referencia.</li>
                <li>Mandarle avisos importantes (carrera nueva, cambio de estado, vencimiento de su ventana de WhatsApp, novedades de su plan) por notificación push o WhatsApp.</li>
                <li>Revisar manualmente verificaciones de conductor y comprobantes de pago.</li>
                <li>Calcular su progreso de puntos y nivel (gamificación) en el panel de indicadores.</li>
                <li>Mejorar la seguridad de la plataforma (ej. detectar cuentas duplicadas por el mismo número de teléfono, o accesos desde un dispositivo no reconocido).</li>
            </ul>
        </section>

        <section>
            <h2>4. Con quién se comparte</h2>
            <p>
                <strong>Arka01 no vende sus datos a nadie, nunca.</strong> Los compartimos, en la mínima medida
                necesaria, con:
            </p>
            <ul>
                <li>
                    <strong>La otra parte de un viaje:</strong> su nombre, foto, calificación, teléfono y — mientras
                    dura el viaje — su ubicación en vivo son visibles para el cliente o conductor con quien está
                    coordinando, para poder encontrarse. Arka01 no controla ni es responsable del uso que la otra
                    parte le dé a esos datos una vez coordinado el viaje (ej. si le vuelve a escribir fuera de la
                    app) — esa comunicación queda entre cliente y conductor.
                </li>
                <li>
                    <strong>Proveedores que procesan datos por encargo nuestro</strong> (encargados de tratamiento),
                    solo con la información mínima que su función necesita: Meta/WhatsApp Business (su teléfono, para
                    verificarlo y enviarle avisos), Google (si inicia sesión con Google, o al usar el buscador de
                    direcciones de Google Places), OpenStreetMap/Nominatim y el servicio OSRM (las coordenadas de
                    origen/destino que usted ingresa, para geocodificar direcciones y trazar rutas — sin datos que lo
                    identifiquen), y nuestro proveedor de correo saliente (su correo, para enviarle notificaciones
                    transaccionales como el correo de bienvenida). Cada uno de estos aplica su propia política de
                    privacidad.
                </li>
                <li>
                    <strong>Empresas aliadas de cupones, beneficios y banners publicitarios:</strong> estas
                    promociones se le muestran <em>dentro</em> de la app, pero la empresa aliada
                    <strong>no recibe sus datos personales</strong> por que usted vea su anuncio o cupón — Arka01 no
                    les entrega su nombre, teléfono, ubicación ni ningún otro dato de su cuenta.
                </li>
                <li>
                    <strong>Autoridades competentes:</strong> únicamente si la ley ecuatoriana nos obliga a
                    entregarlos (ej. un requerimiento judicial).
                </li>
            </ul>
        </section>

        <section>
            <h2>5. Transferencia internacional de datos</h2>
            <p>
                Algunos de los proveedores mencionados en la sección anterior (Meta/WhatsApp, Google) procesan
                información en servidores fuera del Ecuador. Cuando esto ocurre, exigimos que ese tratamiento se
                haga bajo estándares de protección adecuados (cifrado, acceso restringido) conforme a lo que permite
                la LOPDP para transferencias internacionales de datos personales.
            </p>
        </section>

        <section>
            <h2>6. Comunicaciones oficiales y seguridad</h2>
            <p>
                Nuestro único dominio oficial es <strong>arka01.com</strong>, y el correo de Arka01 siempre termina
                en ese dominio. Nunca le vamos a pedir su contraseña por WhatsApp, correo o llamada — si recibe un
                mensaje sospechoso pidiéndole datos o dinero "de parte de Arka01" desde otro dominio o número,
                repórtelo y no responda.
            </p>
        </section>

        <section>
            <h2>7. Dónde se guardan y por cuánto tiempo</h2>
            <p>
                Los datos se guardan en la base de datos de Arka01 y en almacenamiento de archivos (fotos de
                licencia, vehículo y comprobantes de pago), alojados en infraestructura en la nube contratada con
                proveedores que aplican medidas de seguridad estándar de la industria: cifrado en tránsito (HTTPS),
                controles de acceso, y acceso restringido a documentos sensibles (comprobantes de pago y documentos
                de verificación) solo para el dueño del dato y el equipo administrador. El proveedor y la región
                específica de hospedaje se confirman antes de cada despliegue a producción y se mantienen
                actualizados en esta página.
            </p>
            <p>Conservamos cada tipo de dato mientras tenga una finalidad, y como máximo:</p>
            <ul>
                <li><strong>Datos de cuenta e historial de viajes:</strong> mientras su cuenta esté activa. Si la elimina, se borran sus datos personales identificables; el historial de viajes puede conservarse de forma anonimizada (sin datos que lo identifiquen) para estadísticas de la plataforma.</li>
                <li><strong>Comprobantes de pago:</strong> hasta 7 años, por la obligación de conservar respaldos contables y tributarios que exige la normativa ecuatoriana (Código Tributario / SRI).</li>
                <li><strong>Ubicación en vivo:</strong> no se conserva como historial de recorrido más allá de lo necesario para el viaje y su bitácora — no se guarda un rastreo continuo de su ubicación fuera de un viaje en curso.</li>
                <li><strong>Mensajes/estado de verificación de WhatsApp:</strong> mientras la cuenta esté activa, conforme a la ventana de conversación que administra la API de WhatsApp Business.</li>
                <li><strong>Datos técnicos de seguridad (IP, dispositivo):</strong> hasta 12 meses, salvo que formen parte de una investigación de seguridad en curso.</li>
            </ul>
        </section>

        <section>
            <h2>8. Seguridad de la información</h2>
            <ul>
                <li>Las contraseñas se guardan cifradas (hash), nunca en texto plano.</li>
                <li>Las conexiones a Arka01 viajan cifradas por HTTPS.</li>
                <li>Los documentos sensibles (comprobantes de pago, licencia, fotos de vehículo) se guardan en almacenamiento privado, accesible solo por su dueño o un administrador — no son de acceso público.</li>
                <li>Restringimos el acceso interno a datos personales al personal que efectivamente lo necesita para operar la plataforma.</li>
            </ul>
        </section>

        <section>
            <h2>9. Sus derechos sobre sus datos</h2>
            <p>
                Conforme a la LOPDP, usted tiene derecho a: <strong>acceder</strong> a los datos que tenemos sobre
                usted, <strong>rectificarlos</strong> si están incompletos o desactualizados, <strong>eliminarlos
                (cancelación)</strong>, <strong>oponerse</strong> a un uso puntual, y <strong>revocar su
                consentimiento</strong> en cualquier momento. En la práctica, dentro de la app puede:
            </p>
            <ul>
                <li>Editar sus datos de perfil (incluido su número de WhatsApp) en cualquier momento.</li>
                <li>Desactivar su disponibilidad como conductor para dejar de compartir ubicación.</li>
                <li>Eliminar su cuenta desde su perfil — esto borra sus datos personales de la plataforma, sujeto a las excepciones de conservación de la sección 7 (ej. comprobantes de pago por obligación tributaria).</li>
            </ul>
            <p>
                Para cualquier otro pedido relacionado con sus derechos (ej. una copia de sus datos), escríbanos por
                el canal de contacto de la sección 10. Si considera que no atendimos su pedido correctamente, puede
                presentar un reclamo ante la <strong>Superintendencia de Protección de Datos Personales del
                Ecuador</strong>, la autoridad de control en esta materia.
            </p>
        </section>

        <section>
            <h2>10. Menores de edad</h2>
            <p>
                Arka01 no está dirigido a menores de edad y no permite crear cuentas a personas menores de 18 años
                (ver Términos y condiciones, sección 2). No recolectamos deliberadamente datos de menores; si
                detectamos una cuenta de un menor, la eliminamos.
            </p>
        </section>

        <section>
            <h2>11. Contacto</h2>
            <p>
                ¿Preguntas sobre sus datos o quiere ejercer alguno de los derechos de la sección 9?
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">Escríbanos a {{ contactEmail }}</a>
                <span v-else>Escríbanos por los canales de contacto de la app.</span>
            </p>
        </section>
    </LegalLayout>
</template>
