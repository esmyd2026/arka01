# Arka01 — Alcance del proyecto

### *"Solo suben los tuyos."*

**Documento de referencia para construir la plataforma.** Reúne todas las definiciones de producto, negocio, arquitectura y costos acordadas hasta ahora. Pensado para que sirva de guía al desarrollarlo y para que sea fácil de escalar y de seguir editando.

Ecuador · Julio 2026 · Versión de este documento: consolidada a partir de las iteraciones del concepto (v1 a v4 + módulo de costos).

---

## 0. Resumen ejecutivo

Arka01 es una plataforma de movilidad que invierte el modelo tradicional de las apps de transporte (Uber, Cabify, Didi, InDrive). En lugar de que el sistema asigne un conductor desconocido, cada usuario (el **cliente**) construye su propia **flota**: una lista de conductores de confianza —un taxista vecino, un conductor que ya le hizo una carrera antes, alguien que sabe que trabaja en transporte— buscándolos dentro de la plataforma como en una red social. Solicita el viaje dentro de ese círculo de confianza y, si nadie está disponible, puede recurrir a conductores públicos.

Puntos que definen el proyecto:

- La **flota es del cliente**, no del conductor. El conductor "pertenece" (o no) a la flota de uno o varios clientes.
- El pago, **por ahora**, es directo entre cliente y conductor: efectivo o transferencia, **sin comisión de la plataforma**.
- El conductor se financia con una **suscripción mensual** según a cuántos clientes de confianza puede pertenecer y si quiere visibilidad pública. El cliente, si lo necesita, también paga un plan para tener una flota más grande o crear varias.
- Existe una modalidad de **Expresos**: rutas fijas y recurrentes para gente con horario fijo de entrada/salida, con condiciones pactadas (aire acondicionado, puntualidad, etc.), pensada también para empresas y universidades.
- Una **billetera virtual** con comisión del 5% queda **documentada pero fuera del alcance inicial**, por la complejidad regulatoria (Ley Fintech ecuatoriana). Se retoma más adelante.
- Se prioriza mantener costos bajos: web-first con PWA en vez de apps nativas, sin pasarela de pago para las carreras.

---

## 1. El problema que resuelve

- **Inseguridad y desconfianza.** Muchos usuarios prefieren un conductor conocido antes que uno asignado al azar, especialmente de noche o en zonas de riesgo. Hoy no existe una forma estructurada de "guardar" y priorizar a los conductores en los que ya se confía.
- **Comisiones altas para el conductor.** Las plataformas dominantes cobran comisiones que reducen significativamente el ingreso neto por carrera (referencia de mercado: ~25-30% en Uber Ecuador según reportes de prensa, sin cifra oficial confirmada), empujando a trabajar más horas para compensar.
- **Fragmentación de la confianza.** Un buen taxista vecino, un conductor de app que ya lo llevó bien una vez, o un conocido que maneja no tienen forma de quedar conectados con el mismo cliente para futuros viajes: cada carrera empieza de cero.
- **Sin opción para rutas fijas.** Quien tiene un horario fijo de trabajo (turno nocturno, empresa, universidad) no tiene una forma de contratar de antemano un transporte confiable con condiciones claras.
- **Vacío regulatorio cambiante.** El marco legal ecuatoriano para apps de transporte sigue en construcción (ver sección 10), lo que abre espacio para un modelo distinto, centrado en relaciones directas cliente-conductor.

---

## 2. Propuesta de valor

| Para | Qué gana |
|---|---|
| Cliente | Busca conductores como en una red social —por nombre, teléfono, o porque ya lo llevó antes— y arma su flota de confianza, con disponibilidad y cercanía en tiempo real. |
| Conductor | Relaciones repetidas con clientes que ya lo calificaron bien, decide qué invitaciones aceptar, elige su tarifa por km, y solo paga suscripción si quiere ampliar su alcance. |
| Ambos | Perfiles públicos con historial de comentarios y puntuaciones, que sirven de referencia dentro y potencialmente fuera de la plataforma. |
| Rutas fijas | Trabajadores con horario fijo publican su "Expreso" con condiciones y precio; empresas y universidades arman su propia flota (por ejemplo, para turnos nocturnos). |
| Administrador (tú) | Plataforma ligera, web-first, sin apps nativas que mantener, sin pasarela de pago para las carreras, y con costos de infraestructura bajos (ver sección 11). |

---

## 3. Modelo funcional

### 3.1 Roles de usuario

- **Cliente (pasajero).** Persona natural o empresa/universidad. Arma y administra su propia flota de conductores de confianza (el tamaño depende de su plan, sección 7.3). Solicita carreras o publica Expresos, negocia precio, califica al conductor.
- **Conductor.** Puede pertenecer a la flota de varios clientes distintos, hasta el límite de su plan (sección 7.2). Se marca disponible/no disponible, acepta o rechaza invitaciones y solicitudes, fija su tarifa por km, indica qué métodos de pago acepta, y califica al cliente.
- **Conductor público.** Conductor con suscripción que habilita visibilidad pública: aparece en el directorio para que cualquier cliente lo busque y lo agregue sin conocerlo previamente.
- **Organización (universidad/empresa).** Cuenta institucional que administra su propia flota o publica Expresos para varios empleados/estudiantes (turnos nocturnos, por ejemplo). Sus límites de flota se definen a la medida, no siguen la escalera de planes estándar.
- **Administrador de la plataforma.** Gestiona verificación de identidad, disputas, suscripciones y métricas (a futuro, también billeteras).

### 3.2 La flota personal — búsqueda tipo red social e invitación mutua

**Aclaración de términos importante:** la flota es del cliente, no del conductor. El cliente arma su flota; el conductor pertenece (o no) a ella. Cuando se dice que un conductor "puede pertenecer a X flotas", significa que hasta X clientes distintos lo pueden tener agregado como conductor de confianza. Son dos límites separados y dos planes de suscripción distintos (sección 7).

El descubrimiento funciona como en una red social: el cliente busca a la persona, no espera a que el sistema se la asigne. Nadie queda agregado a una flota sin su consentimiento.

- Búsqueda por nombre, número de teléfono, código de invitación o QR — sirve tanto para un taxista vecino que ya conoce, como para un conductor que le hizo una carrera una vez y decide buscarlo después para agregarlo.
- El cliente envía una invitación; el conductor decide aceptar o rechazar. Solo tras aceptar queda vinculado a esa flota.
- Un conductor puede pertenecer a la flota de varios clientes a la vez — es decir, tener varios "clientes de confianza" — hasta el límite de su plan de suscripción (sección 7.2).
- El número de conductores que un cliente puede tener en su propia flota depende de su plan (sección 7.3): el plan gratuito permite hasta 20 conductores en una flota.
- Cualquiera de las dos partes puede terminar la relación cuando quiera: el conductor puede salirse de una flota, y el cliente puede decidir sacar (declinar) a un conductor de la suya.

### 3.3 Ejemplos: por qué un conductor entra o sale de una flota

- **Deja de trabajar de noche:** puede salirse de las flotas de los clientes que se las suelen pedir de noche, en vez de rechazar carrera por carrera.
- **Cambia de zona:** si se muda de barrio, puede salir de las flotas de clientes lejanos y quedarse solo en las de su nueva zona.
- **Suma un cliente nuevo:** le hizo una carrera pública a alguien y quedó bien; ese cliente lo busca después y lo invita a su flota — el conductor decide si acepta.
- **El cliente cambia de conductor:** si el taxista vecino de siempre se muda o deja de manejar, el cliente simplemente lo saca de su flota, sin necesidad de "bloquearlo".

### 3.4 Conductores públicos (red de respaldo)

Cuando ningún conductor de la flota personal está disponible, el cliente puede recurrir al directorio público de conductores con visibilidad activada (plan Plus del conductor o superior, sección 7.2), filtrable por cercanía y calificación. Si la experiencia es buena, lo natural es que termine en una invitación formal a la flota.

### 3.5 Solicitud de una carrera

Flujo: **ver flota disponible → elegir conductor o "toda la flota" → negociar precio → conductor acepta → viaje + pago → calificación mutua.**

- El cliente abre su flota y ve, en tiempo real, quién está disponible y a qué distancia/tiempo estimado se encuentra cada uno.
- Puede solicitar directamente a un conductor específico, o lanzar la solicitud a "toda la flota disponible" para que el primero en aceptar tome la carrera.
- Si nadie de la flota acepta en un tiempo configurable, el sistema ofrece ampliar la búsqueda al directorio público cercano.
- El precio se negocia antes de confirmar (sección 5); el conductor ve origen, destino aproximado, precio propuesto y la calificación del cliente antes de aceptar, y puede rechazar sin penalización severa.

### 3.6 Perfil, métodos de pago y reputación bidireccional

- Cada cliente y cada conductor tiene un perfil público con su historial de puntuaciones y **comentarios de texto** de la otra parte (no solo un número).
- El perfil del conductor indica qué **métodos de pago acepta**: transferencia, efectivo, o ambos — visible antes de solicitar o negociar una carrera. Cuando se active la billetera virtual (sección 6, a futuro), se sumará como tercera opción.
- Al finalizar cada carrera, cliente y conductor se califican y pueden dejar un comentario corto (puntualidad, seguridad, trato, estado del vehículo, forma de pago, etc.).
- Ese historial pondera las decisiones de ambos lados: un conductor con buenos comentarios es más atractivo para ser agregado a nuevas flotas o para pasar a público; un cliente con buen historial es más atractivo para un conductor público que evalúa una invitación de alguien que no conoce.

---

## 4. Expresos — rutas fijas para horarios fijos

Para gente con entrada y salida a hora fija (trabajo, universidad, turno nocturno) y para empresas que quieren ofrecer transporte a su gente. Un "Expreso" es una ruta recurrente que el cliente (persona o empresa) publica, con su horario, condiciones y lo que está dispuesto a pagar. En vez de pedir una carrera cada día, se pacta una sola vez y el sistema genera las solicitudes automáticamente.

### 4.1 Qué publica el cliente

- **Horario y frecuencia:** días de la semana, hora de salida (ej. 6:30 a.m.) y hora de entrada/regreso (ej. 6:00 p.m.).
- **Origen y destino** de cada tramo (ida y vuelta, si aplica).
- **Cuánto está dispuesto a pagar:** por viaje, por semana o por mes.
- **Condiciones obligatorias**, marcadas de una lista o escritas: aire acondicionado, puntualidad máxima (ej. tolerancia de 5 min), no fumar, vehículo con antigüedad máxima, uso de cinturón, etc.

### 4.2 Cómo se cierra el trato

- Los conductores de la flota del cliente (o del directorio público) ven la oferta y se postulan.
- El cliente elige, o negocia el precio propuesto igual que en una carrera normal (sección 5).
- Al aceptar, queda un "contrato" de Expreso activo: el sistema crea automáticamente la solicitud de carrera cada día que corresponda.

### 4.3 Cumplimiento de condiciones

Si no se cumple una condición pactada (por ejemplo, el vehículo no tenía aire acondicionado), el cliente puede reportarlo desde la misma carrera. Los incumplimientos quedan en el historial del conductor y del Expreso, y pueden ser causa para cancelar el contrato sin penalidad para el cliente.

### 4.4 Expresos para empresas y universidades

Una organización puede publicar un Expreso compartido para varios empleados o estudiantes con el mismo horario —típicamente turnos nocturnos— y pagarlo desde una cuenta institucional. Conecta con el plan Institucional (sección 7.2). Por ahora, la conciliación de gastos se hace con comprobantes de transferencia; más adelante, la billetera virtual (sección 6) la puede automatizar.

---

## 5. Precios y negociación

No hay tarifa impuesta por la plataforma: cada conductor define su precio, y cliente y conductor negocian.

- En su perfil, el conductor define una **tarifa base por kilómetro** (y opcionalmente una tarifa mínima por carrera corta).
- Al pedir una carrera, la plataforma calcula un **precio sugerido = distancia × tarifa del conductor**, ajustado por un factor visible según la hora (recargo nocturno o en horas pico) — el cálculo se muestra desglosado, no oculto.
- El cliente puede aceptar ese precio o hacer una **contraoferta** con otro monto.
- El conductor ve el precio sugerido y la oferta del cliente, y puede aceptarla, rechazarla, o responder con una contraoferta propia (se sugiere limitar a una ronda para no alargar el proceso).
- El precio final acordado queda registrado en la carrera junto con su desglose, visible para ambos.
- Este mismo mecanismo se usa como base para calcular el precio sugerido de un **Expreso** (sección 4), ajustado por la duración/distancia del recorrido fijo.
- **Pago:** en efectivo o por transferencia directa entre cliente y conductor al finalizar la carrera; la plataforma no interviene. Ambas partes marcan la carrera como "pagada" para cerrar el ciclo y habilitar la calificación.

---

## 6. Billetera virtual y comisión (futuro — fuera de alcance por ahora)

> **Decisión tomada:** esta función se documenta para no perderla, pero **no se construye en esta primera etapa.** Todo el pago, por ahora, es directo entre cliente y conductor (efectivo/transferencia), sin comisión de la plataforma.

**Por qué queda fuera del MVP inicial:** operar una billetera implica manejar directamente el dinero de clientes y conductores, lo que en Ecuador probablemente cae bajo la Ley Fintech (Ley Orgánica para el Desarrollo, Regulación y Control de los Servicios Financieros Tecnológicos) — capital mínimo de USD 200,000, supervisión del Banco Central y las superintendencias, reportes de riesgo y ciberseguridad (ver sección 10.2). Para no frenar el lanzamiento con ese trámite, se deja para una etapa posterior.

### Cómo funcionaría, cuando se retome

- **Billetera del cliente:** carga fondos por transferencia o depósito para pagar carreras sin usar efectivo.
- **Billetera del conductor:** recibe los pagos hechos por billetera y paga su suscripción directo desde el saldo.
- **Comisión:** cliente paga con billetera → plataforma retiene **5%** → 95% va a la billetera del conductor. El pago directo (efectivo/transferencia) seguiría sin comisión; la billetera sería la única vía con comisión, a cambio de un pago más seguro y cómodo.

### Ideas guardadas para cuando se retome

- **Para el conductor:** pago garantizado al instante, menos efectivo encima (menos riesgo de robo), suscripción pagada directo desde el saldo, insignia "pago seguro" en su perfil.
- **Para que el cliente la use más:** prioridad en la flota al pagar por billetera, promoción sin comisión en las primeras carreras, cashback acumulable, conciliación automática de gasto para empresas en sus Expresos.
- **Métodos de pago del conductor:** la billetera se sumaría como tercera opción junto a transferencia y efectivo, no como reemplazo.
- **Recomendación práctica:** en vez de operar la billetera como entidad propia, evaluar asociarse con un banco, cooperativa o proveedor de billetera ya regulado, y que Arka01 se integre solo como capa de producto sobre esa infraestructura. Validar con un abogado especializado en fintech antes de retomarlo.

---

## 7. Suscripciones — conductor y cliente

Cada lado tiene su propio plan: el conductor paga según a cuántos clientes de confianza puede pertenecer, y el cliente paga según el tamaño y número de sus propias flotas. **El cliente no paga nada por el uso básico de la plataforma** — solo si necesita más capacidad.

### 7.1 Aclaración de términos

La flota es del cliente, no del conductor. Por eso hay **dos límites separados** y **dos escaleras de planes**: una para el conductor (a cuántos clientes puede pertenecer) y otra para el cliente (cuántos conductores puede tener en su flota, y cuántas flotas puede crear).

### 7.2 Plan del conductor — según sus clientes de confianza

Determina a cuántos clientes distintos puede pertenecer y si es visible en el directorio público.

| Plan | Precio/mes | Puede pertenecer a | Visibilidad pública | Extra |
|---|---|---|---|---|
| Gratis | $0 | 3 clientes de confianza | No | Perfil con calificaciones y comentarios |
| Básico | $10 | 25 clientes de confianza | No | — |
| Plus (popular) | $15 | 45 clientes de confianza | Sí, aparece en el directorio | — |
| Pro *(sugerido)* | $25 | 80 clientes de confianza | Sí, con prioridad en resultados | Insignia "conductor verificado" + estadísticas de perfil |
| Institucional *(sugerido)* | Desde $40 | Pertenece a la flota corporativa de una organización | Flota corporativa | Pensado para Expresos y turnos nocturnos; panel de administración + reportes de uso |

La progresión agrega valor en cada escalón: más clientes de confianza (Básico), alcance público (Plus), prioridad y credibilidad (Pro), y gestión institucional (Institucional). Los montos de Pro e Institucional son una propuesta inicial para validar con conductores reales.

### 7.3 Plan del cliente — según el tamaño de su flota

Determina cuántos conductores puede tener en su flota y cuántas flotas distintas puede crear. No afecta el pago ni la visibilidad del conductor.

| Plan | Precio/mes | Flotas | Conductores por flota |
|---|---|---|---|
| Gratis | $0 | 1 | Hasta 20 |
| Plus *(sugerido)* | $5 | 1 | Hasta 50 |
| Multi-flota *(sugerido)* | $12 | Hasta 5 | Hasta 50 c/u |

Una organización (universidad/empresa) no usa esta escalera: sus límites se definen a la medida dentro de su cuenta institucional. La idea central a mantener: el plan gratuito debe alcanzar para un uso normal (1 flota, 20 conductores), y crecer más allá de eso es lo que se cobra.

### 7.4 Ejemplos: para qué sirve una o varias flotas

**Clientes individuales:**

- **Flota de casa:** los taxistas del barrio y conocidos de la familia, para el día a día y las emergencias.
- **Flota de trabajo:** conductores para la ruta a la oficina o mensajería del negocio, separados de los de uso personal.
- **Flota para los papás:** una flota aparte, de más confianza aún, para llevar a los padres mayores o a los hijos — no necesariamente los mismos conductores que usa el resto de la familia.

**Organizaciones:**

- **Universidad:** una flota para el horario diurno de estudiantes y otra, con verificación más estricta, para el turno nocturno.
- **Empresa con varios turnos:** una flota por turno o por sucursal, para no mezclar los conductores asignados a cada horario.
- **Clínica u hospital:** flota separada para el personal de guardia nocturna, distinta de la del personal administrativo de día.

### 7.5 Pago de la suscripción

Por ahora, por transferencia con activación manual. Cuando exista la billetera virtual (sección 6, a futuro), se podrá pagar directo desde el saldo.

---

## 8. Seguimiento y seguridad durante el viaje

Reutiliza la misma infraestructura de ubicación en tiempo real que ya se construye para ver disponibilidad de la flota.

- **Seguimiento en vivo compartible:** el cliente genera un enlace de solo lectura para compartir con un contacto de confianza, que ve la ubicación en un mapa sin cuenta ni instalación.
- **Bitácora automática del viaje:** ruta, distancia, duración y precio acordado quedan guardados en el historial de ambos — sirve para transparencia y disputas.
- **Botón de alerta / SOS:** visible durante la carrera; notifica a un contacto de confianza y guarda un registro de emergencia con ubicación y datos del conductor/vehículo.
- **Verificación visible antes de subir:** foto del conductor, placa y su score — refuerza la confianza con un conductor público que no se conoce bien todavía.

**Orden de construcción recomendado:** seguimiento en vivo y bitácora primero (casi no cuestan más porque reutilizan el WebSocket de ubicación), y sumar el botón SOS poco después.

---

## 9. Arquitectura técnica

"Web pero que se sienta como app móvil" se resuelve con una **Progressive Web App (PWA)**: un sitio responsive que se puede "instalar" en la pantalla de inicio del teléfono, funciona a pantalla completa, recibe notificaciones push y funciona razonablemente offline, sin pasar por las tiendas de aplicaciones.

### 9.1 Backend — Laravel

- Laravel como API REST (Laravel Sanctum para autenticación de sesión/token, ideal para SPA + móvil).
- Laravel Reverb (o Pusher/Ably) para WebSockets: ubicación en tiempo real, negociación de precio, notificación instantánea de solicitudes y Expresos programados.
- Laravel Horizon + colas (Redis) para: cálculo de cercanía, cálculo de precio sugerido, generación automática de carreras de Expreso.
- **Sin pasarela de pago ni billetera en esta etapa:** el cobro de la carrera es directo entre cliente y conductor (efectivo/transferencia), sin comisión de la plataforma.
- Cuando se retome la billetera virtual (sección 6, a futuro), su diseño recomendado es un **ledger interno** (tabla de movimientos con saldo calculado, no solo un número que se sobrescribe) para poder auditar cada depósito, pago, comisión y retiro.

### 9.2 Frontend — responsive y "tipo app"

- Blade + Livewire o Inertia.js + Vue/React, dentro del mismo monorepo de Laravel.
- Tailwind CSS para diseño responsive mobile-first.
- PWA: `manifest.json`, Service Worker (cache offline, ícono de instalación) y Web Push — se "instala" como una app nativa.
- Más adelante, si se justifica, el mismo backend Laravel puede servir una app nativa real (Flutter o React Native) sin rehacer la lógica de negocio.

### 9.3 Geolocalización y tiempo real

- Ubicación de conductores disponibles vía geolocalización del navegador/dispositivo, enviada periódicamente al backend.
- Cálculo de cercanía y distancia con fórmula de Haversine o extensión geoespacial de la base de datos (MySQL con columnas espaciales, o PostgreSQL + PostGIS si se necesita más precisión).
- Canal WebSocket por flota y por solicitud de carrera para actualizar posición, estado y negociación de precio sin recargar la página; el mismo canal habilita el seguimiento en vivo compartible (sección 8).
- Mapas: usar proveedores de bajo costo o de código abierto (OpenStreetMap + Leaflet) en vez de Google Maps, para no incurrir en costos variables por uso.

### 9.4 Modelo de datos — entidades principales

| Entidad | Descripción |
|---|---|
| `users` | Datos base de cuenta; un usuario puede ser cliente y/o conductor. |
| `driver_profiles` | Licencia, vehículo, foto, tarifa por km, métodos de pago aceptados, verificación, disponibilidad. |
| `organizations` | Cuenta institucional (universidad/empresa) que administra su propia flota o Expresos. |
| `subscriptions` | Plan activo de un conductor (límite de clientes de confianza, visibilidad pública) o de un cliente (número de flotas, cupo de conductores por flota); vigencia. |
| `fleets` / `fleet_invitations` / `fleet_members` | Flota personal, invitaciones y membresías confirmadas (con registro de salida/baja). |
| `ride_requests` / `ride_offers` / `rides` | Solicitud, negociación de precio (oferta/contraoferta) y carrera confirmada. |
| `express_routes` | Ruta fija: horario, días, origen/destino, tarifa ofrecida, estado. |
| `express_conditions` | Condiciones pactadas por ruta (aire acondicionado, puntualidad, etc.) y su cumplimiento. |
| `express_applications` | Postulación de un conductor a una ruta Expreso. |
| `reviews` | Calificación + comentario bidireccional asociado a una carrera. |

**No incluidas todavía:** `wallets` y `wallet_transactions`, que se sumarían cuando se retome la billetera virtual (sección 6).

### 9.5 Módulos del sistema, por rol

Catálogo de pantallas/módulos a construir. Sirve como lista de tareas de alto nivel para ir marcando avance.

**A) Módulos del Cliente**

- **Registro y verificación:** alta de cuenta, verificación de teléfono, datos básicos.
- **Mi Flota:** buscar/invitar conductores (por nombre, teléfono, código o QR), ver miembros y su disponibilidad en el mapa, sacar (declinar) a un conductor.
- **Solicitar carrera:** elegir conductor o "toda la flota disponible", negociar precio, ver seguimiento en vivo del viaje en curso.
- **Expresos:** crear/editar ruta fija, definir condiciones y precio, revisar postulaciones de conductores, aprobar o negociar.
- **Historial de viajes:** bitácora de carreras (ruta, distancia, duración, precio), recibos internos, calificaciones dadas y recibidas.
- **Perfil y reputación:** ver mi score y comentarios recibidos, editar mis datos.
- **Plan y suscripción:** ver plan actual y cupo usado ("14 de 20 conductores"), subir de plan, historial de activaciones.
- **Seguridad del viaje:** contactos de confianza, enlace de seguimiento compartido, botón SOS.
- **Notificaciones.**

**B) Módulos del Conductor**

- **Registro y verificación:** licencia, matrícula del vehículo, foto, validación por el administrador.
- **Mis clientes de confianza:** invitaciones recibidas (aceptar/rechazar), flotas a las que pertenece, opción de salir de una.
- **Disponibilidad:** marcar disponible/no disponible, ubicación en vivo.
- **Solicitudes entrantes:** aceptar, rechazar o negociar precio de una carrera o de una postulación a Expreso.
- **Tarifa y métodos de pago:** definir tarifa por km, marcar qué acepta (efectivo, transferencia, y a futuro billetera).
- **Historial de viajes:** carreras realizadas, con su precio y condiciones acordadas.
- **Perfil público y reputación:** score, comentarios recibidos, insignias (verificado, público).
- **Plan y suscripción:** cupo de clientes de confianza usado, activar visibilidad pública, subir de plan.
- **Notificaciones.**

**C) Módulos administrativos (panel del dueño de la plataforma)**

- **Verificación de identidad:** aprobar o rechazar documentos de conductores y de organizaciones.
- **Gestión de usuarios:** clientes, conductores y organizaciones — buscar, suspender, dar soporte.
- **Gestión de suscripciones y pagos manuales:** activar un plan tras confirmar una transferencia, ver próximos vencimientos, enviar recordatorios.
- **Moderación y disputas:** revisar reportes de incumplimiento de condiciones en Expresos, reclamos sobre calificaciones.
- **Panel de organizaciones:** aprobar cuentas institucionales y configurar sus límites a medida (sección 7.3).
- **Métricas y reportes:** usuarios activos, carreras realizadas, tasa de conversión a planes pagos, adopción por ciudad.
- **Configuración de parámetros:** factor de recargo horario del precio sugerido, límites por defecto de los planes, textos legales.
- **Auditoría:** bitácora de acciones administrativas (quién activó qué plan, quién aprobó qué verificación).
- **Soporte / tickets.**

**D) Módulos transversales (compartidos por todos)**

- Autenticación y sesiones.
- Notificaciones (push web y correo).
- Geolocalización y mapa.
- Motor de negociación de precio (oferta/contraoferta, sección 5).
- Motor de recurrencia de Expresos (genera la carrera del día automáticamente).
- Sistema de calificación y comentarios bidireccional.

### 9.6 Cálculos y validaciones — suscripciones y trazabilidad

**Validaciones del lado del conductor**

- Antes de aceptar una invitación nueva: validar que su conteo de flotas activas (`fleet_members` vigentes) sea menor al límite de su plan (sección 7.2). Si ya llegó al tope, bloquear la aceptación y sugerir subir de plan.
- La visibilidad pública solo puede activarse si el plan vigente la incluye (Plus o superior). Si el conductor baja de plan, se desactiva automáticamente.
- Si hace downgrade y queda por encima del nuevo límite, no se expulsa a los clientes ya vinculados (no se rompen relaciones existentes), pero se bloquean nuevas aceptaciones hasta regularizar.

**Validaciones del lado del cliente**

- Antes de invitar a un conductor nuevo a una flota: validar que el conteo de conductores activos en esa flota sea menor al cupo de su plan (sección 7.3).
- Antes de crear una nueva flota: validar que el número de flotas actuales sea menor al límite de su plan.
- Mismo criterio de "no romper lo existente" si hace downgrade.

**Vigencia y gracia**

- Cada suscripción tiene fecha de vencimiento. Como el pago es manual (transferencia, sección 7.5), se define un período de gracia (ej. 3 días) antes de degradar automáticamente al plan Gratis por falta de renovación, con aviso previo (ej. 5 días antes de vencer).

**Trazabilidad (auditoría)**

- Todo cambio de plan (alta, upgrade, downgrade, vencimiento) queda registrado: quién lo activó (usuario o administrador), cuándo, plan anterior y nuevo — clave para auditar las activaciones manuales por transferencia.
- Toda alta o baja de un `fleet_member` (invitación aceptada, salida voluntaria, expulsión) queda registrada con fecha y quién la originó, para reconstruir el historial de la relación cliente-conductor.
- Cada carrera guarda una "foto" del estado en ese momento (tarifa por km vigente, plan y condiciones aplicables), para que cambios posteriores en las reglas generales no alteren el histórico.
- Los reportes de incumplimiento de un Expreso quedan ligados a la carrera y al contrato específico, visibles para el administrador ante una disputa.

**Cálculos**

- Cupo disponible = límite del plan − conteo activo (se muestra al usuario, ej. "14 de 20 conductores usados").
- Precio sugerido = distancia × tarifa del conductor × factor horario (sección 5).
- Días para el vencimiento de la suscripción, para disparar los recordatorios.

### 9.7 Para que sea rápida, eficiente, segura y fácil de usar

**Rendimiento y eficiencia**

- Cachear en Redis la disponibilidad de la flota, para no recalcular cercanía en cada solicitud.
- Índices geoespaciales en la base de datos para que las consultas de cercanía sean rápidas incluso con muchos conductores.
- Paginar el directorio público de conductores (nunca cargar todos de una vez).
- Todo lo que no necesite respuesta inmediata (notificaciones, generación de carreras de Expreso) va por colas (Horizon), no en el request principal.
- CDN (Cloudflare) para los archivos estáticos del frontend.
- Meta de referencia: respuestas de API por debajo de ~300ms en las operaciones más comunes (ver flota, solicitar carrera).

**Seguridad**

- Autenticación con tokens (Sanctum) con expiración y posibilidad de revocar sesiones.
- Verificación de identidad del conductor (documento + foto) antes de habilitarlo a recibir solicitudes.
- Límite de intentos (rate limiting) en login y en endpoints sensibles, para evitar fuerza bruta.
- HTTPS en todo momento (ya contemplado con Let's Encrypt/Cloudflare).
- Permisos por rol en cada endpoint: un cliente no puede ver la flota de otro, un conductor no puede ver el historial de otro conductor, etc.
- Cifrado de los documentos de verificación e información sensible en reposo.
- La auditoría de la sección 9.6 cubre también el aspecto de seguridad: queda registro de quién hizo qué.

**Facilidad de uso**

- Diseño mobile-first, instalable como PWA.
- Flujos cortos: solicitar una carrera en pocos pasos (elegir conductor o flota → confirmar precio → esperar aceptación).
- Mensajes de error claros y accionables (ej. "Llegaste al límite de conductores de esta flota — sube de plan o crea otra flota").
- Onboarding guiado la primera vez: cómo buscar y agregar el primer conductor a la flota.
- Contraste y tamaños de texto legibles (ya cuidado en el diseño del documento de producto en HTML).

### 9.8 Integraciones necesarias

| Integración | Para qué | Notas |
|---|---|---|
| SMS/OTP o alternativa | Verificar el teléfono al registrarse | Es el único costo por persona nueva (sección 11.1); considerar correo o WhatsApp como alternativa más barata al inicio |
| Correo transaccional | Recuperar contraseña, confirmaciones | Nivel gratuito alcanza al inicio |
| Mapas | Ver ubicación, calcular distancia y cercanía | OpenStreetMap + Leaflet, para evitar costos de Google Maps |
| WebSockets | Ubicación en vivo, negociación de precio, notificaciones instantáneas | Laravel Reverb (self-hosted, sin costo de licencia) |
| Almacenamiento de archivos | Fotos de conductor, vehículo, documentos de verificación | Cloudflare R2 (o similar S3-compatible) |
| Monitoreo de errores | Detectar fallos en producción | Sentry u otro, nivel gratuito al inicio |
| Notificaciones push | Avisos en el navegador sin costo de servicio de terceros | Web Push API (VAPID) |
| Programador de tareas | Generar automáticamente las carreras recurrentes de los Expresos | Scheduler de Laravel |
| Pasarela de pago / billetera regulada | Solo cuando se retome la sección 6 | A futuro, fuera del alcance inicial |

### 9.9 Identidad visual y guía de interfaz (una sola línea de diseño)

> **Por qué "Arka01" y el eslogan "Solo suben los tuyos."**
> "Arca" remite al arca de Noé: un refugio que solo llevaba a bordo a quienes ya habían sido elegidos de antemano, a salvo del peligro de afuera — igual que esta plataforma, donde nadie sube a un viaje sin que el cliente lo haya decidido primero. El "01" le suma un aire de producto tecnológico y de primera versión: el punto de partida de una forma distinta de moverse, no una palabra bíblica anticuada. Combinados, cuentan la historia completa en un solo nombre: una embarcación de confianza, puesta al día.

**Principio general:** una plataforma web que se siente como app móvil (PWA), con un diseño profesional propio — inspirado en el nivel de pulido y simplicidad de apps como Uber o Cabify, pero **sin copiar su identidad**: paleta, tipografía e íconos propios, para no arriesgarse a ningún parecido de marca.

**Paleta de colores** (la misma ya validada en el documento de producto en HTML — modo oscuro con verde llamativo):

| Uso | Color | Hex |
|---|---|---|
| Fondo base | Casi negro con tinte verde | `#0a0f0c` |
| Fondo de tarjetas | Verde muy oscuro | `#121b17` |
| Verde primario (marca, acciones) | Verde menta | `#34d399` |
| Verde brillante (énfasis, links activos) | Menta claro | `#6ee7b7` |
| Lima (llamativo: insignias, promociones, "nuevo") | Verde lima | `#a3e635` |
| Texto principal | Blanco verdoso | `#e7f4ee` |
| Texto secundario | Verde grisáceo | `#93ada2` |
| Advertencia | Ámbar | `#fbbf24` |
| Error/peligro | Rojo coral | `#f87171` |

El **verde primario** se usa para botones de acción y elementos de marca; el **lima** se reserva como color llamativo puntual (insignias, planes destacados, etiquetas), nunca como fondo grande, para que siga funcionando como acento y no canse la vista.

**Tipografía:**

- Fuente del sistema (`-apple-system, Segoe UI, Roboto, Helvetica`) en vez de una fuente externa: carga más rápido y se ve nativa en cualquier dispositivo — clave para que la plataforma se sienta rápida y "de app".
- Jerarquía sugerida: título principal 32-40px (bold), título de sección 24-26px (bold), subtítulo 16-18px (semibold), texto de cuerpo 14-16px (regular), texto secundario 12-13px.
- Peso bold reservado para títulos y datos clave (precios, cupos de plan, alertas); regular para el resto.

**Una sola línea de interfaz (design system):**

- Un único set de componentes reutilizable entre cliente, conductor y administrador: tarjetas, tablas, insignias, tarjetas de plan, pasos de flujo, recuadros de aviso — los tres perfiles comparten la misma base visual aunque cada uno vea módulos distintos.
- Bordes redondeados consistentes (10-14px) en toda la interfaz.
- Espaciado, tamaños y colores definidos como variables (design tokens) desde el inicio, no valores sueltos por pantalla — así escalar a nuevos módulos no rompe la consistencia visual.
- Un ícono por concepto (flota, Expreso, calificación, seguridad) usado siempre igual, en las tres experiencias.

**Que la web se sienta app:**

- PWA instalable, ícono propio, pantalla completa (sin barra de navegador de por medio).
- Navegación inferior tipo app en móvil (ej. Inicio · Flota · Carreras · Perfil) en vez de menús web tradicionales.
- Botones e interacciones táctiles grandes (mínimo ~44px de alto), pensados para el dedo, no para el mouse.
- La misma paleta y tipografía se mantiene en escritorio (donde se usará más el panel de administrador) y en móvil, solo variando la densidad de información.

**Por qué no copiar a Uber:** la meta es el mismo nivel de pulido y simplicidad que transmiten Uber o Cabify, no su identidad. Se evita su combinación de colores, tipografía de marca, logo e íconos específicos. La paleta oscura + verde de Arka01 ya cumple ese objetivo: se ve profesional, es reconocible como propia, y no genera ningún parecido problemático con otra marca.

### 9.10 Requisitos de diseño: memorable, moderna, profesional y llamativa

- **Memorabilidad:** ícono de instalación propio (192x192 y 512x512), splash screen de marca al abrir, eslogan "Solo suben los tuyos" visible en el onboarding y en la landing.
- **Modernidad:** micro-interacciones sutiles, transiciones suaves entre pantallas, skeleton loaders en vez de spinners genéricos, un solo set de íconos, "glow" verde en botones y estados activos.
- **Profesionalismo (crítico, porque el producto vende seguridad):** estados vacíos resueltos con ilustración y CTA claro (no pantallas en blanco), errores explicados en lenguaje humano, insignias de verificación visibles a simple vista (conductor verificado, pago seguro a futuro).
- **Llamativa sin perder seriedad:** el lima se reserva para momentos puntuales (confirmaciones, "¡carrera completada!", insignias), nunca como fondo grande; estilo único y coherente en avatares e íconos, sin plantillas genéricas.
- **Que la web se sienta app de verdad:** gestos táctiles nativos (deslizar para volver, pull-to-refresh), barra de estado del sistema pintada con el verde de marca (`theme-color`), y funcionamiento parcial offline (historial cacheado si se pierde conexión).
- **Landing pública:** una página de entrada aparte de la app, para quien todavía no se registra — propuesta de valor arriba, capturas de pantalla, cómo funciona la flota, y el botón de instalar/registrarse. Es la primera impresión profesional antes de que alguien decida confiar.

---

## 10. Legal y regulatorio en Ecuador

### 10.1 Transporte por aplicación

- La Corte Constitucional declaró inconstitucional el artículo del COIP que sancionaba a conductores de apps como Uber, Didi e InDrive, y suspendió esas sanciones hasta que la Asamblea Nacional emita una normativa específica.
- La Asamblea Nacional incluyó la regulación de trabajadores de plataformas digitales en su Plan Legislativo 2025-2027.
- Uber, Didi e InDrive operan hoy en Quito y Guayaquil en un entorno regulatorio todavía no consolidado; Cabify se retiró del país.

### 10.2 Billetera virtual — punto importante (por qué se pospuso)

Ecuador tiene una Ley Fintech (Ley Orgánica para el Desarrollo, Regulación y Control de los Servicios Financieros Tecnológicos), supervisada por el Banco Central, la Junta de Política y Regulación Monetaria/Financiera y las superintendencias correspondientes. Desde 2025 se exige a las fintech de este tipo un capital pagado mínimo de USD 200,000, estructura de riesgos aprobada y reportes de ciberseguridad. Manejar directamente el dinero de clientes y conductores en una billetera propia probablemente cae bajo este régimen — por eso quedó fuera del alcance inicial (sección 6). Cuando se retome: evaluar asociarse con un banco, cooperativa o proveedor de billetera ya regulado en vez de operar los fondos directamente, y validar con un abogado especializado en fintech.

### 10.3 Otros puntos

Conviene además revisar protección de datos personales (ubicación, identidad, historial de pagos) y responsabilidad frente a terceros, especialmente en los Expresos institucionales donde una empresa contrata transporte para su personal.

---

## 11. Costos de operación y rentabilidad

Solo costos de servicios (hosting, SMS, almacenamiento, etc.) — **no incluye desarrollo**, porque el dueño del proyecto lo construye directamente.

### 11.1 Qué servicios se pagarían, mes a mes

| Servicio | Para qué sirve | Costo aproximado |
|---|---|---|
| Servidor (VPS) | Corre Laravel, la base de datos y el WebSocket (Reverb, sin costo de licencia) | $9-30/mes según el tamaño |
| Redis (colas y tiempo real) | Ubicación en vivo, notificaciones, cálculo de precio | $0/mes al inicio (nivel gratuito), ~$5/mes creciendo |
| Almacenamiento de fotos | Fotos de conductor, vehículo, documentos de verificación | $0-5/mes (nivel gratuito cubre bastante) |
| Dominio | arka01.com o similar | ~$1-1.5/mes (se paga una vez al año) |
| Certificado SSL | Conexión segura (https) | $0 (gratis con Let's Encrypt/Cloudflare) |
| Correo transaccional | Recuperar contraseña, confirmaciones | $0-2/mes a este volumen |
| Verificación por SMS (OTP) | Confirmar el número de teléfono al registrarse | ~$0.05-0.08 por registro nuevo (no es mensual fijo, es por persona que se une) |
| Monitoreo de errores | Enterarte si algo se rompe en producción | $0/mes al inicio (nivel gratuito) |
| Respaldos (backups) | Copia de seguridad del servidor | ~$1-3/mes |

La verificación por SMS es el único costo que crece directamente con cada persona nueva que se registra, no con el uso mensual. Alternativa más barata para el arranque: verificar por correo o WhatsApp en vez de SMS.

### 11.2 Total estimado por etapa

| Etapa | Costo mensual | Alcance |
|---|---|---|
| Piloto | ~$20/mes | Hasta 100 conductores activos, 1 ciudad |
| Crecimiento (realista año 1) | ~$70-90/mes | 500-1,000 usuarios activos, varias ciudades |
| Escala | ~$180-220/mes | Miles de usuarios + cuentas institucionales |

### 11.3 ¿Alcanza con los planes de suscripción?

Tomando la etapa "Crecimiento" (~$80/mes) como referencia, alcanza con muy pocos suscriptores pagando: **8 conductores en plan Básico ($10)**, o **6 en plan Plus ($15)**, o una mezcla de conductores y clientes con flota grande.

Si de 500-1,000 usuarios registrados solo un 10-15% termina pagando algún plan (una proporción baja y conservadora), la recaudación mensual ronda los **$1,500-3,000** frente a un gasto de servicios de **$70-90** — un margen amplio incluso en un escenario conservador. Es una estimación orientativa, no una proyección contable: hay que ajustarla con datos reales del piloto.

### 11.4 Dónde hay margen para ajustar precios y sumar gente

Como el gasto de servicios es una fracción pequeña de lo que entra incluso con pocos suscriptores pagando, hay espacio para **bajar precios al inicio sin poner en riesgo la operación**:

- **Tarifa "fundador":** los primeros conductores y clientes que se unan durante el piloto mantienen un precio más bajo de por vida, aunque suba después para los que lleguen más tarde.
- **Ampliar el plan gratuito al inicio:** por ejemplo, subir de 3 a 5 clientes de confianza en el plan gratuito del conductor, o de 20 a 30 conductores en el plan gratuito del cliente, mientras se construye la base de usuarios — el costo real de esto es casi cero.
- **Bajar el precio de entrada:** el plan Básico del conductor podría arrancar en $5-7 en vez de $10 durante el piloto, y subir gradualmente.
- **Meses gratis al invitar:** un conductor o cliente que invita a otro conductor/cliente real que se activa gana un mes gratis de su plan — crece la red sin gastar en publicidad paga.

**Recomendación:** arrancar generoso en el piloto, medir cuántos usuarios activos y de pago se consiguen de verdad, y recién ahí decidir si conviene subir los precios a los valores "de régimen" planteados en la sección 7.
-----
CONSIDERACIÓN DE SEGURIDAD ADICIONAL: ✅ Implementado (ver Arka01_Progreso.md, "Ajuste transversal: sesión única por cuenta y zonas del Ecuador")
1. Debemos cuidar la seguridad de que si tiene iniciada la sesion en un equipo le sepa indicar y no le permita hasta que cierre la sesion en ese equipo, para que no pueda iniciar sesion en otro equipo hasta que cierre la sesion en el primero. Esto es importante para evitar que un usuario comparta su cuenta con otros y se pierda el control de la flota. esto es tanto para conductor como para cliente. Esto se puede implementar con un token de sesión único por usuario y verificarlo en cada solicitud al backend.

buscar de que cuanto pidan una carreta que agreguen zonas del ecuador que por defecto este la ciudad donde vive y que cuando pida aparte del mapa pueda indicar el sector a donde va y donde esta. para que el chofer sepa sin entrar al mapa donde esta y donde la ira a llevar. ejemplo: esta en sauces 1 y va a samanes3 algo asi. y coloque referencias tambien. 
---

## 12. Hoja de ruta (MVP por fases)

| Fase | Alcance | Duración estimada |
|---|---|---|
| 1. Núcleo | Registro, perfiles, flota personal con búsqueda tipo red social e invitación/aceptación mutua. | 4-6 semanas |
| 2. Solicitud de carrera | Geolocalización, disponibilidad en tiempo real, solicitud a la flota (WebSockets). | 4-6 semanas |
| 3. Confianza | Calificaciones y comentarios bidireccionales, perfiles públicos, directorio de conductores públicos. | 3-4 semanas |
| 4. Precios y negociación | Tarifa por km, cálculo sugerido por distancia/hora, oferta y contraoferta. | 3-4 semanas |
| 5. Suscripciones | Planes de conductor (clientes de confianza) y de cliente (tamaño/número de flotas), pago por transferencia con activación manual, panel institucional básico. | 3 semanas |
| 6. Expresos | Publicación de rutas fijas, condiciones, postulación de conductores, generación automática de carreras recurrentes. | 4-5 semanas |
| 7. PWA y seguridad | Manifest, Service Worker, push, seguimiento en vivo, botón SOS. | 3-4 semanas |
| 8. Piloto | Lanzamiento cerrado en una ciudad, con una universidad o empresa piloto para Expresos. | 4 semanas |
| Futuro (fuera del MVP) | Billetera virtual: billetera cliente/conductor y comisión del 5% en pagos por billetera. Pendiente de validar el encaje regulatorio (sección 10.2) antes de iniciar. | A definir |

---

## 13. Próximos pasos

- La billetera virtual (sección 6) queda fuera del alcance inicial; cuando se quiera retomar, validar primero con un abogado fintech el modelo y si conviene un socio regulado (sección 10.2).
- Validar con taxistas y clientes reales los montos y límites de los planes de suscripción (sección 7), empezando generoso durante el piloto (sección 11.4).
- Probar el factor de ajuste horario del precio sugerido (sección 5) con casos reales de tarifa por km.
- Conversar con una universidad o empresa con turno nocturno como posible piloto de Expresos institucionales.
- Construir el proyecto Laravel: autenticación, modelos de datos (sección 9.4), y el flujo de búsqueda/invitación a la flota como primer caso funcional.

---

## 14. Glosario rápido

- **Flota:** lista de conductores de confianza que arma y administra un cliente (o una organización).
- **Cliente de confianza:** desde el punto de vista del conductor, cada cliente que lo tiene agregado en su flota. El límite de "a cuántos puede pertenecer" es lo que determina el plan del conductor.
- **Conductor público:** conductor visible en el directorio general, que cualquier cliente puede encontrar y agregar sin conocerlo antes.
- **Expreso:** ruta fija y recurrente (horario, días, condiciones y precio pactados de antemano) que genera carreras automáticamente.
- **Organización:** cuenta institucional (universidad, empresa) con su propia flota o Expresos, con límites definidos a la medida.
- **Ledger interno:** forma recomendada de construir la futura billetera — una tabla de movimientos auditable, no un simple saldo que se sobrescribe.

---

*Documento consolidado a partir de todas las definiciones acordadas en el proceso de diseño de Arka01. Sirve como referencia única para construir el proyecto y como base para seguir iterando — cada sección puede actualizarse a medida que se validen supuestos con usuarios reales durante el piloto.*
