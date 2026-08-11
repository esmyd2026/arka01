<script setup>
import LegalLayout from '@/Components/LegalLayout.vue';

defineProps({
    contactEmail: { type: String, default: null },
    updatedAt: { type: String, required: true },
});
</script>

<template>
    <LegalLayout title="Términos y condiciones" :updated-at="updatedAt">
        <p>
            Estos términos rigen el uso de Arka01, una app que le permite armar su propia flota de conductores de
            confianza y coordinar viajes dentro de ese círculo, además de acceder a un directorio público de
            conductores como red de respaldo. Al crear una cuenta —sea cliente o conductor— acepta lo siguiente.
        </p>

        <section>
            <h2>1. Qué es Arka01 (y qué no es)</h2>
            <p>
                Arka01 es <strong>únicamente una plataforma de tecnología</strong> que pone en contacto a clientes y
                conductores independientes — presta un servicio de intermediación por software, no un servicio de
                transporte. Arka01 <strong>no es una empresa de transporte, no es transportista, no es empleador de
                ningún conductor, no es agencia de viajes ni operador turístico, y no es parte del contrato de
                transporte</strong> que se pacta directamente entre cliente y conductor en cada viaje (incluidos los
                Expresos y las Rutas y Turismo). Cada conductor es un tercero independiente, responsable de contar con
                licencia de conducir vigente, matrícula, SOAT u otro seguro vehicular vigente, y todos los permisos
                que exige la normativa ecuatoriana de tránsito para transportar pasajeros.
            </p>
            <p>
                Arka01 <strong>no fija tarifas obligatorias, no organiza ni ejecuta ningún viaje, no despacha
                vehículos ni conductores, y no supervisa ni garantiza</strong> la disponibilidad, puntualidad,
                calidad, seguridad o legalidad del transporte que un conductor le brinda a un cliente. El precio que
                muestra la app es siempre un valor de referencia; el trayecto, el vehículo, el estado de este y la
                forma de conducir dependen exclusivamente del conductor. En síntesis: Arka01 <strong>es un prestador
                de servicios de software, nada más</strong> — no responde por lo que ocurra durante un viaje, ver
                sección 9.
            </p>
        </section>

        <section>
            <h2>2. Su cuenta</h2>
            <ul>
                <li>Cada cuenta es de cliente o de conductor, nunca las dos cosas a la vez.</li>
                <li>Es responsable de la información que declara (nombre, teléfono, datos del vehículo si es conductor) y de mantenerla correcta y verdadera.</li>
                <li>El teléfono se verifica por WhatsApp — es el mismo número al que le vamos a escribir para avisos importantes (carreras nuevas, cambios de estado).</li>
                <li>No puede compartir su cuenta, cederla, ni hacerse pasar por otra persona.</li>
                <li>Tiene que ser mayor de edad para crear una cuenta en Arka01.</li>
            </ul>
        </section>

        <section>
            <h2>3. Obligaciones del conductor</h2>
            <p>Si activa su perfil de conductor, además de lo anterior se compromete a:</p>
            <ul>
                <li>Mantener vigente su licencia de conducir, la matrícula del vehículo y el seguro (SOAT u otro) exigido por la ley.</li>
                <li>Circular en condiciones de seguridad: vehículo en buen estado, respetando las normas de tránsito y sin conducir bajo efectos de alcohol o sustancias.</li>
                <li>Brindar un trato respetuoso al cliente y a terceros durante el viaje.</li>
                <li>Cumplir con las condiciones pactadas (precio, ruta, horario) y avisar por la app ante cualquier cambio o imprevisto.</li>
                <li>Responder por las obligaciones tributarias y legales que correspondan a su actividad como conductor independiente.</li>
            </ul>
        </section>

        <section>
            <h2>4. Obligaciones del cliente</h2>
            <ul>
                <li>Pagar el monto pactado con el conductor al finalizar el viaje, por el medio acordado.</li>
                <li>Dar un trato respetuoso al conductor y cuidar el vehículo durante el viaje.</li>
                <li>No usar la plataforma para solicitar viajes con fines ilegales o para poner en riesgo al conductor.</li>
                <li>Brindar datos de ubicación (origen/destino) reales y precisos al pedir una carrera.</li>
            </ul>
        </section>

        <section>
            <h2>5. Viajes, precios y calificaciones</h2>
            <p>
                El precio de cada viaje lo negocian cliente y conductor dentro de la app (Arka01 solo sugiere un
                valor de referencia, que no es vinculante para ninguna de las partes). Una vez completado el viaje,
                ambas partes pueden calificarse — las calificaciones son de buena fe y reflejan la experiencia real
                del viaje; usarlas para difamar o extorsionar a la otra parte está prohibido.
            </p>
        </section>

        <section>
            <h2>6. Ubicación en vivo</h2>
            <p>
                Para que un cliente pueda ver a sus conductores disponibles y seguir un viaje en curso, un conductor
                que se pone "disponible" comparte su ubicación en tiempo real con la app mientras dura esa
                disponibilidad. Puede desactivarla en cualquier momento desde su perfil.
            </p>
        </section>

        <section>
            <h2>7. Suscripciones y pagos a Arka01</h2>
            <p>
                Los planes pagos se activan subiendo un comprobante de pago, que un administrador revisa antes de
                aprobarlo. Un plan nunca baja de nivel solo — si algo cambia, un admin lo revisa a mano. Estos pagos
                son por el uso de la plataforma (funciones, cupos, visibilidad) — no son parte del cobro del viaje en
                sí, que se paga directo entre cliente y conductor.
            </p>
        </section>

        <section>
            <h2>8. Verificación de conductores</h2>
            <p>
                Un conductor puede subir fotos de su licencia y de su vehículo para obtener la insignia de
                verificado. Arka01 revisa que las fotos sean legibles y correspondan a lo declarado, pero esta
                revisión es documental y <strong>no constituye una garantía de idoneidad, buena conducta, ni de la
                autenticidad de los documentos</strong> del conductor — cada cliente decide, bajo su propio criterio,
                con quién viaja.
            </p>
        </section>

        <section>
            <h2>9. Servicios de terceros integrados</h2>
            <p>
                Para funcionar, Arka01 se apoya en servicios de terceros ajenos a nuestro control: la API de
                WhatsApp Business de Meta (verificación y avisos), servicios de mapas y ubicación de Google y de
                OpenStreetMap (buscar direcciones, trazar rutas), y proveedores de correo e infraestructura en la
                nube. Estos servicios tienen sus propios términos y pueden tener interrupciones, demoras o cambios
                fuera de nuestro control. Arka01 <strong>no es responsable por fallas, indisponibilidad o cambios en
                estos servicios de terceros</strong>, aunque hará lo razonable para avisar y mitigar el impacto. Las
                promociones o beneficios de empresas aliadas que se muestran dentro de la app (sección de cupones y
                banners) son responsabilidad exclusiva de cada empresa aliada — Arka01 solo las exhibe, no las
                administra, garantiza ni participa en la relación comercial que resulte de ellas.
            </p>
        </section>

        <section>
            <h2>10. Limitación de responsabilidad</h2>
            <p>
                Arka01 presta exclusivamente un servicio de software de intermediación —
                <strong>es un prestador de servicios de software y nada más</strong>: no es responsable por
                terceros, ni por sus actos ni por sus omisiones. <strong>En la máxima medida permitida por la ley
                ecuatoriana, Arka01 no es responsable por</strong>: accidentes de tránsito; daños personales,
                materiales o morales; robos o pérdida de objetos; conducta indebida de cualquiera de las partes
                (cliente o conductor); incumplimientos del contrato de transporte pactado entre ambos; retrasos,
                cancelaciones o la calidad del servicio de transporte brindado (incluidos Expresos y Rutas y Turismo);
                disputas de pago entre cliente y conductor sobre el precio del viaje; ni por el contenido, la
                veracidad o el resultado de promociones de empresas aliadas mostradas en la app. El uso de la
                plataforma para contratar o brindar transporte se hace bajo el propio riesgo de cada usuario.
                Recomendamos siempre revisar la calificación e insignia de verificación antes de viajar, y usar el
                botón SOS y los contactos de confianza (sección 8 de la app) ante cualquier emergencia.
            </p>
            <p>
                En los casos donde, pese a lo anterior, corresponda alguna responsabilidad de Arka01 conforme a la
                ley aplicable, esta queda limitada al daño directo efectivamente comprobado y, en ningún caso, a un
                monto mayor al que el usuario afectado haya pagado a Arka01 por su suscripción en los últimos tres
                meses.
            </p>
        </section>

        <section>
            <h2>11. Indemnidad</h2>
            <p>
                Se compromete a mantener indemne a Arka01, sus administradores y colaboradores frente a cualquier
                reclamo, multa o gasto (incluyendo honorarios legales razonables) que surja de: su incumplimiento de
                estos términos, su incumplimiento de la ley aplicable a su actividad (como conductor o como
                cliente), o de su conducta durante el uso de la plataforma o de un viaje.
            </p>
        </section>

        <section>
            <h2>12. Uso aceptable y suspensión de cuentas</h2>
            <ul>
                <li>No está permitido usar Arka01 para actividades ilegales, acoso, fraude, o para poner en riesgo a otros usuarios.</li>
                <li>Nos reservamos el derecho de suspender o eliminar una cuenta que incumpla estos términos, que reciba reportes fundados de mal comportamiento, o cuya documentación resulte falsa.</li>
                <li>No hay ningún derecho adquirido a mantener una cuenta activa indefinidamente — el acceso a Arka01 es una facultad que otorgamos mientras se cumplan estos términos.</li>
            </ul>
        </section>

        <section>
            <h2>13. Ley aplicable</h2>
            <p>
                Estos términos se rigen por las leyes de la República del Ecuador. Cualquier controversia derivada
                del uso de la plataforma se someterá a los jueces y tribunales competentes del Ecuador, sin
                perjuicio de que cliente y conductor puedan resolver directamente entre ellos cualquier disputa
                relacionada con el contrato de transporte que pactaron.
            </p>
        </section>

        <section>
            <h2>14. Cambios a estos términos</h2>
            <p>
                Podemos actualizar estos términos a medida que la app crece. Si el cambio es significativo, se lo
                vamos a avisar dentro de la app.
            </p>
        </section>

        <section>
            <h2>15. Contacto</h2>
            <p>
                ¿Dudas sobre estos términos?
                <a v-if="contactEmail" :href="`mailto:${contactEmail}`">Escríbanos a {{ contactEmail }}</a>
                <span v-else>Escríbanos por los canales de contacto de la app.</span>
            </p>
        </section>
    </LegalLayout>
</template>
