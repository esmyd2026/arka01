# Arka01 — Progreso de desarrollo

Registro de qué se construyó en cada fase del roadmap (`Arka01_Alcance_Proyecto.md`, sección 12), con los insumos técnicos de cada una (paquetes, variables de entorno, archivos clave, rutas). Se va actualizando a medida que se cierra cada fase — no repite el detalle del documento de alcance, solo dice **qué de eso ya está construido y cómo**.

---

## Cómo correr el proyecto en local

Se necesitan **3 procesos corriendo al mismo tiempo**, cada uno en su propia terminal:

```
php artisan serve          # o entrar directo por Laragon a http://arka01.test
npm run dev                 # compila y sirve el frontend con hot-reload
php artisan reverb:start    # servidor de WebSockets (tiempo real)
```

Opcional, solo si querés ver el motor de recurrencia de Expresos (Fase 6) corriendo de verdad en vez de disparar el comando a mano: `php artisan schedule:work` (4º proceso). Para probarlo sin esperar al horario programado: `php artisan express:generate-rides`.

Para recargar la base con datos de demo frescos: `php artisan migrate:fresh --seed`.

**Pendiente de tu parte:** para que aparezca el botón "Continuar con Google" en el login, completá `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` y `GOOGLE_REDIRECT_URI` en `.env` (instrucciones de dónde conseguirlos, arriba de esas líneas en el propio archivo). Sin eso, el login normal con usuario y contraseña funciona igual — el botón simplemente no se muestra.

### Cuentas de demo

Todas usan la contraseña `password`.

| Cuenta | Rol | Qué tiene cargado para probar |
|---|---|---|
| `admin@arka01.test` | Admin | Panel admin: planes, tarifas, indicadores (Fase 5), **alertas SOS y verificación de conductores** (Fase 7) |
| `cliente@arka01.test` | Cliente y conductor a la vez | **Contacto de confianza cargado** (Fase 7), flota con 3 conductores, plan **Básico** de conductor, **Expreso activo** con Ana asignada (Fase 6) |
| `otro@arka01.test` | Cliente (plan **Multi-flota**) | **Contacto de confianza + alerta SOS ya registrada** (Fase 7), dos flotas, **Expreso abierto con 2 postulaciones** (Fase 6), una carrera en curso |
| `pedro@arka01.test` | Conductor | **Documentos subidos, pendientes de revisión** en `/admin/verificaciones` (Fase 7) + solicitud "a toda la flota" |
| `ana@arka01.test` | Conductor | **Expreso activo asignado** (Fase 6, con una carrera ya completada de ese contrato) + ya contraofertó un precio de una carrera suelta (Fase 4) |
| `luis@arka01.test` | Conductor | Invitación pendiente de aceptar/rechazar |
| `marta@arka01.test` | Conductor (plan **Plus**, **verificado**) | Insignia de verificado (Fase 7) + postulación pendiente a un Expreso (Fase 6) |
| `carlos@arka01.test` | Conductor (en 2 flotas) | Postulación pendiente a un Expreso (Fase 6) + solicitud dirigida pendiente + reseña asimétrica |
| `sofia@arka01.test` | Conductor (plan **Plus**, **verificado**) | **Carrera en curso** con seguimiento en vivo compartible y SOS ya activado (Fase 7) + mejor calificado del directorio |

`cliente@arka01.test` y `ana@arka01.test` además tienen una negociación de precio en curso entre ellos (Fase 4): Ana ya contraofertó y queda esperando que el cliente acepte o rechace.

Seeder: `database/seeders/DemoDataSeeder.php`.

---

## Fase 1 — Núcleo

**Alcance cubierto:** registro, perfiles, flota personal con búsqueda tipo red social e invitación/aceptación mutua (secciones 3.1-3.3 del alcance).

### Stack e insumos
- Laravel 10 (esqueleto inicial) + Inertia.js + Vue 3 + Tailwind, instalado vía Laravel Breeze.
- MySQL local (Laragon), base `arka01`.
- Sin variables de entorno nuevas más allá de `APP_NAME`, `APP_LOCALE=es`, `APP_TIMEZONE=America/Guayaquil`.
- Paleta e identidad visual aplicada desde el arranque (sección 9.9 del alcance): tokens de color en `tailwind.config.js`, fuente de sistema (sin fuente externa).

### Qué se construyó
- Autenticación completa (registro con teléfono obligatorio, login, recuperar contraseña) — Breeze personalizado con la identidad de Arka01.
- Modelos y migraciones: `users` (extendido con `phone`, `user_type`, `is_admin`), `driver_profiles`, `fleets`, `fleet_invitations`, `fleet_members`.
- `config/arka.php`: límites del plan Gratis como constantes (3 clientes de confianza por conductor, 1 flota y 20 conductores por flota del cliente) — listo para que la Fase 5 los reemplace por suscripciones reales sin tocar el resto del código.
- Búsqueda de conductores por nombre/teléfono/código de invitación + QR (librería `qrcode`, generado en el navegador).
- Flujo completo de invitación: cliente invita → conductor acepta/rechaza → aparece en "Mi Flota", con los dos límites de plan validados.

### Archivos clave
- `app/Models/{User,DriverProfile,Fleet,FleetInvitation,FleetMember}.php`
- `app/Http/Controllers/{FleetController,FleetInvitationController,FleetMemberController,DriverInvitationController,DriverProfileController}.php`
- `resources/js/Pages/Fleet/Index.vue`, `resources/js/Pages/Driver/{Profile,Invitations}.vue`

### Rutas principales
`/flota`, `/flota/buscar-conductores`, `/flota/invitaciones`, `/mis-clientes`, `/driver/profile`

### Tests
`tests/Feature/Fleet/FleetInvitationFlowTest.php` — ciclo completo de invitación, límites de plan, salida voluntaria.

---

## Fase 2 — Solicitud de carrera

**Alcance cubierto:** geolocalización, disponibilidad en tiempo real, solicitud a la flota vía WebSockets (sección 3.5), más el cálculo de precio sugerido (sección 5, sin la negociación por contraoferta — eso es Fase 4).

### Cambio de infraestructura (obligado por esta fase)
- **Laravel 10 → Laravel 12**: Laravel Reverb (WebSockets) pide PHP ≥ 8.2. El usuario actualizó PHP a **8.5.8** en Laragon, lo que a su vez obligó a actualizar el framework completo (Inertia 0.6→2.0, Sanctum 3→4, Breeze 1→2, PHPUnit 10→11) para mantener compatibilidad. Verificado que los 32 tests de la Fase 1 siguieran pasando después del salto.
- **Laravel Reverb** instalado y corriendo en el **puerto 6001** (no 8080 — ya lo usaba un servicio de Windows ajeno al proyecto, "AgentService").

### Insumos / variables de entorno nuevas
- `.env`: `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET`, `REVERB_HOST=localhost`, `REVERB_PORT=6001`, `REVERB_SERVER_PORT=6001`, `REVERB_SCHEME=http`, más los `VITE_REVERB_*` equivalentes para el frontend.
- Paquetes nuevos: `laravel/reverb` (backend), `laravel-echo` + `pusher-js` (frontend, hablan el protocolo de Reverb), `leaflet` (mapas, OpenStreetMap — sin costo por uso, sección 9.3).
- **Nota para vos:** si en algún momento se cambia de proveedor de mapas (ej. a Google Maps) o se suma geocodificación de direcciones, esas credenciales van en `.env` (nunca hardcodeadas) — avisar en su momento qué variable completar.

### Qué se construyó
- Ubicación en vivo del conductor (`DriverAvailabilityToggle.vue`, geolocalización del navegador) transmitida por canal privado de flota (`fleet.{id}`).
- Mapa reutilizable (`FleetMap.vue`, Leaflet + OpenStreetMap).
- "Pedir carrera": elegís un conductor puntual o "toda la flota disponible"; el primero en aceptar se queda con la carrera (con bloqueo de fila para evitar que dos conductores la acepten a la vez).
- Precio sugerido = distancia (fórmula de Haversine) × tarifa del conductor × recargo nocturno, siempre desglosado (`app/Services/{Haversine,PriceCalculator}.php`).
- "Carreras": solicitudes pendientes/entrantes en vivo, carrera en curso con mapa y posición del conductor en tiempo real, marcar completada y marcar pagada (pago manual, sin comisión — sección 5).

### Archivos clave
- `app/Models/{RideRequest,Ride}.php`
- `app/Http/Controllers/{DriverLocationController,RideRequestController,RideController}.php`
- `app/Events/{DriverLocationUpdated,RideRequested,RideRequestAccepted,RideRequestCancelled}.php`
- `resources/js/Pages/Ride/{Request,Show,Index}.vue`, `resources/js/Components/{FleetMap,DriverAvailabilityToggle}.vue`
- `routes/channels.php` (canal `fleet.{fleetId}`)

### Rutas principales
`/flota/solicitar`, `/flota/solicitudes`, `/solicitudes/{id}/aceptar|rechazar|cancelar`, `/carreras`, `/carreras/{id}`

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php` — incluye la carrera contra el reloj de "toda la flota" con dos conductores.

---

## Fase 3 — Confianza

**Alcance cubierto:** calificaciones y comentarios bidireccionales, perfiles públicos, directorio de conductores públicos (sección 3.4 y 3.6).

### Insumos
- Sin paquetes ni variables de entorno nuevas — reutiliza toda la infraestructura de las Fases 1 y 2.
- `is_public` de `driver_profiles` (existía desde la Fase 1, oculto) ahora expuesto en el formulario del conductor: por ahora **gratis para cualquiera**, sin plan de por medio (la Fase 5 lo conecta a una suscripción real).

### Qué se construyó
- Calificación bidireccional al completar una carrera (1-5 estrellas + comentario opcional), una vez por parte.
- Perfil público (`/perfil/{usuario}`): promedio de calificación, reseñas, y si es conductor, vehículo/tarifa/métodos de pago. Visible para cualquier usuario logueado, sin necesitar flota en común.
- Directorio público de conductores (`/conductores`): paginado, ordenado por cercanía (si compartís ubicación) o por mejor calificados. El botón "Invitar" reutiliza el mismo endpoint de invitaciones de la Fase 1.

### Archivos clave
- `app/Models/Review.php`
- `app/Http/Controllers/{ReviewController,PublicProfileController,DriverDirectoryController}.php`
- `resources/js/Pages/{Profile/Show,Directory/Index}.vue`, `resources/js/Components/RatingStars.vue`

### Rutas principales
`/carreras/{id}/calificar`, `/perfil/{user}`, `/conductores`

### Tests
`tests/Feature/Review/ReviewFlowTest.php`, `tests/Feature/Directory/DriverDirectoryTest.php`

---

## Fase 4 — Precios y negociación

**Alcance cubierto:** oferta y contraoferta sobre el precio sugerido, limitada a una ronda (sección 5).

### Insumos
- Sin paquetes ni variables de entorno nuevas.
- Migración que **cambia `ride_requests.status` de enum a texto simple** (para poder sumarle el valor `negotiating` sin depender de `doctrine/dbal`, que este proyecto no tiene instalado — ver nota de nombres más abajo). Hubo que soltar y volver a crear las llaves foráneas/índices de `fleet_id` y `driver_user_id` en el proceso.
- **Nombres más descriptivos a partir de esta fase** (pedido explícito del usuario): la tabla de historial de ofertas se llama `ride_price_offers` (no `ride_offers` a secas) y sus columnas son `offered_by_user_id`/`offered_amount`, para que se entienda de qué se trata sin tener que adivinar.

### Cómo queda el flujo
1. Al pedir la carrera, el cliente ve el precio sugerido (distancia × tarifa) y puede aceptarlo tal cual o proponer otro monto desde el arranque.
2. El conductor ve ese precio y puede **aceptarlo**, **rechazarlo** (solo si la solicitud es dirigida a él) o **contraofertar una vez** con otro número.
3. Si contraoferta, el cliente ya no puede volver a contraofertar — solo acepta o rechaza el número del conductor (sección 5: "una ronda para no alargar el proceso").
4. En una solicitud "a toda la flota", el primer conductor que responde —acepte o contraoferte— toma la negociación; a los demás deja de aparecerles.
5. El precio con el que se cierra la negociación es el que queda registrado en la carrera — no se recalcula en ningún otro momento.

### Archivos clave
- `app/Models/RidePriceOffer.php`, cambios en `app/Models/RideRequest.php` (`current_offered_price`, `negotiation_round`, `last_offer_made_by`, `negotiating_driver_user_id`)
- `app/Http/Controllers/RideRequestController.php` (`store` ahora fija el precio inicial, `accept` usa el precio vigente en vez de recalcularlo, `counter` es nuevo)
- `app/Events/RideRequestCountered.php`
- `resources/js/Pages/Ride/Request.vue` (precio editable antes de pedir), `resources/js/Pages/Ride/Index.vue` (aceptar/rechazar/contraofertar en vivo)

### Rutas principales
`/solicitudes/{id}/contraofertar`

### Tests
`tests/Feature/Ride/RidePriceNegotiationTest.php` — incluye que un conductor no pueda contraofertar dos veces y que, en "toda la flota", el segundo conductor en responder ya no pueda actuar sobre la solicitud.

---

## Fase 5 — Suscripciones

**Alcance cubierto:** planes reales de conductor y cliente con sus cupos y funciones (secciones 7.2, 7.3 y 7.5), multi-flota real para el plan Multi-flota, activación manual con auditoría completa, e indicadores básicos para "saber quién es quién" (sección 9.6 y 9.5-C). El panel institucional completo (entidad "Organización" separada) queda fuera de esta pasada — el plan Institucional existe y es asignable a un usuario normal, con cupos a medida vía overrides, pero no hay un panel de administración de convenios corporativos todavía.

### Insumos
- Sin paquetes nuevos — todo con lo que ya estaba instalado.
- Nuevo middleware `admin` (`app/Http/Middleware/EnsureUserIsAdmin.php`, alias registrado en `app/Http/Kernel.php`) que gatea `/admin/*` con `is_admin` (ya existía el campo desde la Fase 1).

### Modelo de datos
- `subscription_plans`: catálogo de planes por `owner_type` (`driver`/`client`) — precio, cupos, funciones (`public_visibility`, `priority_listing`, `verified_badge` del lado conductor; `max_fleets`, `max_drivers_per_fleet` del lado cliente) y `is_active` (un plan discontinuado deja de ofrecerse para altas nuevas sin afectar a quien ya lo tiene).
- `subscriptions`: la suscripción de un usuario a un plan — `status` (`active`/`grace`/`expired`/`cancelled`), fechas, quién la activó, y overrides `custom_max_*` para el plan Institucional (cupos a medida por convenio, sin cupo fijo en el catálogo).
- `subscription_changes`: bitácora de auditoría — plan anterior, plan nuevo, quién lo cambió (sección 9.6). Es el insumo de "Cambios recientes" del panel admin y del historial de "Mi plan".
- `pricing_settings`: fila única con los parámetros del precio sugerido (sección 5) — recargo nocturno y su horario.
- El catálogo inicial de planes (Gratis/Básico/Plus/Pro/Institucional del conductor, Gratis/Plus/Multi-flota del cliente) y la fila de `pricing_settings` **se siembran desde la propia migración**, no desde un seeder — así existen siempre, en cualquier entorno, apenas se corre `migrate`. De ahí en adelante, todo se administra desde el panel admin (ver más abajo), nunca volviendo a tocar código.

### Servicio central: `PlanLimits`
`app/Services/PlanLimits.php` resuelve, en cascada, "¿cuál es mi cupo y qué tengo habilitado?": suscripción vigente (con sus overrides) → plan Gratis de la base. Sin tercer nivel de respaldo en código — el plan Gratis de cada lado siempre existe (lo siembra la migración y el panel admin no permite borrarlo). Todos los controladores que antes leían límites fijos pasan por acá: `FleetInvitationController` (cupo por flota), `DriverInvitationController` (clientes de confianza), `DriverProfileController` (visibilidad pública — el valor que manda el formulario se ignora si el plan no la incluye), `FleetController` (cupo de flotas).

### Multi-flota real
Antes, un cliente tenía una sola flota auto-provisionada. Ahora:
- `/flotas` — lista de flotas del cliente (`Fleet/List.vue`), con botón "Crear flota" gateado por `max_fleets`.
- `/flota/{fleet}` — detalle de una flota puntual (`Fleet/Show.vue`, antes `Fleet/Index.vue`), con sus propias invitaciones y búsqueda de conductores.
- "Pedir carrera" (`/flota/solicitar`) acepta `?flota=id`; si el cliente tiene más de una, un selector permite cambiar sin volver a la lista.
- **Nota de compatibilidad:** las rutas con nombre `fleet.index`/`ride-requests.create` se mantienen (siguen sin exigir un id en la navegación general), pero `fleet.index` ahora apunta al listado en vez de ir directo al detalle — los enlaces de la Fase 1 (`AuthenticatedLayout`, `Dashboard`) siguen funcionando tal cual.

### Panel admin (acotado, no el panel institucional completo)
- `/admin/suscripciones` (`Admin/SubscriptionController` + `Admin/Subscriptions.vue`): busca usuarios, muestra su plan vigente de cada lado, activa/cambia un plan (con nota, ej. número de comprobante de transferencia — sección 7.5) y da de baja una suscripción antes de tiempo. Cada acción queda en `subscription_changes`.
- `/admin/planes` (`Admin/PlanController` + `Admin/Plans.vue`): **pantalla de mantenimiento del catálogo** — crear un plan nuevo, editar precio/cupos/funciones de uno existente, desactivarlo (deja de ofrecerse, quien ya lo tiene lo conserva) o eliminarlo (bloqueado para el plan Gratis y para cualquier plan que ya tenga suscriptores, activos o históricos, porque el borrado es en cascada). Nada del catálogo queda fijo en código a partir de acá.
- `/admin/tarifas` (`Admin/PricingSettingController` + `Admin/Pricing.vue`): **pantalla de mantenimiento del cálculo de precio** — recargo nocturno y su horario, leídos por `PriceCalculator` desde `pricing_settings`.
- `/admin/metricas` (`Admin/MetricsController` + `Admin/Metrics.vue`): usuarios/conductores/clientes/flotas/carreras completadas, cuántos suscriptores tiene cada plan (incluido el Gratis implícito) y una estimación de ingreso mensual recurrente (MRR).

### "Mi plan"
`/mi-plan/conductor` y `/mi-plan/cliente` (`MyPlanController` + `Plan/{Driver,Client}.vue`): plan vigente y cupo usado, catálogo con precios (solo planes activos, salvo que sea justo el que el usuario ya tiene — un plan discontinuado no debería desaparecerle a quien lo paga), instrucciones de cómo subir de plan (transferencia + confirmación manual, sección 7.5 — todavía no hay pasarela de pago) e historial de activaciones.

### Ajuste de esta misma fase: "nada quemado en código"
A pedido explícito, se revisó que ningún cálculo, plan o catálogo quedara fijo en el código sin una pantalla de mantenimiento detrás:
- El catálogo de planes pasó de un seeder aparte (`SubscriptionPlanSeeder`, eliminado) a sembrarse **dentro de la propia migración** de `subscription_plans` — así el plan Gratis de cada lado existe siempre, sin depender de correr un seeder, y `PlanLimits` ya no necesita un tercer nivel de respaldo en código.
- `config/arka.php` (límites de flota/conductor y parámetros de precio) **se eliminó por completo**: los límites del plan Gratis viven en `subscription_plans` (editable desde `/admin/planes`) y los parámetros de precio en `pricing_settings` (editable desde `/admin/tarifas`).
- Se agregó `is_active` a `subscription_plans` para poder discontinuar un plan sin borrarlo ni afectar a quien ya lo tiene.

### Archivos clave
- `app/Models/{SubscriptionPlan,Subscription,SubscriptionChange,PricingSetting}.php`, `app/Services/{PlanLimits,PriceCalculator}.php`
- `app/Http/Controllers/{FleetController,FleetInvitationController,MyPlanController}.php`, `app/Http/Controllers/Admin/{SubscriptionController,PlanController,PricingSettingController,MetricsController}.php`
- `database/migrations/2026_07_28_121451_create_subscription_plans_table.php` (siembra el catálogo inicial), `database/migrations/2026_07_28_150000_create_pricing_settings_table.php` (siembra la fila única de tarifas)
- `resources/js/Pages/Fleet/{List,Show}.vue`, `resources/js/Pages/Plan/{Driver,Client}.vue`, `resources/js/Pages/Admin/{Subscriptions,Plans,Pricing,Metrics}.vue`

### Rutas principales
`/flotas`, `/flota/{fleet}`, `/mi-plan/conductor`, `/mi-plan/cliente`, `/admin/suscripciones`, `/admin/planes`, `/admin/tarifas`, `/admin/metricas`

### Tests
`tests/Feature/Subscription/PlanLimitsTest.php` (cascada de resolución y overrides del Institucional), `tests/Feature/Fleet/MultiFleetTest.php` (cupo de flotas, visibilidad pública gateada), `tests/Feature/Admin/AdminSubscriptionTest.php` (activación con auditoría, reemplazo de plan anterior, baja, acceso restringido a admins), `tests/Feature/Admin/AdminPlanMaintenanceTest.php` (CRUD del catálogo, protección del plan Gratis y de planes con suscriptores, filtro por `is_active`), `tests/Feature/Admin/AdminPricingMaintenanceTest.php` (edición de tarifas y que `PriceCalculator` efectivamente lea el valor guardado).

---

## Fase 6 — Expresos

**Alcance cubierto:** rutas fijas y recurrentes para gente con horario de entrada/salida fijo (sección 4): publicación por el cliente, postulación de conductores, aceptación (queda un contrato activo), generación automática de la solicitud de carrera cada día que corresponda, y reporte de incumplimiento de condiciones pactadas.

### Insumos
- Sin paquetes nuevos.
- Nuevo comando Artisan `express:generate-rides` (`app/Console/Commands/GenerateExpressRides.php`), programado a diario a las 05:00 en `app/Console/Kernel.php`. En local no hace falta ningún proceso nuevo para probarlo — se puede correr a mano (`php artisan express:generate-rides`); `php artisan schedule:work` es opcional si se quiere ver la programación real funcionando.

### Modelo de datos
- `express_routes`: la ruta fija en sí — cliente dueño, origen/destino, `days_of_week` (JSON, 0=domingo..6=sábado) y `departure_time`, `offered_price`, `status` (`open`/`active`/`paused`/`cancelled`), y el conductor asignado una vez que se acepta una postulación.
- `express_conditions`: condiciones pactadas por ruta (ej. "aire acondicionado"), en una tabla aparte para poder referenciarlas puntualmente desde un reporte de incumplimiento.
- `express_applications`: postulaciones de conductores — igual que la negociación de precio de una carrera (sección 5), simplificada a una sola contraoferta (`proposed_price`) sin rondas múltiples.
- `express_incidents`: reporte de incumplimiento (sección 4.3), ligado a la carrera puntual del día en que pasó y, opcionalmente, a la condición concreta que se incumplió.
- `ride_requests.express_route_id` (nueva columna, nullable): traza qué solicitudes nacieron de la generación automática de un Expreso en vez de un pedido manual del cliente.

### Cómo queda el flujo
1. El cliente publica un Expreso desde `/expresos` (`Express/Index.vue`): nombre, origen/destino en el mapa, días y hora de salida, precio por carrera, condiciones opcionales. Queda `open`.
2. Los conductores de sus flotas ven la oferta en `/expresos-disponibles` (`Express/Available.vue` — mismo alcance de visibilidad que el resto de la plataforma: solo clientes de flotas propias, **no** un directorio global; documentado como simplificación de esta pasada) y se postulan, aceptando el precio ofrecido o proponiendo otro.
3. El cliente revisa las postulaciones desde `/expresos/{id}` (`Express/Show.vue`) y acepta una: la ruta pasa a `active` con ese conductor asignado, y las demás postulaciones pendientes se rechazan automáticamente (un solo conductor asignado a la vez).
4. Todos los días, `express:generate-rides` recorre las rutas activas y, para las que corresponden según `days_of_week`, genera una `RideRequest` dirigida al conductor asignado con el precio ya pactado (sin negociación) — el conductor igual tiene que aceptarla ese día puntual (puede estar de baja, por ejemplo). No duplica si el comando corre dos veces el mismo día.
5. Si no se cumple una condición pactada, el cliente lo reporta desde el historial de carreras generadas de esa ruta, ligado a la carrera puntual y (opcionalmente) a la condición concreta.
6. El cliente puede pausar (deja de generar carreras, sin cancelar el contrato), reanudar o cancelar un Expreso en cualquier momento.

### Fuera de esta pasada
Expresos institucionales compartidos por varias personas de una misma organización (sección 4.4, depende del panel institucional completo, todavía no construido), visibilidad del directorio público para postularse a un Expreso (por ahora solo conductores de la flota del cliente), edición de condiciones de un Expreso ya activo (solo se pueden fijar al publicar o mientras sigue `open`).

### Archivos clave
- `app/Models/{ExpressRoute,ExpressCondition,ExpressApplication,ExpressIncident}.php`, cambios en `app/Models/{RideRequest,User}.php`
- `app/Http/Controllers/{ExpressRouteController,ExpressApplicationController,ExpressIncidentController}.php`, `app/Policies/ExpressRoutePolicy.php`
- `app/Console/Commands/GenerateExpressRides.php`
- `resources/js/Pages/Express/{Index,Show,Available}.vue`

### Rutas principales
`/expresos`, `/expresos/{route}`, `/expresos-disponibles`, `/expresos/{route}/postulaciones`, `/postulaciones/{application}/{aceptar,rechazar,retirar}`, `/expresos/{route}/incidentes`

### Tests
`tests/Feature/Express/ExpressRouteFlowTest.php`: publicar con condiciones, visibilidad acotada a la flota, postular/aceptar/rechazar/retirar, que aceptar una postulación rechace las demás y active la ruta, autorización (solo el dueño edita/cancela), generación automática (incluida la protección contra duplicados y que respete los días de la semana), y reporte de incumplimiento (incluida la validación de que la carrera pertenezca a ese Expreso).

---

## Fase 7 — PWA y seguridad

**Alcance cubierto:** instalable como app (manifest + Service Worker), notificaciones push, seguimiento en vivo compartible, bitácora del viaje, botón SOS, y verificación visible del conductor (sección 8 y 9 del alcance).

### Insumos
- Paquete nuevo: `laravel-notification-channels/webpush` (Web Push API con VAPID — sin costo de servicio de terceros, sección 9.8).
- Variables de entorno nuevas: `VAPID_SUBJECT`, `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY` — generadas con `php artisan webpush:vapid` (no son credenciales de un servicio externo: se generan localmente, no hace falta registrarse en ningún lado). **Nota de entorno para este equipo Windows/Laragon:** ese comando necesita `OPENSSL_CONF` apuntando a un `openssl.cnf` real para poder crear la llave EC — acá funcionó con `OPENSSL_CONF="D:/Programas/Laragon/laragon/bin/php/php-8.5.8/extras/ssl/openssl.cnf" php artisan webpush:vapid`. Si se regeneran las llaves alguna vez, puede hacer falta lo mismo.
- La llave pública VAPID se comparte al frontend como prop de Inertia (`vapidPublicKey`, en `HandleInertiaRequests`), no como variable `VITE_*` duplicada.

### PWA
- `public/manifest.json` (ícono propio en `public/icons/icon.svg`, `display: standalone`, paleta de la marca) y `public/sw.js` (Service Worker): cachea un shell mínimo para mostrar `public/offline.html` si no hay conexión, y escucha eventos `push`/`notificationclick`. No cachea las páginas de la app a propósito — son datos en vivo (flota, precios, carreras), cachearlos de más mostraría información vieja.
- Metatags de instalación en `resources/views/app.blade.php` (`manifest`, `apple-touch-icon`, `apple-mobile-web-app-*`).
- El Service Worker se registra en `resources/js/app.js` al cargar la app.

### Notificaciones push
- `PushSubscriptionController` guarda la suscripción del navegador (`app/Models/User.php` usa el trait `HasPushSubscriptions` del paquete).
- Se activan a pedido del usuario desde el menú de cuenta ("Activar notificaciones"), nunca pidiendo permiso de entrada sin que lo busque.
- Primer (y por ahora único) evento instrumentado: `RideRequestedPushNotification`, disparada junto al WebSocket cuando se pide una carrera (dirigida al conductor puntual, o a todos los miembros activos si es "a toda la flota") — para avisar aunque la pestaña esté cerrada, que es justo lo que el WebSocket no puede hacer. Instrumentar más eventos queda documentado como próximo paso, no era necesario para demostrar el mecanismo completo.

### Seguimiento en vivo compartible y bitácora
- `RideController::trackingLink` genera una URL firmada temporalmente (`URL::temporarySignedRoute`, válida 24h) — sin login, protegida por firma en vez de sesión.
- `PublicRideTrackingController` (rutas bajo middleware `signed`, sin `auth`) sirve una página pública (`Public/RideTracking.vue`, sin `AuthenticatedLayout`) con polling cada 8s a un endpoint también firmado — deliberadamente sin WebSocket público para no tener que abrir un canal sin autenticación; el payload expone lo mínimo (nombre del conductor, vehículo, posición, estado), nunca precio ni datos de contacto.
- Bitácora (sección 8): la duración del viaje ahora se muestra en `Ride/Show.vue` junto al resto del desglose (que ya existía desde fases anteriores).

### Botón SOS
- `trusted_contacts`: contactos de confianza del usuario (`/contactos-de-confianza`), con al menos un teléfono o correo.
- `SosAlertController::store`: solo durante una carrera `in_progress`; guarda una "foto" de la emergencia (`sos_alerts`: conductor, vehículo, ubicación en ese momento) y manda `SosAlertMail` a cada contacto de confianza con correo, con el enlace de seguimiento en vivo (válido 48h para este caso). Un contacto con correo mal cargado no tumba el resto del envío (se loguea y sigue).
- `/admin/alertas-sos`: auditoría de solo lectura para el admin (sección 9.5-C).

### Verificación visible del conductor
- Reutiliza columnas que ya existían desde la Fase 1 sin usar (`license_photo_path`, `vehicle_photo_path`, `verification_status`, `verified_at`, `verified_by` en `driver_profiles`) — no hizo falta ninguna migración nueva para esto.
- `DriverProfileController::update` acepta la subida de ambas fotos (disco `public`; para producción con Cloudflare R2, basta con cambiar el driver del disco `public` en `config/filesystems.php` a `s3` con las credenciales de R2 en `.env` — el código que sube archivos no cambia). Subir un documento nuevo vuelve el estado a `pending` (lo que un admin ya aprobó era sobre la foto anterior).
- `/admin/verificaciones`: cola de revisión (solo perfiles con foto de licencia cargada); aprobar/rechazar.
- La insignia "✓ Conductor verificado" y la miniatura del vehículo se muestran en el perfil público y en el directorio.

### Fuera de esta pasada
Más eventos instrumentados con push (solo "nueva solicitud" por ahora), Service Worker con estrategia de cache más agresiva para assets estáticos (solo shell mínimo), verificación de organizaciones/Expresos institucionales (sigue atada al panel institucional completo, fuera de alcance desde la Fase 5).

### Archivos clave
- `app/Models/{TrustedContact,SosAlert}.php`, cambios en `app/Models/{User,DriverProfile,Ride}.php`
- `app/Http/Controllers/{TrustedContactController,SosAlertController,PublicRideTrackingController,PushSubscriptionController}.php`, `app/Http/Controllers/Admin/{SosAlertController,DriverVerificationController}.php`, cambios en `RideController` y `RideRequestController`
- `app/Notifications/RideRequestedPushNotification.php`, `app/Mail/SosAlertMail.php`, `resources/views/emails/sos-alert.blade.php`
- `public/{manifest.json,sw.js,offline.html,icons/icon.svg}`, `resources/js/push.js`
- `resources/js/Pages/{Security/TrustedContacts,Public/RideTracking,Admin/SosAlerts,Admin/DriverVerifications}.vue`

### Rutas principales
`/contactos-de-confianza`, `/carreras/{ride}/seguimiento`, `/carreras/{ride}/sos`, `/seguimiento/{ride}` (pública, firmada), `/push-subscriptions`, `/admin/alertas-sos`, `/admin/verificaciones`

### Tests
`tests/Feature/Security/{TrustedContactTest,RideTrackingTest,SosAlertTest,DriverVerificationTest,PushNotificationTest}.php`: CRUD de contactos con la validación de "al menos un dato de contacto", enlace firmado (generación, rechazo por vencido/alterado, ocultamiento de ubicación fuera de `in_progress`), SOS (envío de correo, protección de estado, autorización, auditoría solo-admin), verificación (subida resetea a pendiente, aprobar/rechazar, acceso restringido), y que pedir una carrera efectivamente dispare la notificación push al conductor correcto (dirigida y "toda la flota").

---

## Ajuste transversal: diseño del header (esta misma pasada)

A pedido del usuario (con capturas de referencia de Google), se mejoró el header de `AuthenticatedLayout.vue`, **en escritorio y en móvil por igual**:
- Búsqueda (atajo al directorio), ayuda (`Modal` con tips básicos), grilla de accesos rápidos (mismo contenido que el bottom sheet de móvil, en un `Dropdown` ampliado), y avatar circular con iniciales para el menú de cuenta.
- Reemplazó por completo al viejo menú de hamburguesa + panel deslizable de Breeze en móvil — quedaba redundante con la barra inferior + FAB que ya existía.
- También se corrigió que las páginas no tenían `px-4` en mobile antes del breakpoint `sm:`, dejando las tarjetas pegadas al borde de la pantalla — corregido en las 20 páginas que tenían el patrón.

**Segunda pasada** (el usuario marcó el resultado como "confuso" y "el típico"): el header de escritorio pasó de `flex justify-between` (nav pegada al logo, hueco vacío enorme antes de los íconos) a una **grilla de 3 zonas** (`grid-cols-[auto_1fr_auto]`: logo / nav centrada / cuenta). Los 4 links de nav pasaron de texto-con-subrayado a **pastillas con ícono + texto** agrupadas en un contenedor `rounded-full`, con los mismos íconos que ya usaba la barra inferior de móvil (coherencia entre plataformas). Se sacó el ítem "Inicio" de esa pastilla (el logo ya cumple esa función, hacerlo dos veces era ruido). También se detectó y corrigió que el grid de accesos rápidos (ícono de puntos) y el menú de cuenta **repetían** varias opciones que ya estaban en el bottom sheet del FAB de móvil — el menú de cuenta quedó acotado a lo estrictamente de cuenta (perfil, perfil público, activar notificaciones, cerrar sesión), y el ícono de puntos quedó oculto en móvil (`hidden sm:block`) porque el FAB ya cumple esa función ahí.

El logo (`Components/ApplicationLogo.vue`) era el ícono genérico por defecto de Laravel/Breeze — nunca se había reemplazado. Ahora es un **logotipo tipográfico de dos colores** ("Arka" + "01" en el verde de marca), sin ícono. Se usa en el header, en `GuestLayout` y en `Welcome.vue` (con distintos tamaños vía prop `size`).

Memoria de diseño guardada para las próximas fases: `feedback_header_escritorio_estilo_google.md` y `feedback_margen_horizontal_moviles.md`.

---

## Ajuste transversal: pantallas de sesión + login con Google (esta misma pasada)

A pedido del usuario (con una captura de referencia de un login con panel partido), se rediseñó `GuestLayout.vue` — usado por Login, Register, y las pantallas de recuperar/confirmar contraseña:
- Layout partido en escritorio (`lg:flex`): panel de marca a la izquierda + tarjeta de formulario a la derecha; en móvil, una sola columna con el logo chico arriba de la tarjeta (sin el panel, que se oculta).
- El panel de marca es un componente nuevo y **reutilizable**, `Components/AuthBrandingPanel.vue` — título, bajada y viñetas configurables por prop (con defaults tomados del pitch de la sección 0 del alcance: "Solo suben los tuyos"), más un slot para CTAs — pensado para poder reusarlo en otras pantallas futuras (ej. una landing de precios), no solo en el login. Adaptado al tema oscuro fijo de Arka01 (gradiente oscuro con acento menta) en vez del panel claro de la referencia.

### Login con Google
- Paquete nuevo: `laravel/socialite`.
- Variables de entorno nuevas (credenciales de un proyecto OAuth propio en Google Cloud Console — **hay que completarlas**): `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`. Instrucciones de dónde conseguirlas y qué pegar como "URI de redirección autorizado" quedaron como comentario arriba de esas variables en `.env`. **Mientras no se completen, el botón "Continuar con Google" no aparece** (chequeo automático vía el prop compartido `googleLoginEnabled`), así que no hay nada roto en el login mientras tanto.
- `google_id` nuevo en `users` (nullable, único). `GoogleAuthController` (`redirect`/`callback`): si ya existe una cuenta con ese correo (por ejemplo, una de las cuentas de demo) se linkea sola; si no, se crea una cuenta nueva con una contraseña aleatoria inutilizable (esa cuenta siempre va a entrar por Google). El login con usuario/contraseña de siempre sigue intacto, esto es una alternativa, no un reemplazo.
- El avatar de Google se guarda en `avatar_path` (columna que ya existía sin usar desde la Fase 1) y se expone como `avatar_url` calculado — listo para cuando se quiera mostrar la foto real en vez de las iniciales.
- Botón "Continuar con Google" en `Login.vue` y `Register.vue`, con el ícono oficial de 4 colores.

### Archivos clave
- `app/Http/Controllers/Auth/GoogleAuthController.php`, cambios en `app/Models/User.php` (`google_id`, `avatar_path`, `avatar_url`)
- `resources/js/Components/{ApplicationLogo,AuthBrandingPanel}.vue`, `resources/js/Layouts/GuestLayout.vue`
- `database/migrations/2026_07_31_090000_add_google_id_to_users_table.php`

### Tests
`tests/Feature/Auth/GoogleAuthTest.php`: redirección a Google, alta de cuenta nueva, linkeo de una cuenta existente por email (sin duplicarla), y que un segundo login reuse la misma cuenta.

---

## Ajuste transversal: la navegación respeta los perfiles activos (esta misma pasada)

El usuario detectó que la navegación mostraba accesos de cliente y de conductor mezclados sin importar cuáles había activado — ej. un conductor "puro" (sin flota propia) veía igual "Mis Flotas" y "Pedir una carrera". Se corrigió con dos flags nuevos, compartidos por Inertia para el usuario logueado (`App\Http\Middleware\HandleInertiaRequests`):
- `auth.isDriver`: tiene `driver_profile` (activó "Convertirme en conductor").
- `auth.hasFleet`: es dueño de al menos una flota.

Regla aplicada en `AuthenticatedLayout.vue` (nav de escritorio, barra inferior, grilla de accesos rápidos y bottom sheet, todos alimentados por la misma lógica):
- **Accesos de cliente** (Mis Flotas, Pedir una carrera, Directorio, Mis Expresos, Mi plan de cliente): visibles salvo que el usuario sea "solo conductor" (`isDriver` sin `hasFleet`) — el rol de cliente es el implícito por defecto (no hay un alta explícita como con el de conductor), así que un usuario nuevo lo sigue viendo.
- **Accesos de conductor** (Mis clientes de confianza, Expresos disponibles, Mi plan de conductor): visibles solo si `isDriver`.
- **Siempre visibles**: Carreras, Contactos de confianza, "Mi perfil de conductor" (es la puerta de entrada para activar ese rol, tiene que verse aunque todavía no seas conductor), cuenta y panel admin.
- Si el usuario tiene los dos roles (activó conductor Y ya tiene una flota propia), ve todo — "cambiar como cliente" siendo conductor es, en la práctica, crear la primera flota.

De paso, se agregó al perfil público (`Profile/Show.vue` + `PublicProfileController`) una marca de qué rol(es) tiene esa persona (insignias "Cliente"/"Conductor", mismo criterio que la navegación) y una insignia compacta de calificación (★ 4.5) junto al nombre, además de las estrellas detalladas que ya existían más abajo.

### Archivos clave
- `app/Http/Middleware/HandleInertiaRequests.php`, `app/Http/Controllers/PublicProfileController.php`
- `resources/js/Layouts/AuthenticatedLayout.vue`, `resources/js/Pages/Profile/Show.vue`

### Tests
`tests/Feature/NavigationRolesTest.php` (los flags `isDriver`/`hasFleet` reflejan el estado real para usuario nuevo, conductor puro, y ambos roles), `tests/Feature/PublicProfileTest.php` (las insignias de rol del perfil público son correctas en los mismos tres casos).

---

## Ajuste transversal: el admin ve una experiencia propia y clara (esta misma pasada)

El usuario entró con la cuenta admin de demo y no encontraba ninguna diferencia real frente a una cuenta normal: veía "Mis Flotas" y "Pedir una carrera" (que no le sirven de nada, un admin no es cliente ni conductor) y el panel de administración estaba escondido dentro de la grilla de accesos rápidos, mezclado con todo lo demás.

Cambios:
- **`AuthenticatedLayout.vue`**: `showClientNav`/`showDriverNav` ahora son `false` para cualquier cuenta con `is_admin` — deja de ver nav de cliente/conductor por completo (pastillas de escritorio, barra inferior, bottom sheet).
- El admin tiene su propio acceso **destacado y permanente**, no escondido: una pastilla "Panel admin" en el centro del header de escritorio, y un tab dedicado "Admin" en la barra inferior de móvil (reemplazando a Flotas/Carreras, que no aplican).
- **`Dashboard.vue`** ("Inicio"): mensaje de bienvenida distinto para admin, con un link directo al panel en vez del típico "armá tu flota / convertite en conductor".
- **Insignia de rol + calificación en el menú de cuenta**: antes solo estaban en el perfil público; ahora también aparecen directo en el dropdown del avatar (nombre + correo + insignia "Administrador"/"Cliente"/"Conductor" + `★ n.n` si tiene calificaciones), para que se vea "quién sos" sin tener que entrar a otra pantalla. Nuevo par de props compartidos por Inertia: `auth.averageRating` y `auth.reviewCount` (`HandleInertiaRequests`).
- **`Layouts/AdminLayout.vue`** (nuevo): sub-nav persistente para las 6 pantallas de `/admin/*` (Suscripciones, Planes, Tarifas, Indicadores, Verificaciones, Alertas SOS), con la pastilla activa resaltada. Reemplaza las filas de links cruzados que cada página armaba a mano y por separado (fácil que quedaran desactualizadas). Las 6 páginas (`Admin/{Subscriptions,Plans,Pricing,Metrics,DriverVerifications,SosAlerts}.vue`) ahora usan este layout en vez de `AuthenticatedLayout` + cabecera manual.

### Archivos clave
- `resources/js/Layouts/{AuthenticatedLayout,AdminLayout}.vue`, `resources/js/Pages/Dashboard.vue`
- `resources/js/Pages/Admin/{Subscriptions,Plans,Pricing,Metrics,DriverVerifications,SosAlerts}.vue`
- `app/Http/Middleware/HandleInertiaRequests.php`

### Tests
Sin tests nuevos — es un ajuste puramente de navegación/presentación sobre lógica de roles ya cubierta por `NavigationRolesTest`. Verificado con `php vendor/bin/phpunit` (125 tests OK), `./vendor/bin/pint` y `npm run build`.

---

## Ajuste transversal: sesión única por cuenta y zonas del Ecuador (esta misma pasada)

El usuario agregó dos consideraciones nuevas directo en `Arka01_Alcance_Proyecto.md` (sección de "CONSIDERACIÓN DE SEGURIDAD ADICIONAL", al final del documento).

### A. Sesión única por dispositivo

Si una cuenta ya tiene una sesión activa en un equipo, no se puede iniciar una segunda en otro hasta cerrar la primera — evita que una cuenta (cliente o conductor) quede compartida sin control de quién administra la flota en cada momento.

- `SESSION_DRIVER` pasó de `file` a `database` (`.env`, `.env.example`, y `phpunit.xml` para que los tests también puedan verificarlo): así se puede consultar qué sesiones están activas por usuario.
- `App\Listeners\EnforceSingleActiveSession`, enganchado al evento `Illuminate\Auth\Events\Login` (`AppServiceProvider::boot()`): si ya existe otra fila en `sessions` con el mismo `user_id` y actividad reciente (dentro de `session.lifetime`), deshace el login y lanza `App\Exceptions\ActiveSessionExistsException`.
- Cada punto de entrada convierte esa excepción en su propio flujo: `LoginRequest::authenticate()` la vuelve un error de validación normal (mismo lugar donde ya vive "contraseña incorrecta"); `GoogleAuthController::callback()` redirige a `/login` con un mensaje de estado.
- **Se sacó "Recordarme"** (checkbox del login, `remember: true` de Google): un remember-token permitiría reautenticarse en silencio sin pasar por el formulario de login, saltándose el aviso claro que pide cerrar la otra sesión primero. Sesión = hasta que se cierra explícitamente o expira por inactividad (`session.lifetime`, 120 min por defecto) — eso mismo es lo que permite recuperar el acceso solo si alguien se olvidó de cerrar sesión en otro equipo, sin necesitar un botón de "cerrar sesiones remotas".

### B. Zonas del Ecuador (ciudades y sectores)

Al pedir una carrera, además del mapa, el cliente ahora puede indicar el sector de dónde sale y a dónde va (ej. "Sauces 1" → "Samanes 3"), para que el conductor lo entienda de un vistazo sin tener que abrir el mapa — y arranca por defecto en la ciudad donde vive (configurable en "Mi perfil").

- Catálogo nuevo, **editable por el admin, nada quemado en código**: `cities` (nombre, provincia, activa) → `sectors` (nombre, activo, por ciudad). Sembrado con un punto de partida real: Quito, Guayaquil (incluye "Sauces 1"/"Samanes 3" del ejemplo del usuario) y Cuenca.
- Pantalla de mantenimiento `Admin/Locations.vue` (`/admin/zonas`, agregada al sub-nav de `AdminLayout`): alta/edición/baja de ciudades y de sus sectores. Una ciudad con sectores cargados no se puede borrar (hay que vaciarla primero); un sector sí se puede borrar directo — las referencias existentes (usuarios, solicitudes, carreras) quedan en `null` (`nullOnDelete`), no se rompen.
- `users.city_id` (nullable, FK a `cities`): la ciudad donde vive, editable desde "Mi perfil" (`UpdateProfileInformationForm.vue`).
- `ride_requests` y `rides` ganaron `origin_sector_id`/`destination_sector_id` (FK a `sectors`, nullable) — `rides` los copia de la solicitud al aceptar, mismo criterio que el resto de columnas de origen/destino (sección 9.6: cada carrera guarda su propia "foto").
- `Ride/Request.vue`: nueva tarjeta "Zona" con selector de ciudad (arranca en la de "Mi perfil"), selector de sector de origen y de destino (filtrados por esa ciudad), y un campo de referencia de origen que antes no existía en el formulario (ya existía la de destino).
- El sector se ve de un vistazo en `Ride/Index.vue` (solicitudes entrantes del conductor y "esperando respuesta" del cliente, incluida la variante en vivo por WebSocket vía `RideRequested::broadcastWith()`) y en `Ride/Show.vue`.

### Archivos clave
- `app/Listeners/EnforceSingleActiveSession.php`, `app/Exceptions/ActiveSessionExistsException.php`, `app/Providers/AppServiceProvider.php`
- `app/Http/Requests/Auth/LoginRequest.php`, `app/Http/Controllers/Auth/GoogleAuthController.php`, `resources/js/Pages/Auth/Login.vue`
- `app/Models/{City,Sector}.php`, `app/Http/Controllers/Admin/LocationController.php`, `resources/js/Pages/Admin/Locations.vue`
- `app/Http/Controllers/{ProfileController,RideRequestController,RideController}.php`, `resources/js/Pages/Ride/{Request,Index,Show}.vue`, `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`

### Tests
`tests/Feature/Auth/SingleActiveSessionTest.php` (bloquea login normal y con Google mientras la otra sesión sigue activa; permite de nuevo una vez expirada), `tests/Feature/Admin/LocationMaintenanceTest.php` (CRUD de ciudades/sectores, solo admin, no se borra una ciudad con sectores, borrar un sector no rompe una solicitud que ya lo usaba), casos nuevos en `tests/Feature/Ride/RideRequestFlowTest.php` (guarda y copia los sectores a la carrera) y `tests/Feature/ProfileTest.php` (fijar la ciudad por defecto). Verificado con `php vendor/bin/phpunit` (137 tests OK), `./vendor/bin/pint` y `npm run build`.

**Nota:** se corrió `php artisan migrate:fresh --seed` sobre la base de desarrollo para aplicar el nuevo esquema — cualquier dato de prueba manual cargado antes de esta pasada se reemplazó por el elenco de demo actualizado.

---

## Ajuste transversal: logging con trazabilidad (esta misma pasada)

El usuario reportó que "pedir una carrera" mostraba un 404 en `/flota/1`. Diagnóstico: el controlador y el flujo completo (137 tests) funcionan bien de forma aislada; la causa más probable es que el `migrate:fresh --seed` de la pasada anterior vació la tabla `sessions` (recién pasada a `SESSION_DRIVER=database`) mientras el navegador todavía tenía una sesión vieja abierta — se resuelve cerrando sesión y volviendo a entrar. El problema real detrás de esto: **un caso así no dejaba ningún rastro en el log**, porque Laravel no reporta los 404 por defecto (se asumen rutina, tráfico de bots). De ahí el pedido explícito de mejorar el logging.

Cambios:
- **Contexto automático por request** (`app/Http/Middleware/LogRequestContext.php`, nuevo, en el grupo `web` del Kernel justo después de `StartSession`): agrega `request_id`, `method`, `url` y `user_id` vía `Illuminate\Support\Facades\Context`. Laravel adjunta este contexto automáticamente a **toda** línea de log de ese request — la propia y la del framework — sin tener que repetirlo a mano en cada `Log::info`/`Log::error`.
- **`config/logging.php`**: el canal `stack` (el default) ahora apunta a `daily` en vez de `single` — un archivo por día (14 días de retención) en vez de un único log que crece para siempre.
- **`app/Exceptions/Handler.php`**: los 404 (`NotFoundHttpException`/`ModelNotFoundException`) para un usuario **autenticado** ahora sí quedan en el log (`Log::warning`, con la excepción y el mensaje) — un 404 de un usuario ya logueado casi siempre es una señal real (un id que ya no existe, un enlace roto), a diferencia de un bot probando URLs al azar.
- **Eventos clave del flujo de carreras** (`app/Http/Controllers/RideRequestController.php`): `Log::info` al solicitar, aceptar, contraofertar, rechazar y cancelar una carrera, cada uno con los ids relevantes (`ride_request_id`, `fleet_id`, `client_user_id`, `driver_user_id`, montos).
- **`app/Listeners/EnforceSingleActiveSession.php`**: `Log::warning` cuando bloquea un login por sesión activa en otro dispositivo — para que un caso como el de hoy (si hubiera sido por acá) quede visible de inmediato.

### Archivos clave
- `app/Http/Middleware/LogRequestContext.php`, `app/Http/Kernel.php`, `config/logging.php`
- `app/Exceptions/Handler.php`, `app/Http/Controllers/RideRequestController.php`, `app/Listeners/EnforceSingleActiveSession.php`

### Tests
Sin tests nuevos — es instrumentación de logging sobre lógica ya cubierta. Verificado con `php vendor/bin/phpunit` (137 tests OK) y `./vendor/bin/pint`.

---

## Ajuste transversal: login múltiple, verificación por WhatsApp, usuario y código de socio (esta misma pasada)

El usuario pidió login con teléfono además de Google, y al definir el mecanismo (WhatsApp para verificar, en vez de SMS por costo) sumó tres pedidos más: mejorar el formulario de registro, generar un "usuario" legible por iniciales, y un código numérico de socio desde el 500 — ambos buscables para agregar a alguien a una flota.

### A. Verificación de teléfono por WhatsApp
- `app/Services/WhatsAppVerificationSender.php`: manda el código como mensaje de plantilla vía la API oficial de WhatsApp Cloud (Meta) — WhatsApp exige una plantilla pre-aprobada para el primer mensaje de una cuenta de negocio, no admite texto libre. Plantilla configurable (`WHATSAPP_VERIFICATION_TEMPLATE`, default `arka01_verificacion`), con un único parámetro de cuerpo para el código; hay que crearla y esperar su aprobación en Meta Business Manager (instrucciones en `.env.example`).
- **Si no está configurado** (sin `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID`), el registro sigue funcionando: el teléfono queda auto-verificado en vez de bloquear a alguien por una integración pendiente (mismo criterio que `googleLoginEnabled`).
- `users` ganó `phone_verification_code` (hasheado, igual que la contraseña) y `phone_verification_expires_at` (vence en 10 minutos) — ver `User::issuePhoneVerificationCode()` / `verifyPhoneCode()`.
- `app/Http/Middleware/EnsurePhoneIsVerified.php` (alias `phone_verified`), aplicado al `/dashboard` junto al `verified` de email ya existente: si el usuario tiene teléfono cargado y no verificado, lo manda a `/verificar-telefono` (`Auth/VerifyPhone.vue`, código de 6 dígitos + reenviar). Si no tiene teléfono (cuentas solo-Google), no se le exige nada.

### B. Login múltiple (teléfono, correo o usuario)
- `LoginRequest` cambió su campo de `email` a `login`: resuelve si es correo (tiene "@"), teléfono (solo dígitos, con o sin el "+"/el 0 inicial — se prueba también sin el 0 troncal, porque así es como la gente lo recuerda de memoria) o el usuario autogenerado, y autentica contra esa cuenta.
- **Se sacó "Recordarme"** del login (checkbox y `remember: true` de Google): la sesión única por cuenta (pasada anterior) necesita que cada re-login pase siempre por acá, nunca en silencio por un remember-token.

### C. Usuario autogenerado y código de socio
- `App\Services\UsernameGenerator`: primera letra del primer nombre + primer apellido (ej. "Juan Pérez" → `jperez`). Con colisión, prueba con la inicial del segundo apellido, después la del segundo nombre, y si sigue chocando suma un número. Se genera solo al crear la cuenta (`User::booted()`), tanto por el formulario como por Google.
- `App\Services\MemberCodeSequence` + tabla `member_code_sequences` (fila única con lock explícito por transacción): código numérico secuencial desde el 500, asignado también solo al crear la cuenta. No se puede usar el AUTO_INCREMENT nativo de `users.id` para esto — MySQL permite solo una columna auto-incremental por tabla.
- Ambos son buscables (además de nombre/teléfono/código de invitación) desde "Buscar conductor" en `Fleet/Show.vue` (`FleetController::searchDrivers`), y se muestran en el perfil público (`Profile/Show.vue`).

### D. Registro más intuitivo, seguro y con validaciones
- Teléfono en dos partes: selector de código de país (Ecuador +593 por defecto, más Perú/Colombia/Venezuela/EE.UU./España — lista fija de indicativos reales, no un catálogo de negocio) + número local, combinados en formato E.164 antes de guardar.
- Contraseña más exigente (`Rules\Password::defaults()->min(8)->mixedCase()->numbers()`), con una lista de requisitos que se marca en vivo mientras se escribe (más intuitivo que un solo error genérico al mandar el formulario).

### Archivos clave
- `app/Services/{UsernameGenerator,MemberCodeSequence,WhatsAppVerificationSender}.php`
- `app/Http/Controllers/Auth/{RegisteredUserController,PhoneVerificationController}.php`, `app/Http/Requests/Auth/LoginRequest.php`
- `app/Http/Middleware/EnsurePhoneIsVerified.php`, `app/Models/User.php`, `config/services.php`
- `resources/js/Pages/Auth/{Register,Login,VerifyPhone}.vue`
- `app/Http/Controllers/FleetController.php`, `resources/js/Pages/Fleet/Show.vue`, `resources/js/Pages/Profile/Show.vue`

### Tests
`tests/Feature/Auth/{UsernameGeneratorTest,PhoneVerificationTest}.php` (nuevos), casos agregados en `RegistrationTest` (asigna usuario/código, auto-verifica sin WhatsApp configurado), `AuthenticationTest` (login por teléfono, con y sin el 0 inicial, y por usuario), `SingleActiveSessionTest` (campo renombrado), `FleetInvitationFlowTest` (búsqueda por usuario/código). `UserFactory` ahora marca `phone_verified_at` por defecto (mismo criterio que `email_verified_at`), para no romper el resto de la suite con el nuevo gate. Verificado con `php vendor/bin/phpunit` (153 tests OK), `./vendor/bin/pint` y `npm run build`.

**Nota:** se corrió `php artisan migrate:fresh --seed` de nuevo para el nuevo esquema — las cuentas de demo quedaron con usuario/código de socio (500 en adelante) y teléfono ya verificado.

**Pendiente del usuario:** completar `WHATSAPP_TOKEN` y `WHATSAPP_PHONE_NUMBER_ID` en `.env` (Meta Business Manager) y crear/aprobar la plantilla `arka01_verificacion` para que la verificación por WhatsApp mande mensajes de verdad — mientras tanto, el registro auto-verifica el teléfono sin bloquear a nadie.

---

## Fix: "Pedir carrera" daba 404 (bug preexistente, no de esta pasada)

`routes/web.php` registraba `GET /flota/{fleet}` (fleet.show) **antes** que `GET /flota/solicitar` (ride-requests.create). Laravel matchea en orden de registro, así que "solicitar" caía como si fuera un id de flota → `ModelNotFoundException` → 404, sin llegar nunca al controlador. Ningún test lo detectó porque ninguno hacía `GET` a esa pantalla (solo `POST` al guardar). Arreglado moviendo las rutas literales (`/flota/solicitar`, `/flota/solicitudes`) antes de la ruta comodín `/flota/{fleet}`. Test de regresión: `RideRequestFlowTest::test_the_request_screen_loads_and_is_not_swallowed_by_the_fleet_show_route`.

---

## Ajuste transversal: batería de observaciones — tiempo real, pedir carrera, planes (esta misma pasada)

Tanda grande de bugs y features a partir de observaciones puntuales del usuario tras probar la app a fondo.

### Bugs reales encontrados y arreglados
- **Invitación de flota nunca llegaba en vivo** (había que refrescar): `FleetInvitationController::store()` no disparaba ningún evento — no era un bug de WebSocket, la funcionalidad directamente no existía. Se agregó `App\Events\FleetInvitationCreated` + `App\Notifications\FleetInvitationPushNotification`, y `Driver/Invitations.vue` ahora escucha `.fleet-invitation.created` en su canal personal.
- **El chofer no veía el nombre/calificación del cliente al llegarle una solicitud**: la carga inicial de `Ride/Index.vue` nunca tuvo `client_name` (el modelo trae `client: {name}`, no un campo plano) — solo se veía bien si llegaba por WebSocket en vivo, que sí lo mandaba flat. Se unificó en `RideController::index()` para que ambos caminos manden exactamente los mismos campos (`client_name`, `client_rating`, `client_review_count`, `client_member_code`).
- **No existía el symlink de `storage` público** (`php artisan storage:link`) — esto ya afectaba fotos de licencia/vehículo de conductores desde antes, no solo los comprobantes de pago nuevos. Corregido.

### Sesión única (pasada anterior): aviso por correo
`App\Listeners\EnforceSingleActiveSession` ahora manda `App\Mail\ConcurrentLoginAttemptMail` al dueño real de la cuenta (no a quien intentó entrar) cuando bloquea un login por sesión activa en otro dispositivo.

### Dashboard con indicadores reales
`app/Http/Controllers/DashboardController.php` (nuevo — antes la ruta era un closure sin datos): indicadores de conductor (clientes de confianza, solicitudes pendientes, ganado este mes, calificación) y de cliente (conductores en flota, solicitudes en curso, carreras completadas, calificación). Si la cuenta es cliente y conductor a la vez, se muestran primero los de conductor. "Convertirme en conductor" ahora dice "Mi perfil de conductor" si ya activó ese rol.

### Zonas del Ecuador: catálogo completo + coordenadas
Se agregó al menos una ciudad por cada una de las 24 provincias (antes solo Quito/Guayaquil/Cuenca), cada una con `lat`/`lng` — al elegir otra ciudad en "Pedir carrera", el mapa se recentra ahí (`FleetMap.vue` ahora reacciona a cambios de `center` después de montado, no solo al inicio).

### Pedir carrera: rediseño grande
- **De mi flota / del directorio público / ambos**: toggle nuevo en `Ride/Request.vue`. `RideRequestController::create()` ahora arma ambas listas con estado y categoría ya resueltos (`driverCardData()`), y `store()` acepta dirigir la solicitud a un conductor público, no solo de la flota.
- **Estado por color**: disponible (verde), en carrera ahora mismo (naranja — se calcula contra `rides` con `status=in_progress`), desconectado (gris, "apagado" con opacity+grayscale). Los disponibles se listan primero.
- **Categoría por reputación** (consideración agregada al alcance: diamante/oro/plata/cobre): `App\Services\DriverCategory`, calculada por promedio de calificación + cantidad de reseñas — no es un plan pago, es reputación ganada.
- **Trazado real del recorrido**: se mantuvo Leaflet/OpenStreetMap (gratis) en vez de pasar a Google Maps — el usuario eligió esa opción para no generar costos de facturación. `FleetMap.vue` ahora acepta una prop `route` y la pide a OSRM (`router.project-osrm.org`, gratis, sin API key) apenas hay origen y destino.
- **Feedback en vivo mientras se espera** (tipo Uber): mensajes según cuánto tiempo lleva pendiente la solicitud, y un botón "Subir oferta" (`RideRequestController::raiseOffer()`, nuevo, con su propio evento `RideRequestPriceRaised`) para cuando nadie responde.
- **Botón "Abrir en Google Maps" externo** (gratis, sin API key — un link, no la API): en `Ride/Show.vue`, visible para el conductor mientras la carrera está en curso, para ir a buscar al cliente o llevarlo al destino con navegación real.

### Planes: elegir plan + comprobante de pago
Sigue sin haber pasarela de pago (sección 7.5). Nuevo: tabla `subscription_requests` — el usuario elige un plan (botón "Elegir"), sube una captura del comprobante, y el pedido queda "esperando revisión". El admin lo ve en `/admin/suscripciones` con la imagen, y aprobar activa la suscripción real reusando la misma lógica que la activación manual de siempre. Componente compartido `Components/SubscriptionRequestPanel.vue` entre Mi plan de conductor y de cliente.

### Archivos clave
- `app/Events/{FleetInvitationCreated,RideRequestPriceRaised}.php`, `app/Notifications/FleetInvitationPushNotification.php`, `app/Mail/ConcurrentLoginAttemptMail.php`
- `app/Http/Controllers/{DashboardController,SubscriptionRequestController}.php`, `app/Models/SubscriptionRequest.php`, `app/Services/DriverCategory.php`
- `app/Http/Controllers/{RideRequestController,RideController,FleetInvitationController,Admin/SubscriptionController}.php`
- `resources/js/Pages/{Dashboard,Ride/Request,Ride/Index,Ride/Show,Driver/Invitations,Plan/Driver,Plan/Client,Admin/Subscriptions}.vue`, `resources/js/Components/{FleetMap,SubscriptionRequestPanel}.vue`

### Tests
`tests/Feature/Plan/SubscriptionRequestFlowTest.php` (nuevo), casos agregados en `RideRequestFlowTest` (subir oferta), `FleetInvitationFlowTest` (broadcast en vivo). Verificado con `php vendor/bin/phpunit` (161 tests OK), `./vendor/bin/pint` y `npm run build`.

**Nota:** se corrió `php artisan migrate:fresh --seed` para el nuevo esquema.

### Fix rápido posterior
`RideController::index()` usaba `->withAvg('client.reviewsReceived', 'rating')` — Eloquent no soporta encadenar un `belongsTo` con un `hasMany` así (el punto sí funciona en `with()` para eager loading, no en `withAvg`/`withCount`). Cambiado a la misma consulta aparte por `Review::whereIn(...)->groupBy(...)` que ya usa `DriverDirectoryController`.

### Ajuste: módulo de suscripción con historial + validación de cupo al elegir plan
- **`App\Services\SubscriptionPlanEligibility`** (nuevo): antes de dejar elegir un plan (o que un admin apruebe un pedido), revisa que el uso actual entre en el cupo de ESE plan — no se puede pasar a un plan (ni volver al Gratis) si ya se tienen más clientes de confianza, flotas, o conductores por flota de los que ese plan permite. Aplicado en `SubscriptionRequestController::store()` y `Admin\SubscriptionController::approveRequest()` (la activación manual directa del admin sigue sin bloquearse, para casos que necesite forzar a mano).
- **Historial de pagos**: `MyPlanController` ahora manda `requestHistory` (todos los pedidos — aprobados, rechazados, pendientes — no solo el último) a `Plan/Driver.vue` y `Plan/Client.vue`, mostrado con `Components/SubscriptionRequestHistory.vue` (nuevo, compartido entre los dos).

### Tests
`SubscriptionRequestFlowTest`: dos casos nuevos (conductor no puede elegir un plan que no le alcanza para sus clientes actuales; cliente no puede bajar a un plan con menos flotas de las que ya tiene). Verificado con `php vendor/bin/phpunit` (163 tests OK), `./vendor/bin/pint` y `npm run build`.

### Ajuste: el botón "Elegir" ya no aparece activo para un plan que no alcanza + módulo "Mi suscripción"
La validación de cupo del backend (arriba) ya bloqueaba el pedido, pero `Plan/Driver.vue` y `Plan/Client.vue` seguían mostrando "Elegir" activo para cualquier plan — incluido volver al Gratis con más clientes/flotas de las que soporta. Ahora el botón se atenúa (mensaje "no te alcanza con lo que ya tenés armado") con el mismo cálculo que el backend; `MyPlanController` manda `maxDriversInAnyFleet` para el lado cliente.

**"Mi suscripción" en el perfil** (consideración agregada al alcance): `ProfileController::edit()` ahora arma un resumen por rol activo (conductor y/o cliente) con el plan vigente, botón "Cambiar de plan", y un llamado a la acción con el próximo plan hacia arriba — "Por $X más al mes, usá el plan Y y tené [beneficio]". Nuevo `Profile/Partials/SubscriptionSummary.vue`, agregado a `Profile/Edit.vue`.

### Tests
Caso nuevo en `ProfileTest`: un conductor sin flota ve el resumen de conductor con el upsell del próximo plan, y no ve el de cliente. Verificado con `php vendor/bin/phpunit` (164 tests OK), `./vendor/bin/pint` y `npm run build`.

### Fix: plan Gratis pedía comprobante de pago + mensaje de upsell decía "0 flota(s) más"
Dos reportes del usuario sobre el módulo de suscripción recién agregado:

- **Comprobante para un plan de $0**: `SubscriptionRequestController::store()` creaba un pedido `awaiting_proof` para CUALQUIER plan, incluido el Gratis — no tiene sentido pedir comprobante de algo que no cuesta nada. Ahora, si `monthly_price <= 0`, se activa la suscripción directo (sin pasar por revisión de admin) usando el nuevo `App\Services\SubscriptionActivator` (extraído de la lógica que antes vivía privada en `Admin\SubscriptionController`, para no duplicarla entre la activación manual del admin, la aprobación de un pedido, y esta auto-activación).
- **Upsell roto**: `ProfileController::benefitBlurb()` calculaba el beneficio del próximo plan mirando un solo campo fijo (`max_fleets` para cliente, `max_clients` para conductor). Pero Gratis y Plus de cliente tienen el MISMO `max_fleets` (1) y solo difieren en `max_drivers_per_fleet` (20 → 50) — por eso salía "hasta 0 flota(s) más". Reescrito para armar una lista de cláusulas revisando cada dimensión por separado (conductor: clientes de confianza, directorio público, prioridad en listado, insignia verificado; cliente: flotas y conductores por flota), mencionando solo las que de verdad mejoran.

### Tests
`SubscriptionRequestFlowTest`: caso nuevo (elegir un plan gratis activa la suscripción al toque, sin crear un pedido esperando comprobante). `ProfileTest`: caso nuevo (el upsell de Gratis→Plus de cliente menciona conductores por flota, no "0 flota"). Verificado con `php artisan test --filter="SubscriptionRequestFlowTest|ProfileTest|AdminSubscriptionTest"` (23 tests OK) y `./vendor/bin/pint` — sin cambios en Vue, no hizo falta `npm run build`.

### Ajuste: precio del upsell confuso + acceso directo a "Mi suscripción"
El usuario marcó que la tarjeta de "Mi suscripción" decía "Por $5.00 más al mes, usá el plan Plus ($5.00/mes)..." — como el plan Gratis no cuesta nada, el incremento y el precio total coinciden, y leerlo así da a entender que $5 es un precio promocional en vez del precio real del plan. `Profile/Partials/SubscriptionSummary.vue` ahora abre con el precio normal del plan ("El plan Plus cuesta $5.00/mes") y deja el incremento como dato aparte entre paréntesis ("($5.00 más que tu plan actual)").

También agregado (consideración agregada al alcance):
- **"Ver mi suscripción"** en el menú de cuenta (`AuthenticatedLayout.vue`), que lleva directo a la sección de suscripción del perfil (`/profile#suscripcion`).
- El **plan vigente de cada rol activo** (conductor y/o cliente) se muestra debajo de la calificación en ese mismo menú, compartido globalmente vía `HandleInertiaRequests` (`auth.plans.driver` / `auth.plans.client`, usando `PlanLimits`) para que aparezca en cualquier página, no solo en el perfil.

### Tests
`NavigationRolesTest`: caso nuevo (`auth.plans` refleja el plan de cada rol activo, mismo criterio que `isDriver`/`hasFleet`). Verificado con `php artisan test --filter="NavigationRolesTest|ProfileTest|SubscriptionRequestFlowTest|AdminSubscriptionTest"` (27 tests OK), `./vendor/bin/pint` y `npm run build`.

### Cambio de arquitectura: una cuenta es cliente O conductor, nunca las dos
El usuario pidió simplificar la sección 3.1 (antes: una cuenta podía activar el rol de conductor y a la vez ser dueña de una flota como cliente) — la doble suscripción por lado "enredaba todo". Se detectó además el bug concreto que lo disparó: `FleetController::index()` auto-creaba una flota la primera vez que CUALQUIER usuario visitaba `/flotas`, sin chequear si ya era conductor — así fue como la cuenta demo de conductor "Pedro Chofer" terminó con badge de "Cliente" también.

- **`App\Models\User::isClient()`** (nuevo): `! is_admin && ! isDriver()` — única fuente de verdad, reemplaza el patrón `! isDriver() || fleets()->exists()` que estaba duplicado en `HandleInertiaRequests`, `DashboardController` y `ProfileController`.
- **Exclusividad, a nivel de controlador** (mismo patrón que `SubscriptionPlanEligibility`, sin middleware nuevo): `FleetController::index()/store()` rechazan si `isDriver()`; `DriverProfileController::edit()/update()` rechazan si ya es dueño de una flota. Ser **miembro** de la flota de otro (el corazón del producto) no cambia — la regla es solo sobre ser **dueño**.
- **Mensajes flash por fin visibles**: `->with('status', ...)` se usaba en 16 controladores (38 veces) pero ninguna pantalla los mostraba. Ahora `HandleInertiaRequests` comparte `flash.status` y `AuthenticatedLayout.vue` tiene un banner que lo muestra — necesario para que los redirects nuevos de arriba se entiendan, y de paso deja visibles los otros 36 mensajes que ya existían.
- **Navegación**: "Mi perfil de conductor" (el CTA "Convertirme en conductor") se oculta para quien ya es cliente con flota propia, tanto en `AuthenticatedLayout.vue` (menú de escritorio/bottom sheet) como en `Dashboard.vue`.
- **`php artisan app:enforce-single-role`** (nuevo, `App\Console\Commands\EnforceSingleRoleAccounts`): arregla las cuentas que ya habían quedado con doble rol, priorizando conductor y borrando la(s) flota(s) del lado cliente — pero solo si esa flota no tiene `rides`/`ride_requests` reales (si los tiene, no la toca y la reporta aparte, para no destruir historial de pagos por el cascade de las migraciones). También cancela la suscripción del lado cliente si tenía una activa.
- **Seeder de demo**: a "Demo Cliente" (`cliente@arka01.test`) se le quitó el lado conductor (ya había 6 cuentas demo de conductor puro); el caso de plan pago "de en medio" que tenía se movió a Luis.

**Nota pendiente para vos:** corrí `php artisan app:enforce-single-role` contra tu base local y **ni "Demo Cliente" ni "Pedro Chofer" se arreglaron solos** — las dos ya tienen carreras reales (`rides`) en la flota que no debería existir, así que el comando los dejó intactos a propósito en vez de borrar ese historial. Quedan como los únicos dos casos dobles que sobreviven; si querés que los limpie a mano avisame cómo preferís resolverlos (por ejemplo, reasignar esas carreras a otra flota antes de borrar la propia, o simplemente dejarlos así como excepción histórica).

### Tests
`NavigationRolesTest`: dos casos nuevos (un conductor no puede crear/entrar a `/flotas`; un dueño de flota no puede activarse como conductor) + assert de `auth.isClient`. `PublicProfileTest`: se quitó el caso de "ambos roles" (ya no alcanzable). `EnforceSingleRoleAccountsTest` (nuevo): borra flota vacía y mantiene conductor; NO borra flota con `rides` reales; cancela la suscripción cliente; no toca cuentas de un solo rol. Verificado con la suite completa (`php artisan test`, 171 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix de raíz: las imágenes no se mostraban en ningún lado de la app
El usuario entregó un documento de directrices de arquitectura (2026-07-30, ver memoria `project_directrices_arquitectura_2026_07_30`) que, entre varios pedidos grandes, marcaba que ninguna imagen se veía bien (comprobantes de pago, fotos de licencia/vehículo) y pedía auditar todo el flujo en vez de asumir que era solo el frontend. Se priorizó este ítem primero por ser el más concreto y acotado.

**Causa raíz encontrada:** `APP_URL` en `.env` estaba en `http://localhost`, pero la app corre en `http://127.0.0.1:8000` (`php artisan serve`) — y nada responde en `http://localhost` (confirmado con `curl`, connection refused). Como `Storage::disk('public')->url()` arma la URL a partir de `APP_URL` (`config/filesystems.php`, disco `public`), **toda** imagen de la app (comprobantes de suscripción, fotos de licencia/vehículo) se generaba apuntando a un origen que no existe. El symlink `public/storage`, los permisos y las rutas guardadas en BD estaban todos correctos — se confirmó sirviendo el mismo archivo por `127.0.0.1:8000/storage/...` (200 OK) vs `localhost/storage/...` (falla). Corregido cambiando `APP_URL` a `http://127.0.0.1:8000` y limpiando la config cacheada (`php artisan config:clear`).

**De paso, bug de frontend real encontrado** (sección 13 del documento: la foto debe verse en navbar y perfiles): el modelo `User` ya tenía `avatar_url` (poblado automáticamente al loguearse con Google, `GoogleAuthController`), pero `AuthenticatedLayout.vue` nunca lo usaba — el avatar del navbar siempre mostraba iniciales, incluso para alguien que entró con Google y tiene foto real. Se creó `Components/UserAvatar.vue` (reutilizable: foto si existe, iniciales si no, nunca imagen rota) y se usa en el navbar, en el perfil propio (`Profile/Edit.vue`) y en el perfil público (`Profile/Show.vue`).

**Alcance de esta pasada:** se cubrieron los lugares "mínimos" que pedía el documento (navbar, perfil propio, perfil público) más el arreglo de raíz que destraba todo lo demás (comprobantes, fotos de conductor). Sumar el avatar a listados del admin, directorio de conductores, reseñas, etc. queda para una pasada aparte si se pide — no son imágenes rotas hoy (esas pantallas no muestran avatar personal en absoluto, solo texto), así que no es parte del "fix" sino de extender la cobertura.

### Tests
Sin cambios de lógica PHP (solo `.env` y componentes Vue), se corrió `NavigationRolesTest`, `ProfileTest` y `PublicProfileTest` (15 OK) para confirmar que nada se rompió, más `npm run build` sin errores.

### Documento nuevo: `Arka01_Directrices_Arquitectura.md`
El usuario entregó un charter de arquitectura/rendimiento/desarrollo que va a seguir creciendo por secciones a lo largo del proyecto (igual que `Arka01_Alcance_Proyecto.md` con el alcance funcional). Se creó este archivo nuevo en la raíz para que viva como documento versionable del repo en vez de quedar solo en la conversación — las secciones 1-18 (principios de arquitectura, convenciones SQL, Scheduler/Jobs, registro por tipo de cuenta, arquitectura de roles a futuro, gestión de imágenes, calidad de código) quedaron documentadas ahí tal cual se recibieron, listas para retomarse.

### Regla de negocio: las suscripciones son una progresión, nunca un retroceso (sección 19)
Nueva sección del documento de arquitectura: una vez que un usuario sube de plan, no puede volver a uno inferior (ni siquiera al Gratis), sin importar si el cupo le alcanzaría. La jerarquía se determina por `sort_order` (columna que ya existía en `subscription_plans`), nunca comparando nombres — así que agregar o reordenar planes a futuro no requiere tocar la lógica.

- **`App\Services\PlanLimits`**: `forDriver()`/`forClient()` ahora también devuelven `plan_sort_order` (el nivel del plan vigente).
- **`App\Services\SubscriptionPlanEligibility::reasonNotEligible()`**: primero chequea que el plan elegido no sea de nivel inferior al vigente (`sort_order` menor) — si lo es, rechaza con mensaje claro, antes incluso de mirar cupo. Como este método ya era el único punto de paso tanto para `SubscriptionRequestController::store()` (elegir plan) como para `Admin\SubscriptionController::approveRequest()` (aprobar un pedido), la regla queda cubierta en los dos sin duplicar nada — ni siquiera manipulando la petición HTTP a mano (Postman, etc.) se puede saltear, porque la validación vive en el backend, no en la UI.
- **`MyPlanController::driver()/client()`**: el catálogo que se manda al frontend ya NO incluye planes de nivel inferior al vigente (`->where('sort_order', '>=', $limits['plan_sort_order'])`) — no es solo un botón atenuado, el plan ni siquiera llega a la pantalla, tal como pide la sección 19 ("no deberá visualizar nuevamente los planes inferiores").
- **`Plan/Driver.vue` / `Plan/Client.vue`**: si la lista de planes queda con un solo elemento (el vigente), se muestra "Ya tenés el plan de mayor nivel disponible" en vez de una lista vacía sin explicación.
- **Alcance de la restricción**: se aplica a la elección del propio usuario (`SubscriptionRequestController`) y a la aprobación de un pedido (`Admin\SubscriptionController::approveRequest`). La activación manual directa del admin (`Admin\SubscriptionController::store`, buscando a un usuario a mano) sigue sin restringirse a propósito — es la vía de soporte para corregir un error o hacer una excepción puntual, no algo que un usuario pueda disparar por su cuenta; la sección 19 habla de impedirle el downgrade "al usuario", no a un admin operando el panel.
- Los nombres de ejemplo de la sección 19 ("Gratuita, Básica, Profesional, Premium") son ilustrativos de una jerarquía de 4 niveles — no se renombró el catálogo real (Gratis/Básico/Plus/Pro/Institucional en conductor; Gratis/Plus/Multi-flota en cliente), porque la propia sección 19 pide explícitamente no basar la lógica en nombres sino en el nivel/orden, que ya existía.

### Tests
`SubscriptionRequestFlowTest`: tres casos nuevos (no se puede bajar a un plan de nivel inferior aunque el cupo alcance; sí se puede volver a elegir el mismo plan que ya se tiene; el catálogo de "Mi plan" nunca incluye planes de nivel inferior al vigente). Verificado con la suite completa (`php artisan test`, 174 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Ajuste: detalle del comprobante de pago en el panel admin
El usuario marcó que la miniatura del comprobante (`Admin/Subscriptions.vue`) quedaba recortada a un cuadrado chico (`object-cover` 160×160), insuficiente para leer un comprobante real, y que el rechazo necesitaba un motivo más a mano. Se agregó:
- La miniatura (y un link "Ver detalle del pago") abren un modal con la imagen completa sin recortar (`object-contain`) más los datos del pago: cliente, plan solicitado, monto a transferir y fecha en que se subió el comprobante (`updated_at` del pedido, que es cuando se subió — no `created_at`, que es cuando se eligió el plan).
- Motivos de rechazo frecuentes como chips de un clic ("No se distingue el comprobante", "El monto no coincide con el plan") que llenan el campo de motivo (ya existía el campo, seguía siendo editable) — para los casos que mencionó el usuario sin tener que escribirlos cada vez.

Solo cambios de frontend (`Admin/Subscriptions.vue`), sin tocar backend — `admin_note` ya se guardaba y `SubscriptionRequest` ya exponía `updated_at`. Verificado con `AdminSubscriptionTest` y `SubscriptionRequestFlowTest` (15 OK, sin cambios de lógica PHP) y `npm run build`.

### Rediseño: inicio del cliente según mockup del usuario
El usuario compartió una captura de referencia para el "Inicio" de una cuenta cliente: saludo personal, avatares de "Mi flota" con estado por color, dos accesos grandes ("Pedir viaje" / "Expresos") y un carrusel de "Conductores cerca" con botón para agregarlos. Reemplaza la grilla de indicadores numéricos que tenía el cliente (el bloque de conductor y el de admin no cambiaron — desde el cambio a "una cuenta es cliente o conductor" de esta misma sesión, son mutuamente excluyentes, así que no hay superposición que resolver).

- **`DashboardController`**: dentro de `isClient()`, dos props nuevas — `fleetDrivers` (miembros activos de la primera flota, con `status` disponible/en carrera/desconectado, mismo criterio que `RideRequestController::driverCardData()`) y `nearbyDrivers` (conductores públicos disponibles, sin los que ya son de la flota, ordenados por cercanía si el navegador compartió ubicación — mismo patrón de `lat`/`lng` que `DriverDirectoryController`, si no por mejor calificados). La primera flota se auto-crea igual que en `FleetController::index()`, porque el botón "Agregar" de "Conductores cerca" necesita un id de flota real. `clientStats` (los números viejos) se eliminó, no lo usaba nadie más.
- **`Dashboard.vue`**: saludo "¡Hola, {nombre}!", fila de avatares de flota con anillo de color (verde disponible / naranja en carrera / gris "quemado", mismos colores que `Ride/Request.vue`), dos tarjetas grandes de acceso reutilizando los íconos ya usados en el navbar, y un carrusel horizontal (scroll-snap nativo, sin librería) de conductores cerca con botón "Agregar" que invita a la flota (mismo `fleet.invitations.store` que el directorio). El link "Mi perfil de conductor" que antes vivía en la tarjeta compartida de "Accesos rápidos" se movió adentro del bloque de conductor, para no perderlo.

### Tests
`tests/Feature/DashboardTest.php` (nuevo): el cliente ve el estado correcto de cada conductor de su flota (disponible/en carrera/desconectado); "conductores cerca" no incluye a quien ya es de la flota ni al propio usuario; un conductor no ve las secciones de cliente; un admin no ve ninguna. Verificado con la suite completa (`php artisan test`, 178 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: el botón "+" de la barra inferior se veía corrido del centro
El usuario compartió una captura donde el botón central flotante no quedaba centrado en la barra de navegación móvil. Causa: el botón vivía como "un tab más" dentro del mismo `flex` que Inicio/Flotas/Carreras/Perfil — solo quedaba centrado cuando había la misma cantidad de tabs a cada lado (el caso de un cliente), pero un conductor no ve "Flotas" (sección 3.1: cada cuenta es cliente o conductor), así que le quedaban 1 tab a la izquierda y 2 a la derecha, corriendo el botón hacia la izquierda.

Se separaron los tabs en dos mitades (`flex-1` cada una, izquierda y derecha) con un espacio reservado al medio, y el botón pasó a tener posición absoluta centrada en la barra completa (`left-1/2 -translate-x-1/2`) en vez de ser parte del flex de los tabs — así queda perfectamente al medio sin importar cuántos tabs haya de cada lado (cliente, conductor o admin). Solo `AuthenticatedLayout.vue`, sin cambios de backend. Verificado con `npm run build`.

### Fix: error 500 al intentar loguearse con una sesión activa en otro dispositivo
El usuario reportó una pantalla de error de Symfony (`TransportException: Connection could not be established with host "mailpit:1025"`) al intentar entrar desde una segunda sesión. Causa: `EnforceSingleActiveSession` manda un correo avisándole al dueño real de la cuenta antes de bloquear el segundo login (`Mail::to(...)->send(...)`), pero si el servidor de correo local no está levantado (como en este entorno, sin Mailpit corriendo), ese envío tira una excepción que no estaba controlada — reventaba el request entero ANTES de llegar a lanzar `ActiveSessionExistsException`, así que en vez del mensaje claro "ya tenés una sesión activa en otro dispositivo" el usuario veía la pantalla de error cruda de Laravel.

Se envolvió el envío en un `try/catch`: si falla, se registra un `Log::warning` (para poder diagnosticar un problema real de correo después) pero el flujo sigue igual — cierra la sesión vieja y lanza `ActiveSessionExistsException` de todas formas, que es lo que ya se traducía en el mensaje correcto en `LoginRequest`/`GoogleAuthController`. El usuario nunca se entera de que el correo falló, solo ve el mensaje de sesión duplicada, tal como se pidió.

### Tests
`SingleActiveSessionTest`: caso nuevo que simula el envío de correo fallando (`Mail::shouldReceive('to->send')->andThrow(...)`) y confirma que el login se sigue bloqueando con el mensaje correcto en vez de un 500. Verificado con `php artisan test --filter=Auth` (42 tests OK) y `./vendor/bin/pint --dirty`.

### Rediseño: inicio del cliente y del conductor acercados a los mockups nuevos
El usuario compartió mockups más detallados de ambos roles y pidió acercar el diseño, manteniendo la barra inferior actual con el botón "+" (se descartó explícitamente adoptar la barra de 5 pestañas planas del mockup — decisión confirmada con el usuario). Se dejaron afuera, a propósito, piezas del mockup que hubieran significado inventar features grandes sin base real: campana de notificaciones in-app (no existe ese modelo), foto/ilustración de auto (no hay asset), viajes "agendados a futuro" tipo "mañana 6:30pm" (no existe ese concepto — `RideRequest.requested_at` siempre es "ahora"), y el banner de referidos del conductor (no existe un sistema de referidos — se adaptó a compartir el `invite_code` que ya existía).

- **`DashboardController::fleetDriversFor()`**: ahora suma `average_rating`/`review_count`/`distance_km` (mismo patrón por lote que ya usaba `nearbyDriversFor()`), para que las tarjetas de "Mi flota de confianza" se vean completas.
- **`DashboardController::upcomingTripsFor()`** (nuevo): combina, para el rol correspondiente, `RideRequest` pendiente/negociando + `Ride` en curso ya confirmado, hasta 3, como vista previa de "Próximos viajes" — con datos reales (nunca una fecha inventada), "Ver todos" lleva a `rides.index` (la pantalla real, sin cambios).
- **`DashboardController::earningsSparklineFor()`** (nuevo, solo conductor): ganancias por día de los últimos 14 días, un array simple sin tabla ni librería de gráficos nueva — el sparkline en el frontend es un `<svg><polyline>` de una sola serie, color `arka-primary`, sin ejes ni leyenda (siguiendo la guía de la skill de dataviz para un sparkline decorativo de una sola serie).
- **Conductor**: se agregó el `invite_code` (ya existía con QR en `Driver/Profile.vue`, ahora también visible con botón copiar en el inicio) y se sumó `Components/DriverAvailabilityToggle.vue` (ya existía, usado en `Driver/Invitations.vue`) como el único toggle interactivo de disponibilidad de la pantalla, en el banner grande "Desconectarme" — el badge de arriba sigue siendo de solo lectura para no tener dos controles que se puedan desincronizar.
- **Cliente**: buscador que filtra "Mi flota" por nombre (en el navegador, sin ida al servidor), tercera tarjeta "Conductores públicos" en "Solicitá un viaje" (→ `directory.index`), y banner final "Viajá con confianza".

### Tests
`DashboardTest`: 4 casos nuevos — `fleetDrivers` trae calificación/distancia cuando hay ubicación; `upcomingTrips` de un cliente incluye una solicitud pendiente y una carrera confirmada con las etiquetas correctas; `upcomingTrips` de un conductor incluye una solicitud entrante; el conductor recibe `inviteCode`/`earningsSparkline` (14 días) y el cliente no. Verificado con la suite completa (`php artisan test`, 184 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Ajuste: botón "Activarme"/"Desconectarme" dinámico + que se vea en vivo en el cliente
El usuario pidió que el inicio del conductor tuviera un botón claro para activarse, y que ese cambio se reflejara en la pantalla del cliente sin recargar.

- **Banner dinámico**: el banner de disponibilidad del conductor ahora dice "Activarme" (con ícono apagado, cuando no está disponible) o "Desconectarme" (cuando sí) en vez de un título fijo — `Components/DriverAvailabilityToggle.vue` emite `update:available` cada vez que cambia (adición retrocompatible, no rompe su otro uso en `Driver/Invitations.vue`) y `Dashboard.vue` lo usa para actualizar tanto el banner grande como el badge de arriba, que antes quedaba desactualizado tras tocar el switch.
- **En vivo del lado cliente**: se detectó que el broadcast `DriverLocationUpdated` (evento `driver.location.updated`, canal `fleet.{id}`) ya existía y ya lo escuchaban `Ride/Show.vue` y `Ride/Index.vue`, pero `Dashboard.vue` nunca se suscribía — por eso "Mi flota" no se actualizaba sola. Se agregó la misma suscripción: al activarse/desactivarse un conductor de la flota, el punto de color de su tarjeta cambia al toque (no pisa el estado "en carrera" si está en una carrera activa, ya que ese dato no viaja en este evento).

Sin cambios de backend — el broadcast y el endpoint ya existían, solo faltaba que el frontend del cliente se suscribiera. Verificado con la suite completa (184 tests OK, sin tests nuevos porque no hay lógica PHP nueva) y `npm run build`.

### Columna `users.role` (sección 9 de las directrices de arquitectura: preparar la estructura de usuarios para roles futuros)
El usuario pidió una columna en `users` que diga, como string, qué tipo de cuenta es (cliente/conductor/admin por ahora), según lo que ya está configurado.

**Decisión de capa** (sección 2 de las directrices): se evaluaron tres formas de mantenerla al día — un trigger de BD, un Eloquent Observer recalculando en cada `save()`, o actualizarla en los puntos exactos donde el rol puede cambiar de verdad. Se eligió la tercera: el rol de una cuenta cambia en, como mucho, dos momentos reales (se registra → cliente por default; activa conductor → conductor, una sola vez, porque no se puede volver atrás por la regla de "una cuenta es cliente o conductor" de esta misma sesión) y admin es prácticamente solo por seeder hoy. Un trigger hubiera sido la primera automatización de BD del proyecto para un caso rarísimo que ya tiene un único punto de control claro en PHP, duplicando en SQL una regla de negocio que ya vive ahí. Un Observer que recalcule en cada guardado de `User` metería una consulta extra en operaciones frecuentes (cambiar nombre, contraseña, etc.) que no lo necesitan.

- **Migración nueva**: `role` string, default `'cliente'`, con backfill para las cuentas que ya son conductor (tienen `driver_profiles`) o admin (`is_admin=true`). No se reutilizó la columna `user_type` que ya existía en `users` — es un concepto distinto (individual/organización, sin uso real) que no tenía por qué mezclarse con esto.
- **`App\Models\User::booted()`**: hook `saving` que recalcula `role` cada vez que `is_admin` cambia (cubre promoción y una eventual degradación a futuro).
- **`App\Models\DriverProfile::booted()`**: hook `created` que pasa el `role` del dueño a `'conductor'` — vive acá porque es el evento real que dispara el cambio, y así queda cubierto sin importar si el perfil se creó desde el controlador, un seeder o un test.
- **Importante**: `role` es una columna de **consulta rápida** (reportes, admin, SQL directo) — nunca la fuente de verdad para permisos. Las decisiones de autorización siguen usando `isDriver()`/`isClient()`/`is_admin`, sin cambios.
- Al correr el backfill contra la base local se confirmó que `cliente@arka01.test` y `pedro@arka01.test` siguen marcados como los dos casos dobles pendientes de revisión manual (ver el ajuste de "una cuenta es cliente o conductor" más arriba) — la columna refleja la realidad tal cual está, sin ocultar ese pendiente.

### Tests
`UserRoleColumnTest` (nuevo): un usuario nuevo arranca en `'cliente'`; activar el perfil de conductor lo pasa a `'conductor'`; una cuenta admin queda en `'admin'`; sacarle `is_admin` lo vuelve a `'cliente'`. Verificado con la suite completa (`php artisan test`, 188 tests OK) y `./vendor/bin/pint --dirty`.

### Fix: switch de disponibilidad duplicado en "Mis clientes de confianza"
El usuario notó que `Driver/Invitations.vue` ("Mis clientes de confianza") tenía su propia tarjeta "Disponibilidad" con el mismo `Components/DriverAvailabilityToggle.vue` que ya vive en el Inicio del conductor — mismo componente, mismo endpoint (`driver.location.update`), mismo dato (`driver_profiles.is_available`). No estaban "desincronizados" en el sentido de guardar datos distintos (cada pantalla carga el estado real al entrar), pero tener el mismo control repetido en dos lugares es confuso — se sacó de esta pantalla, queda solo en el Inicio. `DriverInvitationController::index()` ya no manda la prop `driverProfile` (quedó sin uso).

Verificado con `FleetInvitationFlowTest` y `DashboardTest` (18 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: "Pedir carrera" no reflejaba la disponibilidad real del conductor
El usuario mostró capturas: activaba/desactivaba a Pedro desde el Inicio del conductor, pero en la pantalla del cliente ("¿A quién se la pedís?") seguía viéndolo "En carrera" (punto naranja) sin cambios. Dos causas distintas, las dos corregidas:

1. **Dato viejo real**: Pedro tenía una `Ride` con `status = in_progress` de una prueba manual anterior (con Demo Cliente) que nunca se marcó completada — no era un bug de código, era una carrera de verdad que había quedado "abierta" en la base. Mientras existiera, `busy` le iba a ganar siempre a `is_available` en el cálculo de estado (a propósito: alguien en carrera no debe verse como disponible aunque tenga el switch prendido) — se cerró esa carrera a mano (`status = completed`).
2. **Bug de código real**: `Ride/Request.vue` (la pantalla de "¿A quién se la pedís?") nunca escuchaba el evento `driver.location.updated` — a diferencia de `Ride/Show.vue`, `Ride/Index.vue` y el inicio del cliente (que si lo agregamos hace unas pasadas), esta pantalla calculaba el estado de cada conductor solo una vez al cargar la página y nunca se enteraba de cambios en vivo. Se agregó la misma suscripción al canal `fleet.{id}` de la flota que se está viendo, con el mismo criterio de no pisar "en carrera" (ese dato no viaja en el evento).

**Límite conocido, no resuelto todavía**: cuando una carrera se completa, hoy no hay ningún evento que le avise a la flota "este conductor ya quedó libre" — el estado "en carrera" solo se corrige recargando la pantalla o esperando el próximo pedido, no al instante en que se completa. Si se quiere cerrar ese último hueco hace falta un evento nuevo (`RideCompleted` o similar) en `RideController::complete()`; no se hizo en esta pasada porque no era el síntoma reportado (avisado explícitamente, no una omisión).

### Tests
Sin tests nuevos (el cambio es agregar una suscripción a un evento que ya existe, mismo patrón ya cubierto por otras pantallas). Verificado con `RideRequestFlowTest`, `RidePriceNegotiationTest` y `DashboardTest` (31 tests OK), `./vendor/bin/pint --dirty` y `npm run build`, más una prueba manual contra la base local confirmando que Pedro ya aparece "available" para Demo Cliente.

### Fix: el conductor no se enteraba de una solicitud nueva si estaba en el Inicio
El usuario probó pedirle una carrera a Pedro directamente y, con Pedro parado en su propia pantalla de Inicio, no pasó nada visible — ni el número de "Solicitudes" cambió, ni ningún aviso. Causa: `Ride/Index.vue` (la pantalla de "Carreras") sí escuchaba el evento `ride-request.created` desde hace tiempo, pero el Inicio del conductor (`Dashboard.vue`), al ser una pantalla nueva de esta sesión, nunca se suscribió — el número de "Solicitudes" se calculaba una sola vez al cargar la página.

- **`DashboardController`**: nueva prop `driverFleetIds` (flotas donde el conductor es miembro activo), mismo cálculo que ya usa `RideController::index()` — hace falta para poder escuchar también las solicitudes "a toda la flota", no solo las dirigidas.
- **`Dashboard.vue`**: se suscribe al canal personal (`App.Models.User.{id}`) y al de cada flota activa, igual que `Ride/Index.vue`. Cuando llega `.ride-request.created`: sube el número en la tarjeta "Solicitudes" al toque, y aparece un banner arriba de todo ("¡Nueva solicitud de carrera! fulano te ofrece $X — Ver") que se puede cerrar a mano o se esconde solo a los 12 segundos. `.ride-request.cancelled` baja el número de vuelta si la solicitud se cae antes de que el conductor la vea.

### Tests
`DashboardTest`: dos casos nuevos — el conductor recibe `driverFleetIds` con las flotas correctas, el cliente no recibe esa prop (no le sirve de nada). Verificado con la suite completa (`php artisan test`, 190 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Mejora: catálogo de ciudades/sectores con diseño propio
El `<select>` nativo del navegador (usado para Ciudad y los dos Sector en "Solicitar carrera") se veía blanco con el estilo del sistema operativo — no hay forma de re-diseñar el panel desplegable de un `<select>` nativo solo con CSS. Se armó `Components/SearchableSelect.vue` (nuevo, reutilizable): mismo look oscuro que el resto de la app, con buscador arriba (útil con ~30 ciudades y decenas de sectores por ciudad) — reemplaza los tres `<select>` de `Ride/Request.vue` sin cambiar la lógica de `changeCity()`/`originSectorId`/`destinationSectorId` que ya existía.

### Tests
Sin tests nuevos (cambio de frontend puro, sin lógica de negocio). Verificado con `RideRequestFlowTest` y `RidePriceNegotiationTest` (los que ejercitan el formulario completo, incluidos sectores) y `npm run build`.

### Fix: dos huecos más en la sincronización en vivo de carreras
El usuario reportó dos síntomas concretos después de probar el flujo completo:

1. **"Completé una carrera como conductor y en el cliente seguía apareciendo 'en carrera'."** Era el hueco que ya había quedado anotado como pendiente la vez pasada: no existía ningún aviso cuando una carrera se completa. Se creó `App\Events\RideCompleted` (mismo criterio que `DriverLocationUpdated`: avisa a TODAS las flotas donde el conductor es miembro activo, no solo a la de esa carrera puntual, porque "en carrera" lo saca de disponible en todos lados) y se dispara desde `RideController::complete()`. `Ride/Request.vue` y `Dashboard.vue` (Mi flota del cliente) ahora escuchan `.ride.completed` igual que ya escuchaban `.driver.location.updated`.
2. **"Cuando el conductor toma la carrera, no desaparece la pantalla de 'Esperando respuesta'."** Este era un bug distinto y ya existía antes de esta sesión: `Ride/Index.vue` sí pedía un `router.reload()` de `pendingRequestsAsClient` al recibir `.ride-request.accepted`, pero la copia local `myPending` (la que de verdad usa el template) nunca se resincronizaba con esa recarga — quedaba pegada con los datos viejos para siempre. Se agregó un `watch()` sobre las props (mismo patrón que ya se usó para `fleetDriversLocal` en pasadas anteriores) para que la copia local se actualice cuando Inertia trae datos nuevos.

### Tests
`RideRequestFlowTest`: caso nuevo que confirma que completar una carrera dispara `RideCompleted` con la carrera correcta. Verificado con la suite completa (`php artisan test`, 191 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Limpieza de las dos cuentas demo con doble rol
`cliente@arka01.test` y `pedro@arka01.test` tenían un perfil accidental del otro rol, sobrante de pruebas manuales de sesiones anteriores (de cuando la app todavía permitía ser cliente y conductor a la vez). Se verificó con Tinker que ninguno de los dos perfiles accidentales tenía carreras reales asociadas (0 `rides`, salvo un `ride_request` cancelado e irrelevante en el caso de Pedro) antes de borrarlos. De paso se encontró que `users.role` había quedado desactualizado en Demo Cliente tras el borrado manual: el hook que sincroniza esa columna solo cubría `DriverProfile::created`, no `deleted` — se agregó el hook simétrico en `app/Models/DriverProfile.php` para que esto se autocorrija si vuelve a pasar.

### Tests
`UserRoleColumnTest`: caso nuevo, borrar el perfil de conductor hace que `role` vuelva a `cliente`. Verificado con `php artisan test --filter=UserRoleColumnTest` (5 tests OK).

### Foto de perfil visible en más pantallas + iconos de mapa + carga de avatar
Tres pedidos del usuario resueltos en la misma pasada:

1. **`UserAvatar` en más lugares**: hasta ahora solo se veía en navbar y perfiles. Se agregó en el directorio de conductores (`Directory/Index.vue`, más `avatar_url` sumado al array manual de `DriverDirectoryController`, que no venía del modelo completo como el resto), en las cuatro listas de `Admin/Subscriptions.vue` (comprobantes pendientes, modal de detalle, lista principal de usuarios, bitácora de cambios), en `Admin/DriverVerifications.vue` y en las reseñas de `Profile/Show.vue` y `Ride/Show.vue`.
2. **Iconos de origen/destino en el mapa**: `Components/FleetMap.vue` pintaba todos los puntos con el mismo pin genérico de Leaflet. Se agregaron tres iconos SVG inline (sin depender de otro asset de imagen): pin verde para origen, pin rojo para destino, punto celeste para la posición en vivo del conductor — elegidos por el `id` del marcador (`'origin'`/`'destination'`/`'driver'`), convención que ya usaban las cuatro pantallas que dibujan un mapa (`Ride/Show`, `Ride/Request`, `Public/RideTracking`, `Express/Index`), así que no hizo falta tocarlas.
3. **Carga de foto de perfil**: `users.avatar_path` ya existía (se usaba para la foto de Google), pero no había forma de subir una propia. Se agregó el campo `avatar` (validado como imagen, máx. 4MB, mismo límite que licencia/vehículo) a `ProfileUpdateRequest` y el manejo de archivo en `ProfileController::update()` — mismo patrón que `DriverProfileController` (borra el archivo anterior del disco `public` antes de guardar el nuevo, pero solo si era un archivo propio, nunca si era una URL de Google). Vive en `Profile/Edit.vue` (la pantalla de cuenta compartida por cliente y conductor, ya que el avatar es de `users`, no de `driver_profiles`), con vista previa inmediata del archivo elegido en `UpdateProfileInformationForm.vue`.

### Tests
`ProfileTest`: tres casos nuevos — subir una foto la guarda y expone `avatar_url`, subir una nueva borra la anterior si era local, subir una nueva no intenta borrar si la anterior era una URL de Google. Verificado con la suite completa (`php artisan test`, 195 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Autocompletado de direcciones con Google Places (solo eso, no todo el mapa)
El usuario pidió "poner Google Maps para el autocompletado de direcciones y el trazado". Como ya se había elegido explícitamente antes, en esta misma sesión, mantener el mapa gratuito (Leaflet + OpenStreetMap + OSRM, sección 9.3), se le preguntó cómo conciliar los dos pedidos — eligió un punto medio: **Google Places solo para el autocompletado de texto**, el mapa visual y el trazado de ruta siguen siendo 100% Leaflet/OSRM, sin cambios.

- **`Utils/googleMaps.js`** (nuevo): carga perezosa y una sola vez del script de Google Maps JS. Si `VITE_GOOGLE_MAPS_API_KEY` no está configurada, resuelve a `null` sin tirar error — los campos de dirección quedan como texto libre normal, no rompe nada en desarrollo sin la key.
- **`Components/AddressAutocomplete.vue`** (nuevo): input de texto con sugerencias (Places API "New", `AutocompleteSuggestion` + `AutocompleteSessionToken` para no facturar de más), mismo estilo visual que `SearchableSelect.vue`. Al elegir una sugerencia, resuelve sus coordenadas y emite `place-selected` — la pantalla que lo usa decide qué hacer con esas coordenadas (en `Ride/Request.vue` reemplaza la geolocalización del origen o el clic en el mapa para el destino; en `Express/Index.vue`, lo mismo que marcar en el mapa).
- Reemplaza los `TextInput` de "Referencia de origen/destino" en `Ride/Request.vue` y `Express/Index.vue` — únicas dos pantallas del proyecto con ese tipo de campo.
- **`.env.example`** documenta el paso a paso (Google Cloud Console, habilitar "Places API (New)", restringir la key por dominio) con el mismo formato ya usado para Google OAuth y WhatsApp — variable pendiente de completar por el usuario: `VITE_GOOGLE_MAPS_API_KEY`.

### Tests
Sin tests nuevos (depende de un script externo de Google que no se puede ejercitar en la suite de PHP; el componente está armado para degradar a un input de texto normal sin la key, que es lo que sí corre en CI/tests). Verificado que `Ride/Request.vue` y `Express/Index.vue` siguen funcionando de punta a punta con `RideRequestFlowTest`/`RidePriceNegotiationTest` (50 tests OK), `./vendor/bin/pint --dirty` y `npm run build`. **Pendiente de prueba manual del usuario** con una key real de Google, ya que no hay forma de simular las sugerencias reales sin ella.

### Reseteo de datos: elenco mínimo de 4 conductores + 4 clientes
Pedido explícito del usuario: después de tantas pasadas de prueba manual, `DemoDataSeeder` había acumulado flotas, carreras en todos los estados, suscripciones, reseñas, Expresos, contactos de confianza y una alerta SOS — útil en su momento, pero ya no representaba una base "limpia" para seguir probando. Se reescribió el seeder desde cero: ahora crea exactamente **1 admin + 4 clientes + 4 conductores**, sin ningún dato relacional precargado (nada de flotas, carreras, suscripciones, reseñas ni Expresos) — esos flujos se arman a mano según haga falta probarlos. Los conductores sí nacen con `DriverProfile` activo (disponible, con ubicación en Quito) porque sin eso la cuenta no funciona como conductor; los dos primeros (Pedro, Ana) con visibilidad pública para que el directorio no arranque vacío. Se corrió `php artisan migrate:fresh --seed` sobre la base local para aplicar el reseteo. Todas las cuentas siguen usando la contraseña "password".

### Tests
Ninguno de los tests depende de este seeder (usan `RefreshDatabase` + factories propias). Verificado con la suite completa (`php artisan test`, 195 tests OK) después del reseteo.

### Registro guiado paso a paso, con tipo de cuenta primero
El usuario reportó que el registro seguía siendo un formulario largo de una sola pantalla, pese a haberlo pedido guiado ("que vaya registrando poco a poco... y vaya diciendo ya casi terminamos") con selección de tipo de cuenta (conductor/pasajero) como primer paso.

- **`resources/js/Pages/Auth/Register.vue`**: reescrito como wizard de 5 pasos (tipo de cuenta → nombre → correo → teléfono → contraseña), un dato por pantalla. Barra de progreso + mensaje de aliento que cambia según el avance (arranca en "Empecemos por lo básico", termina en "¡Ya casi terminamos! Un último paso."). Validación mínima del lado del cliente por paso para habilitar "Siguiente"; si el backend rechaza algo que solo se sabe al mandar el formulario (ej. correo ya registrado), el wizard vuelve solo al paso donde está ese campo en vez de dejar el error escondido detrás de una pantalla que ya no se ve. El botón de Google solo se muestra en el primer paso (alternativa antes de invertir tiempo en la guía).
- **`RegisteredUserController::store()`**: nuevo campo `account_type` (`cliente`/`conductor`), validado pero no persistido en `users` (la fuente de verdad del rol sigue siendo `isDriver()`/`isClient()`, sección 3.1 — esto solo decide el siguiente paso). Si eligió "conductor", el redirect post-registro va directo a `driver.profile.edit` (con un mensaje de bienvenida) en vez de dejarlo en el Inicio sin poder recibir carreras todavía.

### Tests
`RegistrationTest`: caso nuevo — elegir conductor redirige a completar el perfil de conductor. Ajustados los dos tests existentes de registro y `PhoneVerificationTest` para mandar el nuevo campo `account_type`. Verificado con `php artisan test --filter="RegistrationTest|PhoneVerificationTest"` (9 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Auditoría de punta a punta: notificaciones, ETA, coordenadas y seguridad
El usuario pidió una batería de verificaciones sobre el flujo completo (accesos rápidos del Inicio, notificaciones con sonido/vibración en cada acción, aviso de "el conductor ya va por vos" con tiempo estimado, coordenadas, sockets, seguridad, y qué pasa si el celular se apaga con una carrera en curso). Hallazgos y cambios:

- **Accesos rápidos del Inicio**: revisados para ambos roles — ya cubren lo esencial (cliente: Pedir viaje, Expresos, Directorio público, Mi flota, Conductores cerca, Próximos viajes; conductor: Solicitudes con badge, Mis clientes, Mi perfil, disponibilidad, código de invitación, Próximos viajes). No se encontró ninguna acción principal fuera de alcance — sin cambios acá.
- **Sonido + vibración en vivo** (pedido explícito): `Utils/liveAlert.js` (nuevo) sintetiza dos tonos con Web Audio API — sin archivo de audio que alojar ni que pueda romperse. `playAttentionAlert()` (dos tonos + vibración) para "necesita tu atención" (carrera nueva para el conductor); `playUpdateChime()` (un tono suave) para "algo cambió" (aceptada, contraoferta, completada). Cableado en `Dashboard.vue` y `Ride/Index.vue`, en los mismos listeners de Echo que ya existían.
- **"Ya van por vos" + ETA** (pedido explícito): `Utils/eta.js` (nuevo) estima minutos a partir de la distancia (Haversine ya existente) y una velocidad urbana promedio asumida (22 km/h — no hay datos de tráfico real, y se mantiene el trazado gratuito de la sección 9.3, sin Google). `Ride/Show.vue` muestra un banner al cliente mientras la carrera está en curso, recalculado solo con cada actualización de ubicación del conductor por WebSocket.
- **Notificaciones push que faltaban** (solo existían para "solicitud nueva" e "invitación a flota"): `RideAcceptedPushNotification` (al cliente, cuando el conductor acepta) y `RideCompletedPushNotification` (a quien NO completó la carrera) — mismo patrón que las existentes, cubren el caso de la app cerrada/en segundo plano, que el WebSocket no alcanza. `public/sw.js` ahora pide vibración (`vibrate`) y sonido del sistema (`silent: false`, ya era el default pero se dejó explícito) al mostrar la notificación.
- **Bug real de coordenadas encontrado y corregido**: en `Components/DriverAvailabilityToggle.vue`, si el GPS fallaba justo en el instante de desactivarse (túnel, edificio), `getCurrentPosition` no devolvía nada y como el backend EXIGE lat/lng (`DriverLocationController::update`), nunca se enteraba de apagar `is_available` — el conductor quedaba mostrado "disponible" en toda la app para siempre, aunque su propia pantalla ya dijera "No disponible". Se guarda la última posición conocida de la sesión y se manda esa como respaldo si el GPS falla justo en ese momento.
- **Carrera en curso sin cerrar, si se apagó el celular** (pedido explícito): `Dashboard.vue` (Inicio, lo primero que se ve al volver a entrar) ahora muestra un banner de advertencia bien visible si hay una `Ride` en curso sin cerrar, con link directo a esa carrera — se arma con el mismo prop `upcomingTrips` que ya existía, sumándole `ride_id` en `DashboardController`.
- **Seguridad**: revisión rápida del canal admin (`EnsureUserIsAdmin`), autorización de `RideController`/`RideRequestController` (ya tenían los chequeos `403` correctos y tests que los cubren), mass assignment (`is_admin` sigue fuera de `$fillable`), subida de archivos (siempre validados como `image`, rutas generadas por `Storage`, nunca por el usuario) y ausencia de `v-html` en el frontend (sin superficie de XSS por ese lado). No se encontraron vulnerabilidades nuevas.
- **Usuarios de acceso más fáciles**: ya resuelto en el reseteo de datos de este mismo día (ver arriba) — cuentas demo con emails cortos y contraseña "password" para las 9 cuentas.

### Tests
`Security/PushNotificationTest`: dos casos nuevos — aceptar una solicitud notifica al cliente, completar una carrera notifica a quien no la completó (y NO a quien sí). El fix de `DriverAvailabilityToggle.vue` es puramente de frontend (sin suite de tests de componentes Vue en este proyecto) — verificado por lectura de código, no automatizado. Verificado con la suite completa (`php artisan test`, 198 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Pedir carrera "ahora mismo" o "programada", con ida y vuelta
Pedido explícito del usuario: al pedir una carrera, elegir entre "ahora mismo" (default, como siempre) o "programada" para una fecha/hora futura, con la opción de marcarla como ida y vuelta.

- **`ride_requests`**: nuevas columnas `is_scheduled`, `scheduled_at`, `round_trip`. `requested_at` sigue significando "cuándo se hizo el pedido" (el "esperando hace X minutos" de `Ride/Index.vue` no se toca); `scheduled_at` es un dato aparte, "para cuándo es".
- **Decisión de arquitectura, la parte no trivial**: aceptar una solicitud programada NO puede crear una `Ride` en curso de una — el conductor quedaría "ocupado" desde que acepta hasta la hora programada (puede ser horas o días después), rompiendo "Mi flota" y "¿A quién se la pedís?" para el resto de sus clientes mientras tanto. Se agregó un tercer estado a `rides.status`: **`scheduled`** — cuenta como "aceptada, todavía no arrancó", `started_at` nullable hasta ese momento. Todos los cálculos de "conductor ocupado" ya filtraban exactamente por `status = 'in_progress'`, así que `scheduled` queda afuera automáticamente sin tocar esas consultas. Se instaló `doctrine/dbal` (dependencia estándar de Laravel para modificar columnas existentes — la alternativa de SQL crudo por motor de base de datos rompía los tests, que corren sobre SQLite en memoria y sí validan el `CHECK` del enum a diferencia de MySQL).
- **Nueva acción `RideController::start()`** (`POST /carreras/{ride}/arrancar`): solo el conductor de esa carrera puede arrancarla, solo si sigue en `scheduled`. Ahí sí pasa a `in_progress` con `started_at = now()`. Nuevo evento `RideStarted` (mismo criterio de fan-out que `RideCompleted`: avisa a todas las flotas donde el conductor es miembro activo, más el canal personal de las dos partes de esa carrera puntual) y `RideStartedPushNotification` al cliente.
- **`RideController::index()`**: nueva lista `scheduledRides` (ni "en curso" ni "historial"); `rideHistory` ahora excluye también `scheduled`, no solo `in_progress`.
- **`DashboardController::upcomingTripsFor()`**: "Próximos viajes" ahora también incluye las programadas, ordenadas por cercanía a "ahora" (no por fecha descendente a secas — una solicitud recién pedida y una carrera programada para dentro de un rato importan más que una para dentro de tres días).
- **Frontend**: `Ride/Request.vue` suma la sección "¿Cuándo salís?" (toggle Ahora mismo/Programar + fecha/hora + checkbox de ida y vuelta); `Ride/Index.vue` suma la sección "Programados" con el botón "Iniciar viaje" para el conductor; `Ride/Show.vue` y `Dashboard.vue` muestran la fecha/hora programada y el badge de ida y vuelta donde corresponde.

### Tests
`RideRequestFlowTest`: seis casos nuevos — programar con fecha/hora futura + ida y vuelta, programar en el pasado se rechaza, aceptar una programada crea la `Ride` en `scheduled` sin `started_at`, el conductor puede arrancarla, el cliente NO puede arrancarla, no se puede arrancar una que ya está en curso. `PushNotificationTest`: arrancar una programada notifica al cliente. Verificado con la suite completa (`php artisan test`, 205 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Zona de cobertura del conductor (evitar solicitudes que no convienen por los km)
Pedido explícito del usuario: un conductor de Samborondón no quiere que le lleguen solicitudes del Guasmo, aunque el cliente sea de su propia flota — "si está fuera de rango, tiene que estar fuera de rango" sin excepción por confianza previa.

- **`driver_profiles.max_request_distance_km`** (nullable): el conductor la configura desde "Mi perfil" (conductor) — en blanco = sin límite, comportamiento idéntico al de antes para cualquier cuenta que no la toque.
- **`DriverProfile::isWithinRangeOf($lat, $lng)`** (nuevo): compara contra la ubicación actual del conductor con Haversine (ya existente, PHP puro — se evitó SQL crudo con funciones trigonométricas por portabilidad entre MySQL y el SQLite de los tests). Sin límite configurado o sin ubicación conocida todavía, deja pasar (no hay nada que descartar con la información disponible).
- **Se aplicó en los tres lugares donde un conductor "se entera" de una solicitud**, no solo en el aviso en vivo:
  1. `RideRequestController::store()`: una solicitud DIRIGIDA a un conductor fuera de su propio rango se rechaza de una (no se puede pedir igual manipulando el formulario).
  2. `RideRequested::broadcastOn()`: para "toda la flota", en vez de broadcastear al canal compartido de la flota (donde no hay forma de excluir a alguien puntual), ahora calcula qué miembros activos están dentro de su propio rango y les manda el aviso por su canal PERSONAL — el resto de los eventos de esa misma solicitud (contraoferta, cancelada, etc.) siguen yendo por el canal de flota como siempre, sin cambios.
  3. `RideController::index()` (carga inicial de "Carreras") y `DashboardController` (contador de la tarjeta "Solicitudes"): mismo filtro, para que lo que ve al entrar coincida con lo que le llegó en vivo.
- **`Ride/Request.vue`**: el conductor fuera de rango se ve atenuado (gris) y no se puede seleccionar, con la etiqueta "Fuera de su zona de cobertura" en vez del estado habitual.

### Tests
`tests/Feature/Ride/DriverCoverageRangeTest.php` (nuevo): configurar el límite desde el perfil, solicitud dirigida fuera de rango rechazada, dentro de rango funciona, "toda la flota" solo notifica al conductor cercano, el conductor lejano ni siquiera ve la solicitud al entrar a /carreras. Verificado con la suite completa (`php artisan test`, 210 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Referí a tu conductor (enlace compartible)
Pedido explícito del usuario: que un cliente pueda recomendar a su conductor de confianza a otras personas mandándoles un enlace.

- Reutiliza el `invite_code` que el conductor ya tenía (no genera uno nuevo por cliente que refiere — es el conductor el que se recomienda, no un cupón de quien comparte).
- **`GET /referir/{invite_code}`** (nuevo, público, sin sesión — igual que el seguimiento en vivo compartible): landing con el nombre, avatar, calificación y vehículo del conductor. Si quien la abre no tiene cuenta, ve botones de crear cuenta/iniciar sesión (sin redirect automático de vuelta al enlace después — simplificación deliberada, dado que el enlace queda estable y se puede volver a abrir después de loguearse). Si ya es cliente, un botón "Agregar a mi flota".
- **`POST /referir/{invite_code}`** (nuevo, requiere sesión de cliente): reutiliza tal cual la lógica ya existente de `FleetInvitationController::store()` (cupo del plan, "ya es miembro", "ya invitado", notificación push, broadcast) en vez de duplicarla — solo resuelve la flota del referente y le suma el `driver_user_id`.
- **`Fleet/Show.vue`**: nuevo botón "Referir" junto a cada conductor de la flota — Web Share API si el navegador la soporta (celular), copia al portapapeles si no (mismo criterio que "Compartí tu código" del inicio del conductor).

### Tests
`tests/Feature/Fleet/ReferralTest.php` (nuevo): la landing es visible sin sesión, un cliente logueado puede mandar la invitación, un conductor NO puede, la landing avisa si el conductor ya es de la flota, no se puede re-invitar a alguien que ya es miembro. Verificado con la suite completa (`php artisan test`, 215 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix crítico: pedir una carrera "ahora mismo" quedaba bloqueado
El usuario mismo encontró la causa exacta: `scheduled_date`/`scheduled_time` se mandaban como `''` (string vacío, no ausentes) en modo "ahora mismo", y sin `nullable` en la validación, `date_format` los rechazaba igual aunque `required_if:is_scheduled,1` correctamente no los exigiera — bloqueaba CUALQUIER carrera para ahora con un error de formato de fecha. Se agregó `'nullable'` antes de `required_if`/`date_format` en ambos campos (`RideRequestController::store()`). Test de regresión que reproduce el payload exacto del formulario.

### Fix: error de CORS al trazar la ruta en el mapa
La consola mostraba `Access-Control-Allow-Headers` bloqueando `router.project-osrm.org`. Causa: Laravel Echo, apenas se conecta, mete un header `X-Socket-Id` en TODAS las peticiones de `window.axios` (necesario para que `->toOthers()` funcione contra nuestro propio backend) — OSRM no lo tiene permitido en su CORS, así que el preflight fallaba y el trazado del recorrido nunca se pedía de verdad. Se cambió esa única llamada a `fetch()` nativo en `Ride/Request.vue`, que no hereda esos headers.

### Elegir un conductor ofrece pedirle una carrera directo
Pedido explícito del usuario: "cuando seleccione un conductor debería darme la opción de pedir una carrera, por defecto para ese conductor". `RideRequestController::create()` acepta `?conductor=ID` y lo pasa como `preselectedDriverId`; `Ride/Request.vue` lo usa para preseleccionar (validado contra `fleetDrivers`/`publicDrivers` del lado del cliente). Se agregó el link "Pedir carrera" en los cuatro lugares donde el cliente ve una tarjeta de conductor: `Dashboard.vue` (Mi flota y Conductores cerca), `Fleet/Show.vue`, `Directory/Index.vue` y `Profile/Show.vue` (perfil público, solo si quien mira es cliente).

### Tarifa base mínima de la plataforma
El usuario pidió una carrera de 2 km y salió $1 — muy poco para que le convenga al conductor. Nueva `pricing_settings.minimum_fare` (default $2, editable desde `/admin/tarifas`): `PriceCalculator::suggestedPrice()` ahora usa `max(distancia × tarifa, mínimo)` como base antes de aplicar el recargo nocturno.

### Deshabilitar solicitudes de un cliente puntual (sin salir de la flota)
Pedido explícito del usuario: el conductor puede dejar de recibir pedidos de un cliente específico sin cortar la relación entera. Nueva `fleet_members.requests_disabled` — mismo criterio que la zona de cobertura por distancia (Directrices sección "batería de observaciones"): se filtra en los mismos tres puntos (validación al pedir dirigida, notificación/broadcast de "toda la flota", listados de carga inicial).

### "Mis clientes de confianza" con ficha completa
Pedido explícito del usuario: foto, nombre, puntos, cuántas carreras le hizo, su último viaje y su categoría (reutiliza `DriverCategory::forRating()`, ya existente para el directorio de conductores). El botón "Salir" ahora dice "No es mi cliente" (más claro desde el punto de vista del conductor); nuevo botón "Deshabilitar solicitudes" junto a cada cliente.

### Fix: la disponibilidad del conductor no se apagaba al cerrar sesión
Se reportó: un conductor sin sesión iniciada seguía apareciendo "disponible" para su flota. `AuthenticatedSessionController::destroy()` ahora apaga `is_available` y transmite `DriverLocationUpdated` antes de cerrar la sesión, si la cuenta es de un conductor que seguía disponible. **Límite conocido, no resuelto**: esto cubre el logout explícito, no un cierre de sesión por expiración/navegador cerrado sin pasar por esa ruta — resolver eso de verdad necesitaría un mecanismo de sondeo/heartbeat, que no se construyó en esta pasada por no estar pedido explícitamente todavía.

### Tests
`RideRequestFlowTest`: preselección de conductor (con y sin query param). `AdminPricingMaintenanceTest`: tarifa mínima aplicada y actualizable. `tests/Feature/Fleet/DisableClientRequestsTest.php` (nuevo): activar/desactivar, un extraño no puede tocar la membresía ajena, solicitud dirigida a un deshabilitado se rechaza, "toda la flota" no notifica al deshabilitado, la ficha enriquecida trae los datos correctos. `AuthenticationTest`: cerrar sesión apaga la disponibilidad del conductor (y no rompe para clientes ni para un conductor ya desconectado). Verificado con la suite completa (`php artisan test`, 228 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: migración pendiente sin aplicar en la base local
El usuario reportó un error 500 (`Unknown column 'requests_disabled'`) al entrar al Inicio como conductor, y que no le llegó el aviso sonoro de una carrera pedida con la nueva opción de preselección. Causa: la migración de `requests_disabled` (bloque anterior) se había verificado solo contra la base de tests (SQLite en memoria, se recrea sola en cada corrida) — nunca se corrió `php artisan migrate` sobre la base de desarrollo real después de crearla. Sin eso, la página entera del conductor tiraba 500 antes de montar el componente Vue, así que ninguna suscripción a Echo llegaba a ejecutarse (de ahí el silencio). Ya aplicada.

### Fix: color ilegible en selects de ciudad (y el de flota)
El selector de ciudad en "Mi perfil" (y el de flota en "Solicitar carrera", mismo patrón) usaba un `<select>` nativo con fondo transparente — el navegador pinta el panel desplegable con el tema del sistema operativo, no con las clases de Tailwind, y el texto quedaba casi invisible. Mismo bug ya resuelto antes para ciudad/sector en "Solicitar carrera" — se reemplazaron por `SearchableSelect.vue` en los dos lugares que quedaban.

### Fix: la "cercanía" de cada conductor no se actualizaba en vivo al pedir carrera
En `Ride/Request.vue`, el listener de `.driver.location.updated` solo actualizaba el estado (disponible/en carrera), nunca `current_lat`/`current_lng` — la distancia mostrada contra el cliente que está pidiendo la carrera quedaba pegada en la que había al cargar la pantalla, sin reflejar que el conductor se siguiera moviendo de verdad. Ahora también actualiza la posición.

### Acceso directo "Programar carrera" desde el Inicio del cliente
Pedido explícito del usuario: nueva tarjeta en "Solicitá un viaje" que lleva a `ride-requests.create` con `?programar=1` — `Ride/Request.vue` arranca directo en modo "Programar viaje" en vez de "Ahora mismo", sin tener que tocar el toggle a mano.

### Ficha enriquecida al buscar conductores para invitar
Pedido explícito del usuario: el buscador de "Mi flota" ahora muestra foto, puntaje, cantidad de carreras completadas y categoría (reutiliza `DriverCategory::forRating()`) de cada resultado — antes solo nombre/teléfono/tarifa.

### Tests
`AdminPricingMaintenanceTest` y el resto de la suite ya cubrían la migración aplicada. `FleetInvitationFlowTest`: caso nuevo con la ficha enriquecida de búsqueda (foto, rating, carreras, categoría). El fix de distancia en vivo y el de color de los selects son de frontend puro, verificados por lectura de código y `npm run build`. Verificado con la suite completa (`php artisan test`, 229 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: ReferenceError rompía toda la pantalla de Mi perfil
Al reemplazar el `<select>` de ciudad por `SearchableSelect` (arriba), el nuevo `cityOptions` computed leía `props.cities` pero nunca se guardó el resultado de `defineProps()` en una variable `props` — `<script setup>` no expone ese identificador solo, hay que capturarlo a mano. Rompía el render entero de esa pantalla (`ReferenceError`, pantalla en negro). Corregido.

### Diseño propio para los diálogos de confirmación (antes `window.confirm()`)
El usuario pidió mejorar el diseño de las alertas — un cuadro de confirmación nativo del navegador no se puede re-estilar, se ve del tema del sistema operativo, no de Arka01 (se ve en la captura: "¿Seguro que ya no es tu cliente?"). Se reemplazaron los **11** usos de `confirm()` en toda la app por un diálogo propio con el estilo oscuro de la app:

- **`Utils/confirmDialog.js`** (nuevo): estado reactivo global + `confirmDialog(mensaje, opciones)`, una función que devuelve una Promise (`await confirmDialog('¿Seguro...?')`, casi igual que antes).
- **`Components/ConfirmDialogHost.vue`** (nuevo): reutiliza `Modal.vue` ya existente, con botón de "Aceptar" en rojo (`DangerButton`) para acciones destructivas y verde (`PrimaryButton`) para el resto — se decide por opción, no por adivinar.
- Montado **una sola vez** en la raíz (`resources/js/app.js`, junto al `<App>` de Inertia), así cualquier pantalla lo usa sin montar nada propio.
- Reemplazado en: `Security/TrustedContacts.vue`, `Components/SubscriptionRequestPanel.vue`, `Fleet/Show.vue`, `Admin/Subscriptions.vue`, `Admin/Locations.vue` (dos casos), `Admin/Plans.vue`, `Ride/Show.vue` (SOS), `Driver/Invitations.vue`, `Express/Show.vue` (dos casos).

### Tests
Sin tests nuevos (cambio de UI puro, sin lógica de negocio — las funciones que antes usaban `confirm()` siguen haciendo el mismo `router.post/delete` de siempre, solo cambia cómo se pide la confirmación). Verificado con la suite completa (`php artisan test`, 229 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: invitar a un conductor a la flota no le avisaba nada
El usuario probó agregar a Ana desde "Conductores cerca" y, parada en su propio Inicio, a Ana no le llegó ningún aviso. Causa: `FleetInvitationCreated` ya se transmitía por WebSocket desde hace tiempo, pero el único lugar que escuchaba `.fleet-invitation.created` era `Driver/Invitations.vue` — si el conductor estaba en cualquier otra pantalla (como el Inicio, el caso reportado), no pasaba nada visible, ni push (además de que el entorno local no tiene una suscripción real).

- **`DashboardController`**: nuevo `driverStats.pending_invitations` (cuántas invitaciones pendientes tiene el conductor).
- **`Dashboard.vue`**: se suscribe a `.fleet-invitation.created` en el canal personal (mismo patrón ya usado para `.ride-request.created`) — sonido + vibración (`playAttentionAlert()`), banner "¡Te invitaron a una flota!" con link a "Mis clientes", y badge con el número en la tarjeta "Mis clientes" de Acciones rápidas (mismo estilo que el badge de "Solicitudes").

### Tests
`DashboardTest`: caso nuevo — `driverStats.pending_invitations` cuenta las invitaciones pendientes reales. Verificado con la suite completa (`php artisan test`, 216 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Despacho secuencial estilo Uber: al más cercano primero, con 30 seg. para responder
Pedido explícito del usuario: "toda la flota disponible" ya no le avisa a todos los conductores a la vez — ahora se le ofrece la carrera a un candidato por vez, empezando por el más cercano al origen, con 30 segundos para responder antes de pasar al siguiente ("como las de Uber"). Respeta la bolsa que el cliente eligió en el toggle "¿A quién se la pedís?" (mi flota / directorio público / ambos).

- **`ride_requests`**: tres columnas nuevas — `dispatch_pool` (de qué bolsa salen los candidatos), `offer_candidate_ids` (JSON, el resto de la bolsa en orden), `current_offer_expires_at` (cuándo vence el turno del candidato actual).
- **`App\Services\RideDispatchCandidates::forPool()`** (nuevo): arma la lista de candidatos elegibles de la bolsa pedida, ordenada por cercanía real (`Haversine::distanceKm`) al origen — excluye conductores no disponibles, en carrera, que deshabilitaron al cliente (`requests_disabled`) o fuera de su zona de cobertura (`isWithinRangeOf`). Un conductor sin ubicación conocida todavía (recién conectado) no queda afuera de la bolsa, se ordena al final.
- **`App\Services\RideDispatchAdvancer::advanceOrExpire()`** (nuevo): pasa al siguiente candidato de la bolsa (reabre el cronómetro de 30 seg.) o expira la solicitud si ya no queda ninguno — con guardas contra condiciones de carrera (`lockForUpdate`, chequeo de que la solicitud siga siendo del candidato esperado y siga `pending`). Un solo lugar, reutilizado tanto por el vencimiento del cronómetro como por un rechazo explícito.
- **`App\Jobs\ExpireRideOffer`** (nuevo): Job encolado con 30 seg. de retraso al ofrecerle la carrera a cada candidato — llama a `RideDispatchAdvancer` cuando corre. Requiere `QUEUE_CONNECTION=database` (antes era `sync`, que ignoraba cualquier retraso) y un `php artisan queue:work` corriendo — **antes no hacía falta ningún worker, ahora sí, para que la cascada de 30 segundos funcione en desarrollo**.
- **`RideRequestController::store()`**: sin conductor puntual y "ahora mismo" (no programada), arma la bolsa con `RideDispatchCandidates`, asigna el primero como `driver_user_id` (reutilizando toda la lógica existente de aceptar/contraofertar/rechazar, que ya funcionaba sobre ese campo) y guarda el resto para la cascada. Si no hay ningún candidato disponible, error de validación claro. Una solicitud **programada** sin conductor puntual mantiene el aviso a toda la flota de siempre, sin cascada — no tiene sentido meterle presión de 30 segundos a algo que es para más tarde.
- **`RideRequestController::reject()`**: un rechazo explícito del conductor actual pasa la solicitud al siguiente candidato de inmediato (no espera a que se cumplan los 30 seg.) si es despacho secuencial; una solicitud dirigida a un conductor puntual se sigue cancelando como siempre.
- **`RideRequestExpired`** + **`RideRequestExpiredPushNotification`** (nuevos): cuando nadie de la bolsa respondió a tiempo, avisa al cliente (WebSocket + push) para que suba su oferta o pruebe con otra bolsa, en vez de quedarse esperando en silencio.
- **Frontend**: `Ride/Request.vue` manda el `dispatch_pool` elegido y relabela "Toda la flota disponible" según la bolsa; `Ride/Index.vue` muestra un contéo regresivo en la tarjeta de solicitud entrante del conductor (⏱ X seg. para responder) y, del lado del cliente, un mensaje claro + botón "Pedir de nuevo" cuando la solicitud expira.

### Tests
`tests/Feature/Ride/SequentialDispatchTest.php` (nuevo, 9 casos): orden por cercanía y exclusiones de `RideDispatchCandidates`, asignación del más cercano en `store()`, error sin candidatos, la solicitud programada no entra en cascada, un rechazo avanza al siguiente candidato, un rechazo sin más candidatos expira la solicitud, y las guardas contra condiciones de carrera de `RideDispatchAdvancer`. Se ajustaron 4 tests existentes que pedían "toda la flota" (`RideRequestFlowTest`, `DisableClientRequestsTest`, `DriverCoverageRangeTest`, `PushNotificationTest`) para reflejar que ahora se asigna un candidato puntual en vez de quedar `driver_user_id` en null, con `Queue::fake()` para que `ExpireRideOffer` no corra de una bajo `QUEUE_CONNECTION=sync` (forzado en `phpunit.xml`) y adelante la cascada dentro del mismo test. Verificado con la suite completa (`php artisan test`, 238 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Preparación para producción: rendimiento y seguridad
El usuario preguntó si la plataforma aguantaría miles de usuarios simultáneos en producción. Auditoría concreta del código (no genérica) antes de tocar nada: índices de BD, rate limiting, configuración de sesión/caché/cola/broadcasting, CORS, headers de seguridad, consultas sin límite y logging de datos sensibles. De ahí, se separó en dos grupos — lo que se podía resolver ya (código, sin costo) y lo que es una decisión del usuario por implicar infraestructura o gasto recurrente (Redis para caché/cola/broadcasting a escala, hosting de producción real en vez de Laragon, y si conviene restringir CORS del wildcard `*` — hoy irrelevante al ser un monolito Inertia sin API pública consumida desde otro dominio).

Lo resuelto en este batch:
- **Índices faltantes** (`2026_08_05_164351_...`): `ride_requests.current_offer_expires_at` (la columna que golpea la cascada del despacho secuencial) y `driver_profiles.is_available` (la que filtra antes de calcular distancia en `RideDispatchCandidates`) — antes forzaban escaneo completo de tabla.
- **Rate limiting** en los endpoints que no tenían ningún límite: `ride-requests.store` (10/min), `driver.location.update` (20/min — el frontend la llama cada ~15s, así que da margen sin dejar la puerta abierta a inundarla), registro de cuenta y envío inicial de verificación por WhatsApp (6/min), y login (10/min por IP, además del bloqueo por email+IP que ya existía en `LoginRequest`, que no cubre un barrido de contraseñas contra muchos usuarios distintos desde la misma IP).
- **`App\Http\Middleware\SecurityHeaders`** (nuevo, en el grupo `web` del Kernel): Content-Security-Policy, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, Permissions-Policy y HSTS (solo si la request es HTTPS). El CSP no es "todo self" a rajatabla — permite `unsafe-inline` en scripts porque Ziggy (`@routes`) imprime un script inline en cada página (una versión más estricta con nonces queda fuera de este alcance), y lista explícitamente los orígenes externos que la app de verdad usa: OpenStreetMap (tiles del mapa), OSRM (trazado de ruta), Google Maps/Places (autocompletado de direcciones, opcional según `VITE_GOOGLE_MAPS_API_KEY`) y `ws:`/`wss:` para Reverb.
- **`Admin/MetricsController`**: N+1 real, no solo falta de límite — `activeSubscription()` disparaba una consulta nueva por cada usuario suscripto, a pesar de que la relación ya venía precargada (y ya ordenada) con exactamente los mismos datos. Se cambió a usar la colección ya cargada (`$user->subscriptions->first()`) en vez de volver a consultar.
- **`Admin/DriverVerificationController`**: tope de 200 en la cola de verificaciones pendientes — no se justificó paginación de verdad (pantalla de admin, cola de revisión manual, no un listado que un usuario final recorra).

Fuera de este batch, documentado como pendiente para cuando el piloto se acerque a un despliegue real: mover caché/cola/broadcasting a Redis (`CACHE_DRIVER=file` y `QUEUE_CONNECTION=database` no escalan bien con miles de usuarios ni con más de un servidor), elegir hosting de producción de verdad, y — importante, no es un cambio de código sino un recordatorio operativo — el `.env` del servidor de producción tiene que tener `APP_DEBUG=false` y `APP_ENV=production` (hoy en local están en `true`/`local` a propósito, así tiene que seguir localmente).

### Tests
Sin tests nuevos — son cambios de infraestructura transversal (índices, middleware, límites de tasa) sin lógica de negocio propia que testear, salvo el fix del N+1 en `MetricsController`, ya cubierto por el caso existente `admin metrics page reports the plan breakdown` (`AdminSubscriptionTest`), que sigue devolviendo los mismos números con la consulta nueva. El CSP se verificó con `curl` (headers bien formados) y rastreando en el código cada recurso externo que la app carga de verdad, ya que este entorno no tiene un navegador headless disponible para confirmarlo visualmente — recomendado hacer una pasada manual en el navegador antes de desplegar. Verificado con la suite completa (`php artisan test`, 238 tests OK) y `./vendor/bin/pint --dirty`. Sin cambios de frontend en este batch (no hizo falta `npm run build`).

### Fix urgente: el CSP nuevo dejaba la app en blanco en desarrollo
Reportado por el usuario con captura de DevTools, dos veces: pantalla completamente en blanco en `/carreras/4`, con la consola marcando que el `Content-Security-Policy` del batch anterior bloqueaba `http://[::1]:5173/@vite/client` y el resto de los scripts que sirve `npm run dev` — el CSP solo contemplaba los orígenes que usa el **build de producción** (mismo origen), nunca el servidor de desarrollo de Vite, que sirve todo desde otro puerto (5173) para el hot-reload.

Primer intento (insuficiente): agregar explícitamente `localhost`/`127.0.0.1`/`[::1]` puerto 5173 a `script-src`/`style-src`/`connect-src` cuando `app()->environment('local')`. No alcanzó — Chrome directamente descarta como **"invalid source"** cualquier origen CSP con el literal IPv6 entre corchetes (`http://[::1]:5173`, `ws://[::1]:5173`), que es justo el que Vite eligió en esta máquina; ninguna lista de orígenes explícitos lo cubre de forma confiable.

Solución final: `App\Http\Middleware\SecurityHeaders` directamente **no aplica el header `Content-Security-Policy` en local** (`app()->environment('local')`) — el resto de los headers (X-Frame-Options, etc.) se mantienen igual en cualquier entorno. El CSP es una protección pensada para producción (todo servido desde el mismo origen, sin dev server); en local, la única persona navegando es quien está programando en su propia máquina, no hay una amenaza real que mitigar ahí, y perseguir el origen exacto que Vite decide usar cada vez es frágil. En producción (`APP_ENV=production`) el CSP se aplica sin cambios.

### Fix: el estimado de precio mostraba un cálculo engañoso cuando se aplicaba la tarifa mínima
El usuario reportó (con captura) que le llegaba la tarifa base de $2 pero la pantalla de pedir carrera seguía mostrando "0.7 km × $0.45/km = $0.31 (estimado)" — un cálculo que no es el que se termina cobrando. Causa: el frontend calculaba el estimado en JS puro (distancia × tarifa) sin saber que el backend (`PriceCalculator::suggestedPrice()`) aplica una tarifa mínima configurable cuando ese cálculo da menos que el mínimo.

- `RideRequestController::create()` ahora manda `minimumFare` (de `PricingSetting::current()`) como prop.
- `Ride/Request.vue` replica el mismo `max(distancia×tarifa, mínimo)` y, cuando el mínimo es lo que manda, muestra "Tarifa mínima de la plataforma" en vez del desglose por km.

### Fix: un conductor aparecía "disponible" sin estarlo de verdad
El usuario notó conductores marcados como conectados que en realidad no lo estaban. Causa real: `is_available` solo se apagaba con una acción explícita (logout, o tocar el switch) — si el celular se quedaba sin batería, se cerraba la app o se perdía la señal, el ping de ubicación (~15s) simplemente dejaba de llegar y nada apagaba el flag en la base. Confirmado en la propia base de datos local: al correr el fix por primera vez, desconectó 3 conductores de prueba que llevaban así.

- **`App\Console\Commands\SweepStaleDriverAvailability`** (nuevo, corre cada 2 minutos vía Scheduler): marca `is_available = false` a todo conductor "disponible" sin ping en los últimos 2 minutos, y avisa por WebSocket (mismo evento que un ping real) para que cualquier mapa/lista abierta lo saque de inmediato.

### Rediseño de la notificación de carrera entrante (sonido, vibración, modal a media pantalla)
Pedido explícito del usuario: sonido más fuerte, vibración, que ocupe media pantalla, y botones Aceptar/Descartar además de la X del modal.

- **`liveAlert.js`**: nuevo `playIncomingRideAlert()` — tres tonos (en vez de dos) con más volumen, y una vibración más larga tipo "llamada entrante", específico para esta notificación.
- **`Utils/incomingRideRequest.js`** + **`Components/IncomingRideRequestModal.vue`** (nuevos): mismo patrón que `confirmDialog`/`ConfirmDialogHost` — estado global + un solo componente montado una vez, esta vez en `AuthenticatedLayout.vue` (no en cada página), así le llega al conductor sin importar en qué pantalla esté. Reutiliza `BottomSheet.vue` (media pantalla real, no un modal centrado) con el mismo contéo regresivo de 30 seg. del despacho secuencial, y Aceptar/Descartar/X.
- Como el canal personal ahora lo maneja este modal global, `Ride/Index.vue` y `Dashboard.vue` ya NO duplican el sonido/lista para ese mismo evento (sonarían dos veces) — el canal de FLOTA (solicitudes "programadas" a toda la flota, sin apuro) sigue exactamente igual que antes.

### Bitácora de rechazos por conductor
Contador simple (`driver_profiles.rides_rejected_count`, no una tabla de auditoría aparte — alcanza para "ver de un vistazo quién rechaza demasiado"), incrementado en `RideRequestController::reject()`. A propósito NO cuenta un timeout de los 30 segundos sin responder — un rechazo explícito es una decisión real del conductor, un timeout puede ser solo que no tenía la app abierta.

### Cancelar una carrera ya aceptada
Gap real que no existía: una vez que el conductor aceptaba, el cliente no tenía ninguna forma de cancelar, ni siquiera si la carrera todavía estaba "programada" sin arrancar. El estado `cancelled` de `rides` existía en el enum desde el principio pero nunca se usaba.

- `rides.cancelled_at` (nueva columna, mismo criterio que `completed_at`/`paid_at` — sirve para mostrarlo y para medir cuántas se cancelan).
- `RideController::cancel()` (nuevo): solo el cliente, solo mientras el conductor no la completó (`scheduled` o `in_progress`). Avisa al conductor por WebSocket (`RideCancelled`, lo libera de "en carrera" en todas sus flotas, mismo criterio que al completar) + push (`RideCancelledPushNotification`) — importante sobre todo si ya iba en camino.
- Botón "Cancelar carrera" en `Ride/Show.vue`, con el mensaje de confirmación distinguiendo si el conductor ya está en camino o no.

### Panel admin de conductores
Pedido explícito del usuario: ver quién está disponible con su ubicación (que desaparezcan al no estarlo), y poder "bloquear o deshabilitar o desconectar" a uno puntual. Las tres palabras apuntan al mismo problema de fondo, así que se resolvieron con un solo mecanismo en vez de tres redundantes:

- `driver_profiles.suspended_at` (nuevo, a propósito **fuera** de `$fillable` — solo se toca desde el controlador de admin, nunca desde el propio formulario del conductor). Mientras esté suspendido: no puede reconectarse él mismo (`DriverLocationController::update()` devuelve 403), no aparece como candidato de ninguna solicitud (`RideDispatchCandidates`), y no aparece en el directorio público.
- **`Admin\DriverController`** (nuevo) + `Admin/Drivers.vue`: mapa + lista de disponibles ahora mismo (se refresca sola cada 20 seg. — no amerita WebSocket para un panel de admin), y el roster completo con botón Suspender/Reactivar, rechazos y carreras completadas por conductor.

### Centro de operaciones (mapa de demanda, conectados, demanda por horario/zona, avisos)
Cubre varios pedidos juntos porque son la misma pantalla de "qué está pasando ahora": dónde se concentran las solicitudes activas, cuántos clientes/conductores están conectados, la demanda histórica por horario y por zona, el tiempo de espera real de un cliente hasta conseguir conductor, y avisarle a los conductores cercanos que hay demanda.

- **`Admin\OperationsController`** (nuevo) + `Admin/Operations.vue`: mapa de solicitudes `pending`/`negotiating` activas; conectados (reutiliza la tabla `sessions` de `SESSION_DRIVER=database` — `last_activity` ya es exactamente "la última vez que hizo algo", no hizo falta inventar un mecanismo de presencia aparte), separados por `users.role`; demanda por hora (0-23) y por zona (sector), calculado en PHP y no con `HOUR()`/`TIMESTAMPDIFF()` de MySQL (no existen en SQLite, el motor de los tests); tiempo de espera promedio (`responded_at - requested_at` de las aceptadas) y cancelaciones separadas en "en camino" vs "antes de salir" (según si `started_at` ya estaba puesto).
- **Avisar demanda cercana**: `notifyNearby()` toma las solicitudes activas de un sector, calcula el centroide de sus coordenadas (Sector no guarda lat/lng propia, solo nombre), y le manda `DemandAlertPushNotification` a los conductores disponibles dentro de 5 km — el mensaje le avisa a cada uno si esa demanda es de una flota suya o no.

### Tests
`tests/Feature/Console/SweepStaleDriverAvailabilityTest.php` (4 casos), `tests/Feature/Admin/AdminDriverControllerTest.php` (5 casos: acceso, lista en vivo, suspender/reactivar, que un suspendido no se reconecte), `tests/Feature/Admin/AdminOperationsControllerTest.php` (4 casos: demanda activa/espera/cancelaciones, aviso de demanda respeta radio y marca flota propia, error sin demanda activa), más un caso nuevo en `RideRequestFlowTest` (mínimo de tarifa expuesto) y cinco en el mismo archivo para cancelar una carrera aceptada. Verificado con la suite completa (`php artisan test`, 258 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Pedir carrera más simple: buscador con Google Places como campo principal, y "tus lugares" favoritos
El usuario pidió simplificar la pantalla de pedir carrera — mostraba Ciudad, dos selects de sector, un campo de "referencia" (opcional, casi escondido) y recién ahí el mapa para marcar destino: cinco pasos separados para algo que tendría que sentirse tan simple como escribir a dónde vas. Lo que no se sabía es que el campo de "referencia" YA usaba Google Places de fondo (`AddressAutocomplete.vue`, de una pasada anterior) — el problema no era que faltara Google, era que estaba etiquetado y ubicado como un detalle opcional en vez de ser el campo principal.

- **`Ride/Request.vue`**: el buscador de dirección pasa a ser el campo principal, con las etiquetas "Origen"/"Destino" bien visibles arriba de todo (antes de elegir conductor). Ciudad y los dos selects de sector quedan colapsados detrás de un "Precisar ciudad/sector (opcional)" — siguen existiendo (hacen falta para la analítica de zona del centro de operaciones, sección de arriba), pero ya no son el primer paso obligatorio. El mapa se mantiene como confirmación visual y para ajustar el destino a mano tocándolo, y ahora también marca el ORIGEN (antes solo mostraba destino y conductores).
- **"Tus lugares" favoritos** (pedido explícito del usuario: "guardá las que ya ha realizado... que aparezcan como favoritas"): `RideRequestController::create()` arma `frequentPlaces` a partir de las últimas 50 solicitudes del cliente (origen y destino, da igual — "casa" puede ser cualquiera de los dos un día distinto), agrupadas por dirección exacta y ordenadas por cuántas veces se repite. `AddressAutocomplete.vue` los muestra con una ★ apenas se enfoca el campo VACÍO, antes de escribir nada (mismo criterio que "lugares recientes" de cualquier app de viajes) — elegir uno no gasta cuota de Google, porque ya se guardó su lat/lng de la vez anterior. Si el favorito también tenía un sector guardado, se autocompleta solo.

### Tests
`RideRequestFlowTest`: dos casos nuevos — los lugares frecuentes salen ordenados por repetición (el más usado primero), y una solicitud sin dirección no ensucia el listado. Cambios de `AddressAutocomplete.vue` (favoritos) verificados por lectura de código y `npm run build` — es lógica de UI pura (mostrar/ocultar un dropdown), sin contraparte de backend propia más allá de `frequentPlaces`, ya cubierto. Verificado con la suite completa (`php artisan test`, 260 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: el autocompletado de Google no funcionaba, y "usar mi ubicación actual" para el origen
El usuario reportó (con captura de consola) errores dentro del propio SDK de Google Maps al escribir en los campos de Origen/Destino — un `TypeError` sin atrapar dentro del código minificado de Google, no en el nuestro. Causa más probable: el proyecto de Google Cloud de la API key configurada no tiene habilitada "Places API (New)" (la que usan `AutocompleteSuggestion`/`AutocompleteSessionToken`) o le falta la cuenta de facturación — la librería de Google carga igual, pero cualquier llamada real falla adentro de su propio SDK de una forma que ni el `try/catch` de acá alcanza a atrapar del todo. Esto hay que revisarlo en la consola de Google Cloud (no es algo que se arregle solo con código); mientras tanto:

- **`Utils/googleMaps.js`**: después de cargar la librería, verifica que `AutocompleteSuggestion`/`AutocompleteSessionToken` de verdad existan — si faltan, se trata igual que "sin API key" (el campo sigue funcionando como texto libre, sin más).
- **`AddressAutocomplete.vue`**: mismo chequeo antes de llamar a `fetchAutocompleteSuggestions`, y el `try/catch` de `selectSuggestion` ahora envuelve también `toPlace()` (antes quedaba afuera).

También, pedido explícito del usuario: el campo Origen ahora tiene un botón "📍 Usar mi ubicación actual" (antes la geolocalización solo se intentaba una vez, en silencio, al abrir la pantalla — sin esto, si fallaba el primer intento o el cliente se movía, no había forma de reintentarlo desde la pantalla). Al usarlo (o al abrir la pantalla por primera vez), además se resuelve una dirección legible por geocodificación inversa con **Nominatim/OpenStreetMap** (gratis, sin API key — mismo criterio que ya se usa para el trazado del recorrido con OSRM) en vez de dejar el campo vacío con solo el pin puesto en el mapa.

### Tests
Sin tests nuevos — son fixes de robustez de un SDK externo (Google) y una función de geolocalización del navegador, ninguno con lógica de negocio propia para testear en PHPUnit. Verificado con la suite completa (`php artisan test`, 260 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: el modal de carrera entrante se quedaba pegado (y tiraba 403) cuando pasaba al siguiente conductor
El usuario reportó con capturas de dos pestañas a la vez: dos conductores viendo la misma solicitud, uno con "0 seg." pegado para siempre — y al tocar Aceptar en esa tarjeta vencida, un 403 sin estilo (ya no era suya, el despacho secuencial ya había pasado al siguiente conductor). Causa real: `AuthenticatedLayout.vue` (donde vive el modal global desde el batch anterior) solo escuchaba `.ride-request.created` en el canal personal — nunca `.ride-request.cancelled`, que es justo el evento que ya manda `RideDispatchAdvancer` cuando la cascada avanza o alguien la descarta desde otro lado. `Ride/Index.vue` sí lo escuchaba (por eso la LISTA se actualizaba bien); el modal nuevo, al vivir en un componente aparte, se había quedado afuera de ese aviso.

- `AuthenticatedLayout.vue`: agregado el listener de `.ride-request.cancelled` → saca la solicitud del modal apenas la cascada avanza, exactamente igual que ya hacía la lista.
- `IncomingRideRequestModal.vue`: respaldo adicional, por si ese aviso tarda o se pierde — al llegar a 0 seg. los botones se deshabilitan solos y, si en 3 segundos no llegó el aviso del servidor, se descarta localmente igual. Así nunca queda una tarjeta "viva" ofreciendo aceptar algo que ya es de otro conductor.

### Fix: el mapa de la carrera no se centraba en lo que estaba pasando
El usuario reportó (con captura) que en el seguimiento de una carrera en curso, el mapa mostraba un lugar random de Ecuador en vez de la zona real del viaje — "no veo lo que está sucediendo". Causa: `Components/FleetMap.vue` nunca se ajustaba solo a sus marcadores — si la pantalla no le pasaba un `:center` explícito (`Ride/Show.vue` nunca lo hacía), quedaba siempre centrado en el default fijo (Quito), sin importar dónde fuera la carrera de verdad.

- `FleetMap.vue`: ahora encuadra sus marcadores automáticamente la PRIMERA vez que aparecen (uno solo → centra ahí; dos o más → `fitBounds` con margen) — pero no en cada actualización después de esa, para no pelearse con un zoom/pan manual de quien está mirando el mapa cada vez que llega un ping de ubicación en vivo. Arreglado en el componente compartido, no en cada pantalla — beneficia también a `Ride/Show.vue`, `Admin/Drivers.vue` y `Admin/Operations.vue` por igual, sin tocar cada uno. Nueva prop `autoFit` para poder desactivarlo cuando una pantalla ya maneja su propio centrado (ver el fix de abajo).

### Fix: el campo de dirección "se quedaba pegado" y borraba lo que se estaba escribiendo
El usuario reportó (con captura de consola llena de errores) que a veces el campo de Origen/Destino se congelaba o perdía lo que estaba escribiendo. Causa real encontrada en `Ride/Request.vue`: la geolocalización automática + geocodificación inversa (agregadas en el batch anterior) tardan un par de segundos en resolver — si el cliente ya había empezado a escribir su propia referencia mientras tanto, el resultado async llegaba tarde y le PISABA lo que ya había tipeado, sin avisar. Un caso clásico de condición de carrera entre un proceso asíncrono lento y la escritura del usuario.

- `useCurrentLocationAsOrigin()` ahora distingue: el intento automático y silencioso de al abrir la pantalla ya NO pisa el campo si el cliente ya escribió algo ahí; el botón explícito "Usar mi ubicación actual" sí lo pisa siempre (es una acción a propósito, tiene que ganar).
- De paso, se corrigió que el botón pasara el evento del click como argumento a la función (inofensivo por cómo quedó escrita, pero confuso) y se desactivó el auto-encuadre nuevo de `FleetMap.vue` en esta pantalla puntual (`:auto-fit="false"`), porque acá el centrado ya lo maneja la propia geolocalización del cliente — las dos lógicas compitiendo por el mismo mapa podían generar comportamiento errático.

Sobre el aluvión de errores en consola ("Cannot read properties of null (reading 'emitsOptions')"): es un error interno del *runtime* de Vue, no de nuestro código — la causa más común es un estado de Hot Module Reload (`npm run dev`) que quedó inconsistente después de muchas ediciones en vivo con la pestaña abierta. Si después de este fix se lo sigue viendo, conviene un refresco fuerte (Ctrl+Shift+R) o reiniciar `npm run dev`; si persiste incluso así, probar en una ventana de incógnito sin extensiones (algunas, como traductores o bloqueadores, modifican el DOM en vivo y pueden chocar con las actualizaciones de Vue).

### Tests
Sin tests nuevos — son fixes de comportamiento de UI en vivo (WebSocket + mapa Leaflet), sin lógica de negocio de backend para testear en PHPUnit. Verificado con la suite completa (`php artisan test`, 260 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: rechazar una solicitud dirigida a un conductor puntual no le avisaba nada al cliente
El usuario reportó (con captura) que pidió la carrera a "Luis Manejo" puntualmente, el conductor la rechazó, y la tarjeta "Esperando respuesta" se quedó pegada en "Buscando entre tus conductores disponibles…" para siempre — sin ningún aviso de que en realidad ya la habían rechazado. Causa real: el evento que ya se disparaba en `reject()` (`RideRequestCancelled`) avisa al canal del **conductor** cuando la solicitud es dirigida — tiene sentido para cuando es el CLIENTE el que cancela (así el conductor la saca de su lista), pero es al revés de lo que hace falta acá: el conductor ya sabe que la rechazó, quien necesita enterarse es el cliente, y no había ningún evento apuntándole a él.

- **`RideRequestDeclined`** (evento nuevo) + **`RideRequestDeclinedPushNotification`** (nueva): avisan al CLIENTE, con el nombre del conductor que rechazó, cuando `reject()` resuelve una solicitud dirigida (no dispatch secuencial — ese caso ya tenía su propio aviso, `RideRequestExpired`).
- `Ride/Index.vue`: nueva tarjeta para `status === 'declined'` — "{{ conductor }} rechazó tu solicitud. Probá con toda tu flota o el directorio público.", con botones directos a "Pedir a toda mi flota" y "Ver directorio público" (la recomendación que pidió el usuario), más "Descartar".
- De paso, `waitingMessage()` decía siempre "buscando entre tus conductores disponibles" (plural) mientras esperaba — confuso para una solicitud dirigida a UN conductor puntual, que no está "buscando entre" nadie más. Ahora, si es dirigida, dice "Esperando que {conductor} responda…".

### Tests
`RideRequestFlowTest`: caso nuevo — rechazar una solicitud dirigida dispara `RideRequestDeclined` con el nombre correcto del conductor y notifica al cliente. Verificado con la suite completa (`php artisan test`, 261 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: la cascada del despacho secuencial le seguía ofreciendo la carrera a un conductor que ya se había desconectado
El usuario reportó (con captura): dos conductores activos, el cliente pidió "a toda la flota", el primero no respondió (o rechazó) y mientras tanto el SEGUNDO se desconectó a propósito — igual le llegó la notificación de "¡Carrera nueva!" cuando le tocó su turno. Causa real: `RideDispatchAdvancer::advanceOrExpire()` tomaba el siguiente candidato de `offer_candidate_ids` tal cual — esa lista se arma UNA sola vez, al principio (`RideDispatchCandidates::forPool()`), y nunca se volvía a chequear si ese candidato puntual seguía disponible en el momento real en que le tocaba el turno (podían pasar 30, 60 o más segundos desde que se armó la bolsa).

- **`RideDispatchCandidates::isStillEligible()`** (nuevo): mismos criterios que `forPool()` (disponible, no suspendido, no ocupado, dentro de su zona de cobertura) pero para un conductor puntual, re-chequeados en el momento de avanzar.
- **`RideDispatchAdvancer::advanceOrExpire()`**: ahora, al pasar al siguiente candidato, se salta a los que ya no son elegibles (uno por uno) hasta encontrar uno que sí lo sea — o hasta vaciar la bolsa, en cuyo caso expira en vez de ofrecérsela igual a alguien que ya no puede tomarla.

### Tests
`SequentialDispatchTest`: dos casos nuevos — la cascada salta a un candidato que se desconectó y avanza al siguiente elegible, y expira (en vez de ofrecer igual) cuando el único candidato que queda ya no es elegible. Verificado con la suite completa (`php artisan test`, 263 tests OK) y `./vendor/bin/pint --dirty`. Sin cambios de frontend en este batch.

### Etiquetas admin: comprobantes pendientes y "Mi suscripción" con viñetas explicadas
Dos pedidos del usuario sobre pantallas ya existentes que mostraban el dato crudo sin explicarlo:

- **`Admin/Subscriptions.vue`** (comprobantes pendientes de revisión): el usuario pidió saber si quien se suscribe es cliente o conductor — el dato (`plan.owner_type`) ya venía del backend sin usar; se agregó una etiqueta "Cliente"/"Conductor" junto al nombre en la lista y en el detalle del pago.
- **`Profile/Partials/SubscriptionSummary.vue`** ("Mi suscripción"): el usuario pidió una explicación clara en viñetas de qué incluye el plan vigente — antes solo mostraba el nombre del plan y el llamado a mejorar, sin decir qué significan "directorio público", "prioridad" o "insignia de verificado" (dudas textuales del usuario). Cada lado (conductor/cliente) ahora lista en viñetas, en criollo, qué incluye de verdad el plan actual — sin tocar el llamado a mejorar de plan, que sigue debajo tal cual estaba.

Nota aparte, no pedida pero encontrada al revisar esto: `verified_badge` (si el plan "incluye" la insignia de verificado) hoy es un campo configurable por plan que **no gatilla nada en el código** — en toda la app (`DriverDirectoryController`, `RideRequestController`), si un conductor aparece como "verificado" depende solo de `verification_status === 'approved'` (la aprobación manual de un admin), sin importar el plan. Si se quiere que la insignia realmente dependa del plan (no solo de la aprobación), es un cambio aparte — quedó anotado, no se tocó sin pedirlo.

### Tests
Sin tests nuevos — son cambios de presentación pura sobre datos que el backend ya exponía, sin lógica de negocio nueva para testear en PHPUnit. Verificado con `npm run build` y los tests existentes de `AdminSubscriptionTest` (5 OK, sin cambios de comportamiento).

### Capacidad del vehículo: pasajeros, cajuela, y solo mostrar conductores que califican
El usuario pidió, en un solo pedido grande: que al pedir una carrera se indique cuántos pasajeros van (por defecto 1) y si hace falta cajuela (por defecto no); que el conductor cargue en su perfil los datos de su vehículo — color, placa, cuántos pasajeros caben, si tiene cajuela — todos obligatorios; que la búsqueda de conductor filtre por esas características de verdad, no solo las muestre; y que un conductor con el perfil incompleto no se pueda poner disponible.

- **`driver_profiles`**: campos nuevos `vehicle_color`, `passenger_capacity`, `has_trunk`. **`ride_requests`**: campos nuevos `passenger_count` (por defecto 1) y `needs_trunk` (por defecto no).
- **`DriverProfile::hasCompleteVehicleInfo()`** (nuevo): marca/modelo/color/placa/año/capacidad, todos cargados. **`DriverProfileController`**: esos campos pasan de opcionales a obligatorios en la validación; `Driver/Profile.vue` los agrega al formulario (capacidad como número, cajuela como casillero) y ya no deja mandar el formulario sin ellos.
- **Bloqueo al ponerse disponible** (pedido explícito: "si no actualizó su perfil no lo dejes ponerse disponible"): `DriverLocationController::update()` rechaza con un 403 explicativo si el conductor intenta `is_available=true` con el perfil incompleto — el frontend (`DriverAvailabilityToggle.vue`) revierte el switch SOLO en ese caso puntual (nunca ante una falla de red pasajera, para no tirar a un conductor "offline" por una desconexión momentánea).
- **Filtro real, no solo visual**: `RideDispatchCandidates::forPool()`/`isStillEligible()` y el chequeo de solicitud dirigida en `RideRequestController::store()` descartan a cualquier conductor cuya `passenger_capacity` no alcance o que no tenga cajuela cuando se pidió una — tanto para "toda la flota"/"público" como para un conductor puntual. `Ride/Request.vue` filtra la lista visible con el mismo criterio y muestra los datos del vehículo de cada conductor (marca, modelo, color, placa, capacidad, cajuela).
- **Panel "Tu estado" en `Driver/Profile.vue`** (agregado por iniciativa propia, para que el conductor entienda las reglas nuevas sin adivinar): reemplaza el aviso único de antes por una lista de estados — datos del vehículo completos, disponible ahora, visible en el directorio público, insignia de verificado — cada uno con el detalle puntual de qué le falta si no lo cumple.
- **De paso, un gap encontrado y corregido**: `verified_badge` (si el plan del conductor "incluye" insignia) era un campo configurable que ningún código chequeaba de verdad — la insignia dependía solo de la aprobación del admin, sin importar el plan (quedó anotado sin tocar en el batch anterior; acá sí se corrigió). Ahora la insignia exige las dos cosas: aprobado por un admin Y el plan actual la incluye. Corregido en `RideRequestController::driverCardData()`, `DriverDirectoryController::index()`, y agregado como prop compartida `auth.hasVerifiedBadge` (usada en el menú de cuenta de `AuthenticatedLayout.vue`, con un enlace a "ver por qué" cuando falta).

### Fix, en el medio de este mismo batch: Marta no aparecía en el directorio público a pesar de haber pagado el plan Pro
El usuario reportó con capturas que Marta pagó el plan Pro, está cerca del cliente, y no aparecía en el directorio. Diagnóstico en vivo contra la base de datos: a Marta le faltaban TRES cosas independientes, no una — `is_public: false` (nunca activó la visibilidad, aparte de pagar el plan), `is_available: false` (no estaba conectada), y los datos nuevos del vehículo (`vehicle_color`/`passenger_capacity` en null, de antes de este batch). Las tres se explican ahora en el panel "Tu estado" de su perfil, en vez de quedar como un misterio.

- **`Admin/Subscriptions.vue`**: el usuario notó que la lista mostraba SIEMPRE "Conductor: X / Cliente: Y" para cada cuenta, a pesar de que cada cuenta es una cosa o la otra, nunca las dos (regla del proyecto desde el 30/07). Corregido para mostrar un solo badge según el rol real (`user.role`), y el selector de "activar plan" ahora solo ofrece planes del lado que corresponde a esa cuenta — con el mismo chequeo agregado también en el **servidor** (`Admin/SubscriptionController::store()`), no solo visualmente, para que no se pueda forzar por fuera del formulario.

### Tests
`tests/Feature/Ride/VehicleCapacityTest.php` (11 casos nuevos): validación obligatoria de los campos del vehículo, bloqueo/permiso de ponerse disponible según el perfil esté completo (y que desconectarse siempre funciona, perfil incompleto o no), `forPool()` filtrando por capacidad y por cajuela, `store()` rechazando una solicitud dirigida sin capacidad suficiente y aplicando el valor por defecto de 1 pasajero cuando no se manda, la insignia de verificado exigiendo aprobación Y plan, y el admin sin poder activar un plan de conductor a una cuenta sin perfil de conductor. Se ajustaron además varios tests preexistentes que posteaban a `driver.profile.update` sin los campos ahora obligatorios (`DriverVerificationTest`, `MultiFleetTest`, `DriverCoverageRangeTest`) y dos de `AdminSubscriptionTest` que activaban un plan de conductor a una cuenta sin perfil de conductor. Verificado con la suite completa (`php artisan test`, 274 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Tanda grande de observaciones: colores, revisión de documentos, vencimiento de suscripción, perfil completo en admin, bug de "Mi Flota" sin conductores, y rediseño de finalización + calificaciones
El usuario mandó una tanda larga de observaciones sobre pantallas ya existentes, todas atendidas en este mismo batch:

- **Color del vehículo como lista, no texto libre**: `Driver/Profile.vue` reemplaza el campo de texto por un `<select>` con los colores más comunes (Blanco, Negro, Gris, Plata, Rojo, Azul, Verde, Amarillo, Naranja, Café, Dorado, Beige, Vino, Otro) — si un perfil viejo ya tenía guardado un color fuera de esa lista, se agrega igual como opción para no perder el dato al abrir el formulario. Sin cambios de backend: sigue siendo un string simple.
- **Revisión de documentos con motivo de rechazo obligatorio**: nueva columna `driver_profiles.verification_rejection_reason`. `Admin\DriverVerificationController::reject()` ahora exige `reason` (validación `required`) y lo guarda; `approve()` lo limpia. `Driver/Profile.vue` muestra el motivo en rojo junto al estado de verificación, y también en el panel "Tu estado".
- **Bloqueo de resubida mientras está "en revisión"**: `DriverProfile::canUploadDocuments()` (nuevo) es `false` solo cuando `verification_status === 'pending'`. `DriverProfileController::update()` rechaza con un 422 cualquier intento de subir `license_photo`/`vehicle_photo` en ese estado; el formulario deshabilita los inputs de archivo y muestra un aviso. Vuelve a habilitarse solo si se rechaza (o si nunca se subió nada).
- **Admin/DriverVerifications.vue mejorada**: cada foto (licencia/vehículo) ahora abre un modal con la imagen completa sin recortar (mismo patrón que el modal de comprobantes de pago de Admin/Subscriptions.vue); un link "Ver perfil completo →" lleva al nuevo perfil de admin; y rechazar exige escribir el motivo antes de poder confirmar (botón deshabilitado sin texto).
- **Perfil completo de un usuario en el admin** (pedido explícito: "consultar el perfil completo tanto del conductor como del cliente... sin necesidad de navegar por diferentes pantallas"): nuevo `Admin\UserProfileController::show()` + pantalla `Admin/UserProfile.vue` en `/admin/usuarios/{user}` — identidad, perfil de conductor completo (vehículo, documentos, verificación con su motivo, plan y vencimiento) si tiene, flotas propias + plan de cliente si es cliente, y las últimas reseñas recibidas con su promedio. Enlazada desde Admin/Subscriptions.vue y Admin/DriverVerifications.vue.
- **Estado y vencimiento de la suscripción, visibles por fin**: `PlanLimits::forDriver()`/`forClient()` ahora también devuelven `subscription_status` y `expires_at` (ya existían en la tabla `subscriptions`, simplemente no se exponían). Se muestran en `Profile/Partials/SubscriptionSummary.vue` ("Mi suscripción", ambos lados) y en `Admin/Subscriptions.vue` (junto a cada badge de plan) y en el nuevo perfil completo de admin.
- **Fix del bug reportado ("Pedir Carrera no hace nada")**: el backend YA rechazaba con un mensaje claro cuando "Mi Flota" (o cualquier bolsa) no tenía ningún conductor elegible (`RideRequestController::store()`, mensaje en `driver_user_id`) — el problema real era que `Ride/Request.vue` nunca mostraba ese error en pantalla, así que el clic parecía no hacer nada. Se agregó el mensaje visible con un atajo para cambiar a "Público"/"Ambos" desde el mismo aviso.

### Rediseño del flujo de finalización: solo el conductor completa, se elimina la confirmación de pago, calificación obligatoria y en orden
Pedido explícito del usuario, con un catálogo de motivos provisto textualmente:

- **Solo el conductor completa la carrera**: `RideController::complete()` ya no acepta al cliente (antes cualquiera de las dos partes podía). El cliente ve "Esperando que el conductor marque la carrera como completada" mientras tanto.
- **Se elimina por completo el paso de "¿pagaste?"**: se borraron `RideController::markPaid()`, la ruta `rides.mark-paid`, el botón "Marcar como pagada" de `Ride/Show.vue`, y las columnas `client_marked_paid`/`driver_marked_paid`/`paid_at` de `rides` (migración de baja, con su `down()` simétrico) — nada de esto se usaba para otra cosa en el resto de la app.
- **Calificación obligatoria y en orden fijo**: al completarse, el siguiente paso es que el CLIENTE califique primero; el conductor recién puede calificar después. `ReviewController::store()` rechaza el intento del conductor con un mensaje claro ("todavía tenés que esperar a que el cliente califique primero") si todavía no existe la reseña del cliente para esa carrera — en el frontend, `Ride/Show.vue` directamente no muestra el formulario del conductor todavía, muestra el aviso de espera (usa el dato `theirReview` que ya traía el backend: para el conductor, es exactamente "la reseña que el cliente ya le hizo a él").
- **5 estrellas por defecto, motivo obligatorio si se baja**: `reviewForm.rating` arranca en 5 (antes 0); si el usuario la baja, aparece un `<select>` obligatorio con los motivos activos de su dirección (cliente→conductor o conductor→cliente) — sin elegir uno, el botón de enviar queda deshabilitado. El backend valida lo mismo (`rating_reason_id` con `required_if:rating,1,2,3,4`) y además que el motivo elegido sea de la dirección correcta y esté activo, para que no se pueda mandar un motivo ajeno manipulando el pedido.
- **Nuevo catálogo "Motivos de Calificación"** (`rating_reasons`): columnas `direction` (`client_to_driver`/`driver_to_client`), `text`, `is_active`, `sort_order`. Sembrado con los dos listados provistos textualmente por el usuario (15 motivos cliente→conductor, 13 conductor→cliente) directamente en la propia migración — mismo criterio que `subscription_plans`, así el catálogo existe siempre, incluso en una base nueva sin seeders. Nueva pantalla `Admin/RatingReasons.vue` (`/admin/motivos-calificacion`, agregada a la sub-nav de `AdminLayout.vue`) para agregar, editar el texto, y activar/desactivar cada motivo — separado por columna según la dirección.
- Nueva columna `reviews.rating_reason_id` (FK nullable a `rating_reasons`, `nullOnDelete()`).

### Tests
`tests/Feature/Security/DriverVerificationTest.php`: +3 casos (rechazo exige motivo, bloqueo de resubida en pending, resubida permitida tras rechazo). `tests/Feature/Admin/AdminUserProfileTest.php` (nuevo, 3 casos: acceso restringido a admin, perfil de conductor, perfil de cliente). `tests/Feature/Admin/RatingReasonTest.php` (nuevo, 4 casos: catálogo sembrado con 15+13, acceso restringido, crear motivo, desactivar motivo). `tests/Feature/Ride/RideRequestFlowTest.php`: reemplazado el test de pago manual por dos nuevos (solo el conductor completa, el cliente no puede). `tests/Feature/Review/ReviewFlowTest.php`: actualizado para el orden fijo y el motivo obligatorio (+3 casos: conductor califica después del cliente, conductor no puede calificar antes, motivo de la dirección equivocada rechazado). Verificado con la suite completa (`php artisan test`, 288 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Compartir un Expreso con otros clientes de ruta parecida (carpooling ligero)
El usuario planteó un problema real del transporte en Ecuador: a un conductor no le conviene hacer un Expreso porque va vacío a buscar a una sola persona por una sola carrera. Se evaluaron dos caminos — (A) una capa liviana de matching por cercanía sobre el Expreso tal como existe hoy, sin tocar el modelo de carrera; o (B) rehacer el Expreso para soportar de verdad "una carrera, varios pasajeros" (cupo de asientos, orden de recogida, reparto real del pago). El usuario eligió (A): mucho menos riesgo, reutiliza toda la infraestructura de carreras que ya existe, y resuelve el problema de fondo (bajar el costo real compartiendo ruta).

- **`express_routes`**: nuevas columnas `share_enabled` (el dueño decide, no es automático) y `max_companions` (cupo de acompañantes, además de él mismo — sin configurar, se asume 1).
- **Nueva tabla `express_route_companions`**: el pedido de un cliente distinto para sumarse a un Expreso ajeno — su propio origen/destino si difiere un poco, y estado `pending`/`accepted`/`rejected`/`left`. El dueño del Expreso aprueba o rechaza cada pedido, mismo criterio de "círculo de confianza" que ya rige el resto de la app.
- **`ExpressRoute::pricePerPerson()`**: el precio total pactado con el conductor NO cambia — se reparte entre el dueño y sus acompañantes aceptados. `hasRoomForCompanions()` combina `share_enabled` + el cupo configurado.
- **Nuevo `ExpressRouteCompanionController`**: `discover()` (el cliente busca, con su propio origen/destino, Expresos de OTROS clientes abiertos a compartir dentro de 2.5 km de cada punto — reutiliza `Haversine`, mismo servicio que ya usa el resto de la app, sin inventar nada geoespacial nuevo), `store()` (pedir sumarse), `accept()`/`reject()` (el dueño responde), `leave()` (el acompañante se baja). `ExpressRoutePolicy::view()` se extendió para que un acompañante (pendiente o aceptado) también pueda ver el detalle del Expreso.
- **`GenerateExpressRides`** (el motor diario de recurrencia): ahora también genera la carrera del día para cada acompañante ACEPTADO, con el mismo conductor asignado, y el precio de cada carrera generada (la del dueño incluida) pasa a ser `pricePerPerson()` en vez del total — así el conductor efectivamente cobra el total repartido en varias carreras, no el total varias veces.
- **Frontend**: `Express/Index.vue` — checkbox "Abrir este Expreso a que otras personas de ruta parecida se sumen" + cupo, al publicar. `Express/Show.vue` — el dueño ve los pedidos pendientes (aceptar/rechazar) y los acompañantes ya sumados con el precio por persona; un acompañante ve el estado de su propio pedido y un botón para bajarse. Nueva pantalla `Express/Discover.vue` — buscador (origen/destino con Google Places) + lista de Expresos compatibles cerca, con "Pedir unirme".

### Tests
`tests/Feature/Express/ExpressRouteSharingTest.php` (nuevo, 12 casos): publicar con `share_enabled`, `discover()` encuentra rutas cercanas y excluye las lejanas/no-compartibles, pedir sumarse (y que el dueño no pueda pedirse a sí mismo, ni se pueda si ya no hay cupo), aceptar/rechazar por el dueño (un desconocido no puede), bajarse como acompañante, el cálculo de `pricePerPerson()`, y que `GenerateExpressRides` genere también la carrera del acompañante con el precio repartido. Verificado con la suite completa (`php artisan test`, 300 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Módulo de publicidad y promociones (monetización): banners tipo slider
El usuario pidió, como primera pieza de un ecosistema de monetización más amplio (junto con cupones, VAN/turismo y WhatsApp), un espacio publicitario vendible a negocios aliados (talleres, aseguradoras, lavadoras, restaurantes), administrable por completo desde el admin.

- **`ad_banners`**: imagen, título, descripción corta, texto del botón, URL de destino, `is_active`, `sort_order`, y `starts_at`/`ends_at` para programar la vigencia. `AdBanner::scopeVisible()` filtra por activo + dentro de fecha, sin tocar los que ya vencieron (el admin los conserva para reactivarlos después, no hace falta recrearlos).
- **`Admin\AdBannerController`**: mismo patrón que `RatingReasonController`/`PlanController` (index/store/update, formulario inline) — `update()` acepta reemplazar la imagen o no (si no se manda una nueva, se conserva la actual), y borra el archivo viejo del disco `public` al reemplazar o eliminar. Nueva sección "Banners" en la sub-nav de `AdminLayout.vue`.
- **Ubicación elegida, a propósito**: el Inicio (`Dashboard.vue`), justo debajo del saludo — es la pantalla más visitada, y ahí un slider no compite con ningún flujo de trabajo (pedir carrera, ver solicitudes, etc.), a diferencia de meterlo en el layout compartido de toda la app. `DashboardController::index()` expone `adBanners` (ya filtrados por `visible()`) para ambos roles.
- **`Components/AdBannerSlider.vue`** (nuevo, sin librería externa): rotación automática cada 6 segundos, se pausa al pasar el mouse, puntitos de navegación manual, cada slide es un link al `button_url` (si tiene) con `target="_blank" rel="noopener sponsored"`. Si no hay banners activos, no renderiza nada (no deja un hueco vacío).

### Tests
`tests/Feature/Admin/AdBannerTest.php` (nuevo, 6 casos): acceso restringido a admin, crear con imagen (y que falle sin ella), editar sin reemplazar la imagen, eliminar, y que el Inicio solo muestre los banners activos y vigentes por fecha (no los vencidos, no programados a futuro, no inactivos). Verificado con la suite completa (`php artisan test`, 306 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Centro de cupones y beneficios (monetización): promociones independientes por rol
Segunda pieza del ecosistema de monetización que pidió el usuario, con el mismo criterio de "cada cuenta es cliente O conductor" que ya rige el resto de la app (sección 3.1): las promociones de un lado son completamente independientes de las del otro.

- **`coupons`**: `audience` (`client`/`driver`), imagen, título, descripción, botón, enlace, `is_active`, `sort_order`, y una única `expires_at` (a diferencia de los banners, acá el usuario pidió literalmente "fecha de vigencia" en singular — sin fecha de inicio, solo vencimiento). `Coupon::scopeVisible()` filtra por activo + no vencido.
- **`Admin\CouponController`**: mismo patrón CRUD que el resto (`RatingReasonController`, `AdBannerController`), con la lista agrupada por audiencia en la pantalla — dos secciones separadas ("Cupones de Cliente" / "Cupones de Conductor"), cada una con su propio botón "Nuevo cupón". Nueva sección "Cupones" en la sub-nav del admin.
- **`CouponController`** (lado usuario, no-admin): resuelve la audiencia del rol REAL de la cuenta (`$user->isDriver()`), nunca de un selector manual — coherente con que una cuenta no puede ser las dos cosas. Nueva pantalla `Coupons/Index.vue` (grilla de tarjetas, no slider — a diferencia de los banners, acá el usuario entra a buscar un beneficio puntual, no es contenido de paso), enlazada desde el menú de accesos rápidos ("Cupones y beneficios", `AuthenticatedLayout.vue`) y la ruta `/cupones`.

### Tests
`tests/Feature/Admin/CouponTest.php` (nuevo, 5 casos): acceso restringido a admin, crear un cupón para una audiencia, que un cliente solo vea cupones de cliente, que un conductor solo vea los de conductor, y que uno vencido no se muestre. Verificado con la suite completa (`php artisan test`, 311 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Nuevo servicio para conductores tipo VAN/buseta/microbús/turístico
Tercera pieza del pedido grande del usuario: un módulo nuevo para viajes programados puntuales (no recurrentes como un Expreso), con reserva de asientos a precio fijo — pensado como "plan premium exclusivo para conductores".

- **`van_trips_enabled`** en `subscription_plans` (nuevo feature flag, mismo criterio que `verified_badge`): arranca habilitado en los planes de conductor Pro e Institucional, editable después desde `/admin/planes` (nuevo checkbox "Viajes tipo VAN/turismo" junto a los otros tres). Expuesto en `PlanLimits::forDriver()`.
- **`van_trips`**: ciudad de origen/destino (reutiliza el catálogo `cities` de "zonas del Ecuador", no uno nuevo), fecha, hora de salida, cupo de asientos, precio por asiento, descripción, servicios incluidos (lista libre en JSON), equipaje permitido, y estado (`open`/`cancelled`). **`van_trip_photos`**: fotos del vehículo, separadas de las del perfil de conductor porque un mismo conductor puede publicar con vehículos distintos según la ocasión. **`van_trip_reservations`**: reserva directa de N asientos por un cliente, sin negociación — precio fijo por asiento.
- **`VanTripController`**: `store()` gatea por perfil de conductor activo Y por `van_trips_enabled` del plan vigente (ambos chequeos, igual que el resto de la app nunca confía solo en lo que muestra el frontend). `browse()` es el descubrimiento del lado cliente — filtra por ciudad/fecha y excluye viajes sin cupo real (no solo por `status`, calcula asientos disponibles de verdad).
- **`VanTripReservationController::store()`**: con `lockForUpdate()` (mismo patrón que `RideRequestController::accept()`) para que dos reservas simultáneas no se pasen del cupo real; un cliente no puede reservar dos veces en el mismo viaje ni el propio conductor reservarse a sí mismo.
- **Frontend**: `VanTrips/Index.vue` (mis viajes publicados + formulario de publicación, solo si el plan lo incluye — si no, mensaje explicando qué falta), `VanTrips/Browse.vue` (buscador por ciudad/fecha para el cliente), `VanTrips/Show.vue` (detalle dual: el dueño administra y cancela, ve quién reservó; un cliente reserva o cancela su propia reserva). Enlazado desde el menú de accesos rápidos ("Mis viajes VAN" / "Viajes VAN / turismo").

### Tests
`tests/Feature/VanTrips/VanTripFlowTest.php` (nuevo, 10 casos): publicar con el plan correcto (y que falle sin el feature del plan, y que un cliente sin perfil de conductor no pueda), que `browse()` excluya viajes llenos/cancelados, reservar asientos (y que no se pueda reservar de más, ni dos veces, ni el propio conductor su viaje), cancelar una reserva libera el cupo, y cancelar el viaje completo (solo el dueño). Verificado con la suite completa (`php artisan test`, 321 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Integración con WhatsApp: ventana de 24h para avisos de carrera sin costo de plantilla
Última pieza del pedido grande del usuario: avisar a los conductores por WhatsApp cuando llega una carrera nueva, sin pagar plantillas HSM en cada mensaje — usando la ventana gratuita de 24 horas que WhatsApp abre cuando el USUARIO le escribe primero al número oficial.

- **`whatsapp_sessions`**: cada fila es una apertura de la ventana (queda como historial). **Decisión de arquitectura evaluada explícitamente**: no se guarda un campo `status` separado ni hace falta un job programado que la "marque expirada" — el estado (`active`/`expiring_soon`/`expired`) se calcula siempre comparando `expires_at` contra la hora actual en el momento de usarlo (mandar un mensaje, o mostrarlo en pantalla). Guardar y mantener sincronizado un status aparte sería una capa de más sin ningún beneficio real, y además arriesgaría quedar desactualizado si el job no corrió todavía.
- **`WhatsAppWebhookController`** (nuevo, en `routes/api.php` — sin CSRF/sesión, porque quien llama es Meta, no un navegador): `verify()` responde el handshake de Meta al configurar el webhook; `receive()` procesa mensajes entrantes, busca el usuario por teléfono (`+<número>`) y le abre una ventana nueva de 24h. Un número sin cuenta asociada, o un payload de solo confirmaciones de entrega (sin `messages`), no rompen nada — se ignoran en silencio.
- **`WhatsAppFreeformSender`** (nuevo, hermano de `WhatsAppVerificationSender` pero con `type: text` en vez de plantilla): `sendNewRideAlert()` arma el mensaje (nombre del cliente, dirección de recogida, distancia con `Haversine`, valor aproximado, link a `/carreras`) y no hace nada si el conductor no tiene la ventana abierta — mismo criterio "best-effort" que la verificación por WhatsApp, nunca bloquea el flujo principal. Enganchado en los DOS puntos donde hoy se le avisa a un conductor de una carrera nueva: `RideRequestController::notifyDriversOfNewRequest()` (al crearse la solicitud) y `RideDispatchAdvancer` (cuando el despacho secuencial le pasa el turno al siguiente candidato).
- **Nuevas variables de entorno** (`.env.example`): `WHATSAPP_BUSINESS_NUMBER` (el número oficial en dígitos, para armar el link `wa.me/<numero>?text=Hola`) y `WHATSAPP_WEBHOOK_VERIFY_TOKEN` (secreto que también hay que pegar en Meta for Developers al configurar el webhook, apuntando a `/api/webhooks/whatsapp`) — **el usuario tiene que completar estas dos, más las `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID` que ya existían de la verificación por teléfono**, para que esta integración funcione de verdad.
- **`Driver/Profile.vue`**: nuevo panel "Avisos de carrera nueva por WhatsApp" (solo si `WHATSAPP_BUSINESS_NUMBER` está configurado) — muestra el estado de la ventana vigente con el tiempo restante, y un link directo `wa.me` para conectar o renovar escribiendo "Hola" sin que el conductor tenga que escribir nada a mano.

### Tests
`tests/Feature/WhatsApp/WhatsAppSessionTest.php` (nuevo, 7 casos): handshake de verificación del webhook (token correcto/incorrecto), un mensaje entrante de un teléfono conocido abre la ventana, uno de un teléfono desconocido se ignora sin error, un payload de solo confirmaciones no rompe nada, y el cálculo de estado (activa/próxima a vencer/expirada) contra distintas `expires_at`. `tests/Feature/WhatsApp/WhatsAppRideAlertTest.php` (nuevo, 3 casos, con `Http::fake()` igual que `PhoneVerificationTest`): se manda el aviso cuando el conductor tiene ventana activa, no se manda sin ventana, no se manda con una ventana ya vencida. Verificado con la suite completa (`php artisan test`, 331 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: un conductor "disponible" seguía recibiendo carreras hasta 2 minutos después de desconectarse de verdad
El usuario preguntó qué pasa si un conductor pasa la app a segundo plano, cierra el navegador o pierde señal — investigando el código se confirmó un hueco real: `DriverAvailabilityToggle.vue` manda su ping de ubicación cada ~15s mientras la pestaña está activa, pero al perder esos pings `is_available` se queda en `true` hasta que corre `SweepStaleDriverAvailability` (cada 2 minutos). Durante esa ventana, el conductor seguía apareciendo disponible y **podía recibir/aceptar una solicitud dirigida igual**, porque el filtro de candidatos solo miraba `is_available`, nunca la frescura del último ping.

- **`DriverProfile::isStale()`** (nuevo) + **`DriverProfile::STALE_AFTER_MINUTES`** (constante, 2 minutos): única fuente de verdad para "¿este conductor sigue de verdad ahí?" — `location_updated_at` nulo o más viejo que el umbral. `SweepStaleDriverAvailability` ahora referencia esta misma constante (antes tenía su propia copia) para que nunca puedan desincronizarse.
- **`RideDispatchCandidates::forPool()`** e **`isStillEligible()`**: excluyen a un conductor stale, igual que ya excluían a uno suspendido o fuera de zona — así ni siquiera entra a la bolsa de candidatos ni recibe la oferta cuando la cascada le pasa el turno, sin esperar al barrido de 2 minutos.
- **`RideRequestController::store()`**: una solicitud DIRIGIDA a un conductor puntual ahora también se rechaza si está stale ("Ese conductor parece haberse desconectado — probá con otro o con toda tu flota"), el mismo criterio que ya se validaba para zona de cobertura o capacidad de pasajeros.
- **Consistencia visual**: `RideRequestController::driverCardData()` (lista de "¿A quién se la pedís?"), `DriverDirectoryController` (directorio público) y `DashboardController::fleetDriversFor()` (widget "Mi flota" del Inicio) ahora muestran a un conductor stale como desconectado/no disponible, no como el punto verde de "disponible" — para que el cliente no vea un estado que ya no es real ni intente pedirle algo dirigido.
- **`DriverProfileFactory`**: se agregó `location_updated_at => now()` por defecto (en producción `is_available` y `location_updated_at` siempre se escriben juntos, `DriverLocationController::update()`) — sin esto, cualquier conductor de test hubiera nacido "stale" y roto decenas de tests de despacho preexistentes.

### Tests
`tests/Feature/Ride/StaleDriverAvailabilityTest.php` (nuevo, 9 casos): `isStale()` con ping viejo/reciente/nunca, `forPool()` e `isStillEligible()` excluyendo a un conductor stale, `store()` rechazando una solicitud dirigida a uno stale, y que la pantalla de pedir carrera, el directorio público y el widget "Mi flota" del Inicio lo muestren como desconectado. Verificado con la suite completa (`php artisan test`, 340 tests OK) y `./vendor/bin/pint --dirty` (sin cambios de frontend en este fix, no hizo falta `npm run build`).

### Fix: un conductor podía "pedir una carrera" (acción exclusiva de cliente)
El usuario pidió ocultar el botón "Pedir una carrera" para un conductor. Al investigar se encontró que el botón aparecía sin ninguna guarda de rol en tres pantallas (`Ride/Index.vue`, `Directory/Index.vue`) y que el propio backend (`RideRequestController::create()`/`store()`) tampoco bloqueaba nada — un conductor que entrara por URL directa no solo lograba pedir una carrera, sino que además se le provisionaba una flota propia en silencio (`resolveFleet()` la crea sola si no existe), rompiendo la regla "cada cuenta es cliente o conductor, nunca las dos" (sección 3.1) por abajo del frontend.

- **Frontend**: el botón ahora está detrás de `v-if="isClient"` (`usePage().props.auth.isClient`) en `Ride/Index.vue` y `Directory/Index.vue`. `Fleet/Show.vue` no necesitó cambios — esa pantalla ya está protegida por policy (`$this->authorize('view', $fleet)`), y un conductor nunca es dueño de una flota, así que nunca llega ahí.
- **Backend**: `RideRequestController::create()`/`store()` ahora rechazan a un conductor con el mismo mensaje y patrón que ya usa `FleetController` (`SINGLE_ROLE_MESSAGE`) — `create()` redirige al inicio, `store()` tira `ValidationException`. Cierra el hueco de verdad, no solo lo esconde visualmente.

### Fix de infraestructura de tests: `.env` real filtrándose a la suite
De paso, al correr la suite completa apareció un test caído (`RegistrationTest`) sin relación con este cambio — el `.env` real ya tiene cargadas las credenciales de WhatsApp (el usuario las completó), y `phpunit.xml` aislaba la base de datos/caché/sesión de lo real pero no esas variables, así que los tests empezaron a leer el token de WhatsApp de verdad y a activar el camino "configurado" donde varios tests dan por hecho el camino "no configurado". Se agregaron overrides en blanco (`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_BUSINESS_NUMBER`, `WHATSAPP_WEBHOOK_VERIFY_TOKEN`, `GOOGLE_CLIENT_ID/SECRET`) en `phpunit.xml`, mismo criterio que ya se usaba para `DB_CONNECTION` — la suite nunca debe depender de qué credenciales reales haya o no en `.env`.

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php`: +2 casos (un conductor no puede abrir la pantalla de pedir carrera ni tampoco enviar la solicitud, y no se le crea una flota por el intento). Verificado con la suite completa (`php artisan test`, 342 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Aviso por WhatsApp cuando un conductor se desconecta (voluntaria o involuntariamente)
El usuario pidió avisarle al conductor por WhatsApp cuando se desconecta — para animarlo a volver — cubriendo dos casos: que cierre sesión/se ponga "no disponible" a propósito, o que se le corte la ubicación sin que él haga nada. Pidió explícitamente evaluar si conviene un Job o un trigger de MySQL para esto.

- **Decisión de arquitectura (evaluada, no asumida)**: se descartó el trigger de MySQL — un trigger de base de datos no puede hacer una llamada HTTP a la API de WhatsApp, solo puede reaccionar dentro de la propia base de datos (para eso serviría, como mucho, para escribir en una cola propia que después otra cosa procese — más complejo que usar la cola que Laravel ya tiene lista). Se implementó como **Job encolado** (`NotifyDriverDisconnectedByWhatsApp`, `QUEUE_CONNECTION=database` ya configurado): así ni el clic del conductor en "Activarme"/cerrar sesión, ni el barrido de stale (que puede procesar varios conductores en una sola corrida), esperan a que termine la llamada a Meta.
- **Los dos disparadores que pidió el usuario, sin duplicar lógica de armado del mensaje**: `DriverLocationController::update()` (el conductor se pone "no disponible" a propósito) y `Auth\AuthenticatedSessionController::destroy()` (cierra sesión estando disponible) cubren el caso VOLUNTARIO; `SweepStaleDriverAvailability` (el barrido cada 2 minutos) cubre el caso INVOLUNTARIO — los tres solo disparan el job cuando `is_available` pasa de `true` a `false` de verdad (no en cada ping rutinario mientras sigue disponible). Un conductor SUSPENDIDO por un admin (`Admin\DriverController::suspend()`) no dispara el aviso a propósito — no tendría sentido "animarlo a volver" a alguien al que la plataforma acaba de bloquear.
- **`WhatsAppFreeformSender::sendDisconnectedAlert()`** (nuevo, mismo patrón que `sendNewRideAlert()`): el chequeo de la ventana de 24h (`hasActiveWhatsAppSession()`) se hace en el momento real de mandar el mensaje, dentro del job — no al encolarlo, porque para cuando el job corra la ventana pudo haber cambiado.

### Tests
`tests/Feature/WhatsApp/WhatsAppDisconnectAlertTest.php` (nuevo, 8 casos, con `Queue::fake()`/`Http::fake()`): apagar disponibilidad encola el job (y activarla, o un ping de rutina estando ya disponible, NO lo encola), cerrar sesión estando disponible lo encola (y estando ya desconectado no), el barrido de stale lo encola por cada conductor, y el job manda el WhatsApp solo con ventana de 24h activa. Verificado con la suite completa (`php artisan test`, 350 tests OK) y `./vendor/bin/pint --dirty` (sin cambios de frontend, no hizo falta `npm run build`).

### Fix: activarse desde Inicio no ofrecía conectar la ventana de WhatsApp
El usuario reportó que al activarse ("Disponible") desde el banner de Inicio no pasaba nada — esperaba que se le pidiera abrir WhatsApp para activar la ventana de 24h. Faltaba ese enganche puntual: `Driver/Profile.vue` ya mostraba el estado de la ventana, pero el banner "Activarme"/"Desconectarme" de `Dashboard.vue` (que es por donde el conductor arranca su turno de verdad) no sabía nada de WhatsApp.

- **`DashboardController::index()`**: ahora expone `whatsappSession` (estado + vencimiento de la ventana vigente, igual que ya hacía `DriverProfileController::edit()`) y `whatsappBusinessNumber`, solo para el lado conductor.
- **`Dashboard.vue`**: al activarse (`isAvailableNow` pasa a `true`) sin ventana abierta, aparece un banner chico debajo de "Activarme" invitando a conectar WhatsApp (link `wa.me` con "Hola" precargado) — se puede cerrar sin conectar (no es obligatorio, no bloquea nada) y vuelve a aparecer la próxima vez que se active mientras siga sin ventana. También aparece si la pantalla carga con el conductor ya disponible (no soloal tocar el switch).
- **Aclaración importante, ya cumplida de antes**: un fallo al mandar el WhatsApp de desconexión NUNCA bloquea poner/sacar la disponibilidad — el toggle guarda `is_available` y responde al navegador antes de que el Job de WhatsApp corra (está encolado, no en la misma petición). Se le agregó además un `try/catch` al Job (`NotifyDriverDisconnectedByWhatsApp`) para que cualquier error quede solo en el log, sin reintentos ni efectos sobre el resto del sistema.

### Tests
`tests/Feature/DashboardTest.php`: +3 casos (un conductor sin ventana abierta recibe `null`, con ventana activa recibe su estado, y un cliente nunca recibe este dato). Verificado con la suite completa (`php artisan test`, 353 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Mejoras al flujo de conexión de WhatsApp: apertura automática, mensaje profesional y confirmación del bot
Tras probar el flujo completo, el usuario pidió tres ajustes: que activarse mande derecho a WhatsApp (no solo un aviso para tocar aparte), un mensaje inicial más profesional, y que el "bot" responda confirmando la conexión.

- **`Utils/whatsapp.js`** (nuevo, compartido entre `Dashboard.vue` y `Driver/Profile.vue`): arma el link `wa.me` con el mensaje "Buen día, inicio mi turno en Arka01 🚗" en vez de un simple "Hola".
- **Apertura automática al activarse**: `Dashboard.vue` ahora abre la pestaña de WhatsApp (`window.open`) apenas el conductor toca el switch, en el mismo manejador del evento del click — a propósito, no en un `watch()` — los navegadores solo dejan abrir una pestaña nueva sin bloquearla como pop-up si el `window.open` corre sincrónicamente dentro de la cadena del gesto del usuario. El banner que ya existía queda como respaldo visible por si el navegador la bloqueó igual.
- **`SendWhatsAppWindowConfirmation`** (nuevo Job encolado) + **`WhatsAppFreeformSender::sendWindowConfirmation()`**: apenas `WhatsAppWebhookController::receive()` abre la ventana de 24h, el "bot" le contesta "✅ ¡Listo, {nombre}! Ya quedaste conectado y activo para recibir avisos de carreras nuevas... ¡a generar ingresos! 🚀" — confirmándole al conductor que el enlace quedó bien hecho. Encolado (no sincrónico) para que el webhook le siga respondiendo rápido a Meta.

### Diagnóstico: "pedí una carrera pero no le llegó el WhatsApp al conductor"
Revisando `storage/logs/laravel-2026-08-06.log` se confirmó que el mecanismo en sí funciona: la ventana de 24h se abrió correctamente para **`user_id: 10`** (`"WhatsApp: ventana de 24h abierta." {"user_id":10}`). Pero las carreras que se pidieron en esa misma sesión de pruebas fueron dirigidas a **`driver_user_id: 8`** — una cuenta distinta, que nunca le escribió "Hola" al número oficial y por lo tanto no tiene ninguna ventana abierta. No es un bug: el conductor de la prueba (usuario 8) y el teléfono que efectivamente conectó WhatsApp (usuario 10) son dos cuentas distintas — para probar el aviso de carrera nueva, hay que pedirle la carrera al MISMO conductor cuyo número de teléfono mandó el "Hola".

### Tests
`tests/Feature/WhatsApp/WhatsAppSessionTest.php`: +2 casos (el webhook encola la confirmación al abrir la ventana, y la confirmación manda el mensaje de verdad con `Http::fake()`). Verificado con la suite completa (`php artisan test`, 355 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Fix: calificación independiente (se revierte el orden fijo cliente→conductor) + alarma de pendientes
El usuario probó el flujo de calificación secuencial (implementado en un batch anterior) y pidió revertirlo: cliente y conductor califican cada uno por su cuenta, sin esperarse — a cambio, pidió un recordatorio visible mientras a alguno de los dos le falte calificar.

- **`ReviewController::store()`**: se quitó el chequeo que exigía que el cliente ya hubiera calificado antes de dejar calificar al conductor — ahora cualquiera de las dos partes puede calificar en cualquier momento después de completada la carrera, de forma independiente.
- **`Ride/Show.vue`**: se quitó el mensaje "esperá a que el cliente califique primero" — el formulario de calificación del conductor aparece siempre que la carrera esté completada y todavía no haya calificado.
- **Alarma de pendientes** (pedido explícito): `RideController::index()` ahora marca cada carrera del historial con `needs_my_review` (completada y sin mi reseña todavía, calculado igual para cliente y conductor). `Ride/Index.vue` muestra un banner de advertencia arriba de la pantalla mientras haya alguna ("⚠️ Tenés N carrera(s) completada(s) sin calificar todavía", con link directo a la primera) y una etiqueta "Sin calificar" junto a cada ítem del historial que corresponda.

### Diagnóstico: "la solicitud llegó a la app pero no mandó el WhatsApp"
Se repitió el mismo patrón que la vez anterior — confirmado con `storage/logs/laravel-2026-08-06.log` y una consulta directa: la ventana de WhatsApp está abierta para **Andrea Osorio** (user_id 10, `hasActiveWhatsAppSession() = true`), pero la carrera de la captura se le pidió a **Luis Manejo** (user_id 8, teléfono `0990000007` — un número de prueba sin formato E.164, y sin ninguna ventana de WhatsApp abierta). No es un bug del código: son dos cuentas de prueba distintas. Para probar el aviso de carrera nueva de punta a punta, hay que pedirle la carrera al mismo conductor cuyo celular mandó el mensaje inicial a WhatsApp (en este caso, Andrea).

### Tests
`tests/Feature/Review/ReviewFlowTest.php`: reemplazado el test de "conductor espera al cliente" por uno que confirma que puede calificar ANTES que el cliente; +2 casos nuevos para `needs_my_review` (marca una carrera sin calificar, no marca una ya calificada). Verificado con la suite completa (`php artisan test`, 356 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### WhatsApp: validar el número contra el perfil, y mejoras al aviso de carrera nueva
El usuario probó el flujo completo (screenshot con el aviso de carrera nueva llegando bien) y pidió tres ajustes más.

- **Validar el número contra lo que el conductor declaró en su perfil**: antes, el webhook solo abría la ventana si el número que escribía coincidía EXACTO con `users.phone` — si no coincidía, se descartaba en silencio sin avisarle a nadie (el usuario tuvo que corregir el teléfono a mano en la base para que funcionara). Ahora el link "Conectar WhatsApp" (`Utils/whatsapp.js`) incluye una referencia propia en el mensaje pre-cargado (`"...Arka01 🚗 (ref:ID)"`), así el webhook siempre sabe quién intentó conectar, incluso si el número no matchea:
  - Si el número coincide con el declarado → se abre la ventana normal (sin cambios).
  - Si el conductor **todavía no tiene ningún teléfono declarado** → este mensaje sirve como prueba de que el número es suyo, y se completa solo (`users.phone`).
  - Si **ya tiene uno declarado y es distinto** → NO se abre la ventana a su nombre (los avisos siempre van al número del perfil, abrirla igual no serviría de nada) — se le avisa por WhatsApp, al número que acaba de escribir, que no coincide con el de su perfil (`WhatsAppFreeformSender::sendPhoneMismatchNotice()`, nuevo Job `SendWhatsAppPhoneMismatchNotice`).
- **Mensaje de carrera nueva con el tiempo para responder**: `sendNewRideAlert()` ahora incluye "⏱ Tenés N segundos para aceptar antes de que pase al siguiente conductor" — calculado en el momento real de mandar el mensaje contra `current_offer_expires_at`, y solo cuando aplica (una solicitud DIRIGIDA no tiene vencimiento). También se puso el link en su propia línea para que WhatsApp lo detecte mejor como enlace tocable.
- **Aviso cuando se le acaba el tiempo**: nuevo `WhatsAppFreeformSender::sendOfferExpiredNotice()`, enganchado en `RideDispatchAdvancer::advanceOrExpire()` — el conductor que tenía el turno y no respondió (rechazó, o se le acabaron los 30 segundos) se entera por WhatsApp de que la carrera ya pasó a otro, no solo por la app si la tenía cerrada.

### Tests
`tests/Feature/WhatsApp/WhatsAppSessionTest.php`: +4 casos (referencia completa un teléfono faltante y abre la ventana; referencia con teléfono distinto NO abre la ventana y avisa; referencia a un usuario inexistente no rompe nada; el aviso de número distinto se manda al número que escribió). `tests/Feature/WhatsApp/WhatsAppRideAlertTest.php`: +3 casos (el mensaje de "toda la flota" incluye el tiempo restante, uno dirigido no lo menciona, y el candidato anterior recibe el aviso de "se te acabó el tiempo" al rechazar). Verificado con la suite completa (`php artisan test`, 363 tests OK), `./vendor/bin/pint --dirty` y `npm run build`.

### Un conductor con WhatsApp abierto ya no se desconecta solo por tener la app en segundo plano
Caso real reportado por el usuario: el conductor Luis se veía "desconectado" para los clientes y no recibía avisos, aunque seguía con el celular a mano — la app en segundo plano deja de mandar el ping de GPS, y a los 2 minutos el barrido (`SweepStaleDriverAvailability`) lo apagaba de verdad, sin considerar que WhatsApp es un canal aparte que sigue funcionando igual.

- **`DriverProfile::isReachable(bool $hasActiveWhatsAppSession)`** (nuevo): un conductor sin ping reciente (`isStale()`) ya no se trata como desconectado si todavía tiene la ventana de WhatsApp de 24h abierta — sigue siendo alcanzable por ese canal. Reemplaza el chequeo `! isStale()` en los 6 lugares que lo usaban: `RideDispatchCandidates` (bolsa de candidatos y elegibilidad durante la cascada), `SweepStaleDriverAvailability` (ya no lo apaga), `DashboardController`/`RideRequestController` (estado "disponible"/"offline" que ve el cliente) y `DriverDirectoryController` (directorio público).
- El único caso en que SÍ se lo desconecta de verdad ahora es sin ping reciente **y** sin ventana de WhatsApp abierta — ahí no hay ningún canal para alcanzarlo, es una desconexión real.
- **Mensaje de carrera nueva**: se le agregó un recordatorio al final — "¿No querés más solicitudes? Desconectate desde la app para dejar de recibirlas" — porque ahora dejar la app en segundo plano ya no alcanza para dejar de recibir avisos; tiene que desconectarse a propósito con el switch.

### Tests
`tests/Feature/Ride/StaleDriverAvailabilityTest.php`: +7 casos (reachable con sesión activa, forPool lo incluye, isStillEligible lo acepta, store permite la solicitud dirigida, y las 3 pantallas — pedir carrera, directorio público, inicio — lo muestran disponible). `tests/Feature/Console/SweepStaleDriverAvailabilityTest.php`: +1 caso (no lo desconecta con la ventana abierta). `tests/Feature/WhatsApp/WhatsAppRideAlertTest.php`: +1 caso (el mensaje incluye el recordatorio). Verificado con la suite completa (372 tests OK) y `./vendor/bin/pint --dirty` limpio; sin cambios de frontend en esta tanda.

### Preparación para el despliegue (menos la pasarela de pagos, queda para la próxima)
El usuario pidió cerrar los gaps identificados en la revisión de "qué falta antes de desplegar", salvo la pasarela de pagos real (reconocida como pendiente aparte).

- **Monitoreo de errores (Sentry)**: se instaló `sentry/sentry-laravel` y se conectó en `app/Exceptions/Handler.php::register()`. Apagado por completo mientras `SENTRY_LARAVEL_DSN` esté vacío en `.env` (mismo criterio que WhatsApp/Google: una integración opcional no bloquea nada sin configurar) — completar con el DSN de un proyecto gratis en sentry.io para activarlo.
- **Páginas de Términos y Privacidad**: nuevas `/terminos` y `/privacidad` (`resources/js/Pages/Legal/`), públicas, enlazadas desde el login/registro y desde la pantalla de bienvenida. Contenido base en español cubriendo qué es Arka01, ubicación en vivo, suscripciones manuales, verificación de conductores y datos que se recolectan — con una nota aclarando que conviene que un abogado la revise contra la normativa ecuatoriana antes de un lanzamiento con usuarios reales (no reemplaza asesoría legal real).
- **Supervisor para producción**: `deploy/supervisor-queue-worker.conf`, `deploy/supervisor-reverb.conf` y `deploy/README.md` — plantillas listas para copiar a `/etc/supervisor/conf.d/` en el servidor real, para que el queue worker y Reverb se reinicien solos si se caen (hoy en Laragon corren a mano en una terminal). Incluye también la línea de cron que necesita el scheduler.

### El conductor puede corregir su número de WhatsApp desde su perfil
Pedido explícito del usuario, ligado al caso de Luis: hacía falta poder cambiar el número declarado sin tocar la base a mano, y detectar cuando dos cuentas distintas intentan usar el mismo número.

- **`Driver/Profile.vue`**: nuevo campo (código de país + número) para corregir el WhatsApp declarado — en blanco no toca lo que ya había. Muestra el número actual y si está verificado o no.
- **`DriverProfileController::update()`**: valida que el número nuevo no esté en uso por otra cuenta (mensaje explícito: "ya está registrado por otra cuenta de Arka01"); si cambia, resetea `phone_verified_at` y dispara la verificación por WhatsApp de nuevo (mismo mecanismo que el registro) — o queda auto-verificado si esa integración no está configurada.
- **`WhatsAppWebhookController::receive()`**: mismo problema visto del otro lado — si un mensaje entrante trae la referencia `(ref:ID)` de una cuenta DISTINTA a la dueña real del número que escribe, ya no se abre la ventana en nombre de nadie: se le avisa a quien escribió que ese número ya está conectado a otra cuenta (`WhatsAppFreeformSender::sendNumberAlreadyRegisteredNotice()`, sin revelar de quién es, por privacidad).

### Tests
`tests/Feature/Driver/DriverProfilePhoneUpdateTest.php` (nuevo, 5 casos), `tests/Feature/WhatsApp/WhatsAppSessionTest.php` (+2 casos: número ya en uso rechazado, dueño real sigue conectando normal), `tests/LegalPagesTest.php` (nuevo, 2 casos). Suite completa: 381 tests OK, Pint limpio, build limpio.

### El perfil público mostraba "Cliente" y "Conductor" a la vez, y el conductor veía un módulo para publicar Expresos
Caso real reportado por el usuario con captura de pantalla: el perfil público de Luis Manejo (conductor) mostraba las insignias "Cliente" Y "Conductor" al mismo tiempo, y desde la pantalla de conductor "Expresos disponibles" había un link "Publicar el mío" — ambas cosas violan la regla de que cada cuenta es cliente O conductor, nunca las dos, y que un conductor no solicita servicios (los pide un cliente).

- **Causa raíz**: `DriverDirectoryController::index()` (el directorio público, pantalla de cliente) le creaba una `Fleet` propia sola a CUALQUIERA que pisara esa URL — sin chequear el rol — para poder armar el botón "Invitar". Un conductor que hubiera entrado ahí alguna vez (por curiosidad, un link viejo, etc.) terminaba con una flota fantasma vacía. `PublicProfileController::show()` calculaba `isClient` mirando "¿tiene alguna fila en `fleets`?" en vez de usar `User::isClient()` — por eso esa flota fantasma le encendía la insignia de "Cliente" para siempre, aunque nunca la hubiera usado.
- **`DriverDirectoryController::index()`** y **`ExpressRouteController::index()`/`store()`**: ahora rechazan a un conductor con el mismo guard ya usado en `RideRequestController::create()` (redirect con mensaje explícito, o error de validación si es un POST) — un conductor no puede entrar al directorio de cliente ni publicar un Expreso, ni siquiera por URL directa.
- **`Express/Available.vue`** (pantalla 100% de conductor, para postularse): se sacó el link "Publicar el mío →" que apuntaba a la pantalla de cliente — no tenía por qué estar ahí.
- **`PublicProfileController::show()`**: `isClient` ahora usa `User::isClient()` (el mismo criterio canónico que el resto de la app), no una cuenta de filas en `fleets`.
- **Limpieza de datos**: se encontraron y borraron 4 flotas fantasma (0 miembros, 0 invitaciones, 0 solicitudes) que este bug le había creado a 4 conductores de la base de desarrollo, incluido Luis Manejo.

### Tests
`tests/Feature/Directory/DriverDirectoryTest.php`: +1 caso (conductor redirigido, sin flota fantasma), -1 caso obsoleto (el que probaba la autoexclusión ya no es alcanzable). `tests/Feature/Express/ExpressRouteFlowTest.php`: +2 casos (conductor no puede publicar, conductor redirigido de "Mis Expresos"). `tests/Feature/PublicProfileTest.php`: +1 caso (conductor con flota fantasma vieja ya no se marca como cliente). Suite completa: 384 tests OK, Pint limpio, build limpio.

### Expresos también se puede habilitar o no por plan de conductor, y quedó más claro quién ve cada uno
El usuario pidió poder gatear módulos por plan (VAN, perfil público, Expresos) y reportó, con un caso real, que probó con "todos los conductores" y ninguno veía una Expreso publicada.

- **Diagnóstico del caso real**: la única Expreso en la base la había publicado Luis Manejo (conductor, no cliente) — justo el bug corregido en la tanda anterior (un conductor podía publicar Expresos antes del guard). Como Luis no es dueño de ninguna flota, ningún conductor podía "ser miembro de su flota" — esa Expreso nunca le iba a aparecer a nadie, sin importar con quién se probara. Se borró (dato de prueba inválido, sin postulaciones ni carreras generadas).
- **Los otros dos módulos que pidió ya estaban gateados por plan** desde tandas anteriores de esta sesión: **VAN/turismo** (`van_trips_enabled`) y **perfil público/directorio** (`public_visibility`) — ambos editables desde `/admin/planes`. De paso se corrigió que el formulario de **crear** un plan nuevo no tenía el checkbox de VAN (solo el de editar lo tenía).
- **Expresos ahora sigue el mismo patrón** (`express_enabled`, nuevo): a diferencia de VAN (función nueva, arranca apagada), Expresos ya era abierto para todos — arranca **habilitado** en todos los planes existentes y en los nuevos que no lo especifiquen, para no sacarle de golpe algo que un conductor ya podía usar. Un conductor sin el flag sigue viendo la lista de "Expresos disponibles" (no se le oculta), pero no puede postularse — mismo criterio de UX que VAN (`VanTripController`).
- **Más entendible, del lado cliente** (`Express/Index.vue`): nota explícita de que solo lo van a ver los conductores que ya son miembros activos de sus flotas — no es un aviso a todos los conductores de la plataforma.
- **Más entendible, del lado conductor** (`Express/Available.vue`): si la lista está vacía, ahora distingue "no pertenecés a ninguna flota todavía" de "pertenecés, pero nadie tiene nada abierto ahora" (`myFleetCount`), en vez de un genérico "no hay nada".

### Tests
`tests/Feature/Subscription/PlanLimitsTest.php`: +2 casos. `tests/Feature/Admin/AdminPlanMaintenanceTest.php`: +2 casos. `tests/Feature/Express/ExpressRouteFlowTest.php`: +2 casos. Suite completa: 389 tests OK, Pint limpio, build limpio.

### Cabecera con el switch de disponibilidad, invitar a quien no está registrado, y Términos/Privacidad reforzados con el dominio nuevo
- **`Dashboard.vue`**: el switch "Activarme"/"Desconectarme" del conductor subió a la cabecera (al lado de "Inicio"), visible sin hacer scroll — se sacó el banner grande que ocupaba espacio más abajo en el cuerpo de la pantalla, ya redundante.
- **`Fleet/Show.vue`** ("Mi flota"): cuando la búsqueda de un conductor por nombre/teléfono/usuario/código no encuentra a nadie, ahora se lo dice explícitamente y ofrece invitarlo a sumarse a Arka01 — por WhatsApp (link `wa.me` con mensaje precargado) o compartir genérico (Web Share API / copiar enlace), en vez de quedarse en silencio.
- **Dominio `arka01.com`**: `.env.example` documentado para que `APP_URL` (en producción) y `MAIL_FROM_ADDRESS` usen el dominio nuevo, con la advertencia de que hay que actualizar también la "Authorized redirect URI" de Google y la URL del webhook de Meta cuando se despliegue ahí — no se tocó el `.env` real (sigue apuntando a Laragon local).
- **Términos y Privacidad reforzados** (pedido explícito del usuario: "cubreme bien... no me hago responsable de nada, solo de prestar el servicio de software"): `Legal/Terms.vue` ahora tiene secciones dedicadas a obligaciones del conductor, obligaciones del cliente, limitación de responsabilidad, indemnidad, suspensión de cuentas y ley aplicable — inspiradas en las prácticas típicas de plataformas de intermediación de transporte (Uber/InDrive), adaptadas y redactadas para Arka01, dejando claro que la plataforma es solo el software, no el transportista. `Legal/Privacy.vue` sumó una sección de "comunicaciones oficiales" (el dominio `arka01.com` como único canal legítimo, para prevenir phishing) y aclaró que Arka01 no controla el uso que la otra parte le dé a los datos de contacto una vez coordinado el viaje. Se mantiene la nota de que esto no reemplaza asesoría legal real.

### Tests
Sin cambios de backend en esta tanda (solo frontend y documentos) — suite completa: 389 tests OK, Pint limpio, build limpio.

### Se arregló el link duplicado al invitar por WhatsApp, y el roster de "Mi flota" ahora muestra los mismos datos que el buscador
- **Bug del link duplicado**: `navigator.share()` recibía el link de registro metido DENTRO del texto del mensaje Y otra vez aparte en el campo `url` — varias apps (WhatsApp incluida) arman el mensaje final concatenando texto + url, así que terminaba apareciendo dos veces. Ahora el texto nunca lleva el link adentro: para el botón de WhatsApp se arma un solo string con el link al final (una sola vez); para "compartir genérico" el link va solo en `url`. De paso se sacó el emoji 🚗 del mensaje (reportado como un ícono roto/"�" en algunos teléfonos).
- **`Fleet/Show.vue`** ("Mi flota"): el roster "Conductores en tu flota" mostraba mucho menos que el buscador de arriba (solo teléfono y tarifa) — ahora suma foto, calificación, cantidad de carreras completadas y categoría (💎🥇🥈🥉), mismo cálculo que ya usaba `FleetController::searchDrivers()` (`memberStats`, nuevo, keyed por id de conductor).

### Tests
`tests/Feature/Fleet/FleetShowMemberStatsTest.php` (nuevo, 2 casos). Suite completa: 391 tests OK, Pint limpio, build limpio.

### Cabecera del conductor: se recuperó el diseño del banner (ícono + título + subtítulo), ahora compacto
El usuario pidió que la cabecera conservara el "look" del banner grande que se sacó (no solo el switch pelado) — se agregó de vuelta el ícono circular y el título/subtítulo ("Activarme"/"Desconectarme"), ocultando el texto en pantallas chicas (queda ícono + switch) para no romper el layout junto con "Inicio".

También se diagnosticó (no era un bug de código) por qué "no manda el WhatsApp y no pasa nada en el log": el log SÍ tenía una entrada — `WHATSAPP_TOKEN` está vencido/inválido (401 "OAuthException" de Meta al intentar mandar un mensaje). Se le indicó al usuario generar un token nuevo (de preferencia permanente, no el de prueba de 24h) desde Meta Business Manager.

### Bug real: el autocompletado de Google Places cargaba datos pero no los mostraba
Diagnosticado paso a paso con capturas de consola del usuario. Dos causas distintas, una después de la otra:

1. **`ERR_BLOCKED_BY_CLIENT`**: una extensión del navegador (bloqueador de anuncios) frenaba el script de Google antes de que cargara — nada que ver con la configuración. Se agregó un `console.warn` en `Utils/googleMaps.js` para que la próxima vez esto se vea directo en la consola.
2. **Ya con el bloqueador desactivado, Google devolvía resultados reales pero la lista nunca se pintaba** — la consola mostraba `Unhandled error during execution of render function`. Causa real: `AddressAutocomplete.vue` guardaba los objetos que devuelve el SDK de Google (`AutocompleteSuggestion`/`PlacePrediction`) en un `ref()` normal — Vue envuelve el contenido de un `ref()` en un Proxy reactivo, y esos objetos de Google dependen de estado interno atado a su identidad real (getters basados en campos privados/WeakMap), que ese Proxy rompe. Cambiado a `shallowRef()` (reacciona igual porque siempre se reemplaza la lista entera, nunca se muta un elemento suelto, pero no envuelve el contenido) — resuelto.

### Tests
Sin cambios de backend — solo frontend. `npm run build` limpio, sin tests automatizados de Places (depende de la API real de Google, ya excluida de la suite por diseño).

### La contraoferta inicial no puede ser menor al precio estimado
Caso real reportado por el usuario: al pedir una carrera, se podía tildar "Proponer otro monto" y mandar un valor simbólico (ej. $2) muy por debajo del estimado ($3.85), sin ningún control.

- **`RideRequestController::store()`**: si el cliente manda un `offered_price` propio, ahora se valida contra el precio estimado que calcula el propio servidor (`PriceCalculator::suggestedPrice()`, con tarifa mínima y recargo nocturno ya aplicados) — nunca contra lo que haya mostrado el navegador, que se puede manipular. Por debajo de eso, error de validación explícito.
- **`Ride/Request.vue`**: mismo chequeo del lado del cliente para avisar antes de intentar mandar el formulario — el botón "Pedir carrera" queda deshabilitado y aparece un aviso en rojo si la propuesta es menor al estimado, en vez de esperar a que el servidor lo rechace.

### Tests
`tests/Feature/Ride/RidePriceNegotiationTest.php`: +1 caso. Suite completa: 392 tests OK, Pint limpio, build limpio.

### El desplegable de direcciones quedaba tapado por el mapa
Bug reportado por el usuario: Leaflet (`Components/FleetMap.vue`) trae sus propios controles internos con z-index hasta 1000 — con el z-40/z-50 que tenía el desplegable de `AddressAutocomplete.vue`, el mapa de más abajo en la pantalla terminaba tapando la parte de abajo de la lista de sugerencias. Subido a z-[1400]/z-[1500] para que gane siempre, sin importar qué mapa haya debajo.

### Pedir un código para cerrar la sesión activa en otro dispositivo
Caso real reportado por el usuario: "no sé dónde dejé loguiada mi sesión y no me deja entrar desde otro navegador". La sesión única por cuenta ya expira sola a las 2 horas de inactividad real (`SESSION_LIFETIME`), pero una pestaña vieja olvidada en segundo plano se mantiene "viva" indefinidamente si la app le sigue pegando pings (ubicación, WebSocket) — nunca llega a esas 2 horas de inactividad real.

- **`User::findByLoginIdentifier()`** (nuevo, estático): resuelve correo/teléfono/usuario a una cuenta — se extrajo de `LoginRequest::resolveUser()` (que ahora delega ahí) para que el nuevo flujo identifique la cuenta exactamente igual que el login normal, sin duplicar la lógica.
- **`User::issueSessionTakeoverCode()` / `verifySessionTakeoverCode()`** (nuevo): mismo patrón que la verificación de teléfono al registrarse, pero en columnas separadas (`session_takeover_code`/`_expires_at`) — no tiene nada que ver con verificar el teléfono, no debía pisar ese estado.
- **`SessionTakeoverController`** (nuevo, `POST /sesion/liberar` y `/sesion/liberar/confirmar`, sin pantallas Inertia aparte — responde JSON, se consume desde un widget chico en `Auth/Login.vue`): pedir el código no revela si la cuenta existe (misma respuesta genérica siempre); confirmarlo cierra **todas** las filas de `sessions` de esa cuenta. Se manda por WhatsApp si el dueño tiene la ventana de 24h abierta (`WhatsAppFreeformSender::sendSessionTakeoverCode()`), si no por correo (`SessionTakeoverCodeMail`, mismo patrón que el aviso de intento de login concurrente que ya existía). Ambos endpoints con `throttle` (3/min pedir, 6/min confirmar) para no habilitar fuerza bruta ni spam.
- **`Auth/Login.vue`**: cuando el error es "Ya tiene una sesión activa en otro dispositivo", aparece la opción "Pedir código" para cerrar esa sesión, con el flujo completo inline, sin salir de la pantalla.

### Tests
`tests/Feature/Auth/SessionTakeoverTest.php` (nuevo, 7 casos, incluye el flujo de punta a punta: login bloqueado → confirmar código → login exitoso con la fila vieja de `sessions` todavía en la base). Suite completa: 399 tests OK, Pint limpio, build limpio.

### Tono de toda la app: "usted", no voseo
Pedido explícito del usuario ("toda la app a usted ya mismo"): nada de "sos vos"/"tenés" — Arka01 le habla al usuario de "usted". Se guardó como memoria durable (`feedback_tono_usted_no_voseo`) porque toda la app venía escrita en voseo desde el principio del proyecto.

Conversión completa, hecha en tres tandas (modelos/servicios/notificaciones PHP, controladores PHP, y las pantallas Vue en dos grupos) más una revisión manual de cierre: quedó texto suelto sin convertir en `Fleet/Show.vue` (títulos de compartir por WhatsApp) y dos aserciones de test que todavía comparaban contra el texto viejo en voseo (`WhatsAppRideAlertTest.php`) — ambos corregidos a mano después de un barrido con `grep` sobre `resources/js/**` y `app/**`. Los comentarios de desarrollador (`//`, `/** */`) y los `console.warn` de diagnóstico técnico (ej. `Utils/googleMaps.js`) se dejaron a propósito en voseo, por no ser texto que vea el usuario final.

### Bloqueo de cuenta desde el aviso de "si no fue usted"
Sobre el mecanismo de sesión única ya existente: el aviso (WhatsApp/correo) que se manda cuando alguien pide cerrar la otra sesión ahora incluye un botón real "No fui yo, bloquear mi cuenta" — antes la única opción era "ignore este mensaje".

- **`users.locked_at`** (nuevo): `User::isLocked()`. El login (contraseña y Google) lo rechaza con un mensaje claro, distinto de "contraseña incorrecta".
- **`GET /sesion/bloquear/{user}`** (nuevo, firmado con `URL::temporarySignedRoute`, 30 min de validez, sin necesitar sesión iniciada — justamente para el caso de cuenta comprometida): bloquea la cuenta y cierra todas sus sesiones de una.
- Reactivarla es a propósito una acción de **admin** (`/admin/usuarios/{user}` ahora tiene un botón "Reactivar cuenta" cuando está bloqueada) — no hay forma de que el mismo link la desbloquee, para que no sirva de nada si alguien más lo intercepta.
- De paso, mejor feedback visual al confirmar el código de liberación de sesión (ícono + mensaje destacado, foco automático a la contraseña) — antes pasaba desapercibido.

### El desplegable de direcciones quedaba tapado por el mapa
Bug reportado por el usuario: Leaflet trae controles internos con z-index hasta 1000 — el desplegable de sugerencias de `AddressAutocomplete.vue` (z-40/z-50) quedaba tapado por el mapa de más abajo en la pantalla. Subido a z-[1400]/z-[1500].

### "No hay conductores disponibles" con un conductor visiblemente "Disponible" en pantalla
Caso real reportado por el usuario: pidió una carrera con Luis Manejo visible como "Disponible" en la lista, y salió "no hay conductores... (pasajeros/cajuela)". Diagnosticado con datos reales: Luis tiene un límite de cobertura de 2 km configurado, y el origen quedaba a 2.24 km — el motivo real era la zona de cobertura, nada que ver con pasajeros ni cajuela (el mensaje fijo siempre culpaba a eso). `RideDispatchCandidates::explainEmptyPool()` (nuevo) diagnostica en etapas (conectado → capacidad → zona → ocupado) y devuelve el motivo real.

### "Mis rutas": guardar un origen+destino con alias, para pedir la próxima carrera más rápido
Pedido explícito del usuario: un check para guardar la ruta apenas está completa (origen y destino), con alias opcional (Casa, Trabajo, Paseo), y un módulo para tomarla directo la próxima vez.

- **`saved_routes`** (nuevo, `SavedRoute` — belongsTo cliente): distinto de las "direcciones favoritas" ya existentes (sueltas, automáticas desde el historial) — acá es un PAR completo guardado a propósito, con nombre propio.
- **`Ride/Request.vue`**: chips de "Mis rutas" arriba del buscador (tocar uno llena origen y destino de una); check "Guardar esta ruta" con alias opcional apenas hay origen y destino — independiente de pedir la carrera en ese momento.

### Cuántos clientes tiene un conductor, al buscarlo o en el roster
Pedido explícito del usuario: saber cuántos clientes tiene ya un conductor antes de sumarse a él. Agregado a `FleetController::searchDrivers()` y a `FleetController::show()` (roster "Conductores en tu flota") — `active_clients_count`.

### Conductores de demo variados para probar pantallas con flotas grandes
Pedido explícito del usuario: ver cómo se ve la pantalla con ~20 conductores por cliente, en distintos estados. Nuevo comando `php artisan demo:seed-many-drivers` (a propósito NO forma parte de `DemoDataSeeder`, que se mantiene mínimo) — le agregó 20 conductores nuevos a cada una de las 4 flotas existentes (80 en total), repartidos en 4 estados parejos: disponible, ocupado (en carrera de verdad), desconectado, y "fantasma" (disponible en la base pero con GPS viejo, para probar `isReachable()`). Subió el plan de cada cliente a Multi-flota donde hacía falta más cupo. Contraseña única para las 80 cuentas nuevas: `Demo1234`. No tocó ninguna cuenta que ya existía.

### La lista de conductores para pedir carrera era muy larga, sin orden ni paginado
Consecuencia directa de la seed anterior (flotas de ~20 conductores): la lista en `Ride/Request.vue` se volvió una scrolleada eterna, sin ningún criterio de orden más allá de "disponible primero". Solo cambios de frontend, sin tocar backend ni el despacho secuencial.

- **Orden**: se mantiene la regla de siempre (disponible antes que ocupado antes que desconectado), pero ahora el cliente elige el desempate entre quienes empatan en estado — "Más cercanos" (nuevo criterio por defecto, mismo que ya usa el despacho secuencial para "toda mi flota"), "Más económicos" (por `rate_per_km`) o "Mejor calificados" (el orden que había antes).
- **Paginado**: de a 5 conductores por página, con "Anterior/Siguiente" — cambiar de fuente (Mi flota/Público/Ambos), de orden, o de cantidad de pasajeros vuelve siempre a la página 1.
- **Botón "Pedir carrera" duplicado arriba**: al lado del selector "Mi flota/Público/Ambos", para no tener que bajar toda la pantalla — misma acción que el botón de siempre (que sigue estando después del precio, para quien prefiere revisar todo antes de mandar).

### Tests
Sin casos nuevos (cambio de frontend, sin tocar `RideDispatchCandidates` ni `RideRequestController`) — se corrió `php artisan test --filter=Ride` (131 tests OK) para confirmar que el despacho secuencial y el resto del flujo de carreras no se vieron afectados. Pint limpio, build limpio.

### Tests
`tests/Feature/Auth/SessionTakeoverTest.php` (+6 casos de bloqueo/desbloqueo), `tests/Feature/Ride/DriverCoverageRangeTest.php` (+1), `tests/Feature/Ride/SavedRouteTest.php` (nuevo, 5 casos), `tests/Feature/Fleet/FleetInvitationFlowTest.php` y `FleetShowMemberStatsTest.php` (+1 cada uno), `tests/Feature/WhatsApp/WhatsAppRideAlertTest.php` (2 aserciones actualizadas al texto en "usted"). Suite completa: 411 tests OK, Pint limpio, build limpio.

### Auditoría de seguridad: webhook sin firmar, datos de más expuestos, documentos sensibles en disco público
Pedido explícito del usuario ("cómo estamos con respecto a seguridad y a datos expuestos"): dos agentes en paralelo revisaron autorización/IDOR, exposición de datos, inyección SQL, XSS, subida de archivos, webhook de WhatsApp y configuración. Sin inyección SQL (todos los `selectRaw` son agregaciones fijas) ni XSS (cero `v-html` en todo el frontend) — dos dominios limpios. Se corrigieron los 4 hallazgos reales, de más grave a menos:

- **Webhook de WhatsApp sin validar firma (alto)**: cualquiera que conociera la URL podía mandar un POST armado a mano y que se procesara como un mensaje real de Meta (abrir ventanas de sesión, completar teléfonos ajenos). Nuevo middleware `App\Http\Middleware\VerifyWhatsAppSignature` (`whatsapp.signed`, aplicado solo al POST de `receive()`) valida el header `X-Hub-Signature-256` (HMAC-SHA256) contra `WHATSAPP_APP_SECRET`. **Variable nueva en `.env` que hay que completar con el "App Secret" de Meta for Developers** — mientras quede vacía (como está ahora), el webhook sigue funcionando sin validar firma, igual que antes, para no cortar la integración ya conectada.
- **`/perfil/{user}` exponía el modelo `User` completo (alto)**: cualquier usuario logueado podía enumerar toda la base (email, teléfono, `is_admin`, códigos hasheados) visitando `/perfil/1`, `/perfil/2`... `PublicProfileController::show()` ahora arma un array explícito con solo lo que `Profile/Show.vue` de verdad muestra (mismo criterio que ya usaba `PublicRideTrackingController`). De fondo, `User::$hidden` (antes solo `password`/`remember_token`) ahora también tapa `session_takeover_code`, `phone_verification_code` (y sus vencimientos), `locked_at` y `google_id` en toda la app — `is_admin` queda visible a propósito, el propio usuario lo necesita para su nav. Donde un contexto de confianza sí necesita alguno de estos (el admin viendo si una cuenta está bloqueada), se usa `->makeVisible()` puntual (`Admin/UserProfileController::show()`), no se destapa para todos.
- **Sin límite de tasa en varios endpoints sensibles (medio)**: `throttle` agregado a recuperar contraseña (`forgot-password`, `reset-password`), el botón SOS (5/min) y la subida de comprobante de pago (6/min) — antes solo login/registro/verificación lo tenían.
- **Documentos sensibles en disco público (medio)**: la foto de licencia (documento de identidad) y el comprobante de transferencia bancaria quedaban en el disco `public`, accesibles por URL directa a quien la consiguiera. Ambos pasan al disco `local` — se sirven por un controlador (`DriverProfileController::licensePhoto()`, `SubscriptionRequestController::paymentProof()`) que solo deja verlos al dueño o a un admin. La foto del **vehículo** queda a propósito en el disco público — es contenido pensado para mostrarse en el directorio y el perfil público. Los archivos que ya estaban subidos (1 licencia + 5 comprobantes en uso real) se migraron al disco privado sin perder el vínculo con su registro.

Quedó afuera de esta pasada, por ser una decisión de producto y no un fix puntual: el login con Google vincula por email a una cuenta preexistente sin re-verificarla, lo que en teoría habilita "pre-account hijacking" (alguien registra antes una cuenta local con el email de la víctima). Se conversa aparte si se quiere reforzar.

### Tests
`tests/Feature/Security/WhatsAppWebhookSignatureTest.php` (nuevo, 4 casos), `tests/Unit/UserHiddenAttributesTest.php` (nuevo), `tests/Feature/PublicProfileTest.php` (+1), `tests/Feature/Security/DriverVerificationTest.php` (+1, y 2 tests existentes ajustados al disco `local`), `tests/Feature/Plan/SubscriptionRequestFlowTest.php` (+1, y 1 test existente ajustado al disco `local`). Suite completa: 419 tests OK, Pint limpio, build limpio.

### Medallas por puntos: fidelizar el uso de la app frente al "arreglo directo por WhatsApp"
Charla del usuario sobre cómo lograr que clientes y conductores usen la app en vez de arreglar directo una vez que ya se conocen — se decidió empezar por el lado conductor: que subir de medalla dependa de carreras hechas *por la app*, no de la calificación.

- **Puntos por carrera completada** (`RideController::complete()`): 1 punto si la carrera es menor a 5 km, 2 si es igual o mayor — corte fijo en código (el usuario pidió configurables las medallas, no esta regla puntual). Se acumulan en `driver_profiles.total_points` (`increment()`, atómico) y quedan trazables carrera por carrera en `rides.points_earned`.
- **`driver_tiers`** (nuevo, `App\Models\DriverTier`): catálogo de medallas editable desde **`/admin/medallas`** — nombre, a partir de cuántos puntos, emoji, color, y si aparece en el directorio público. Reemplaza el criterio fijo de `DriverCategory::forRating()` (basado en calificación) para el lado conductor — ese servicio se deja intacto solo para `client_category` en `Driver/Invitations.vue` (la medalla que un conductor le ve a su cliente, que sigue siendo por calificación: un cliente no recorre distancia). Semilla con los números de ejemplo del usuario: Cobre desde 0 puntos, Plata desde 150, Oro desde 500 (habilitada para el directorio), Diamante desde 1000 (también habilitada).
- **`DriverDirectoryController::index()`**: además del plan pagado que ya gateaba el directorio público (`is_public`), ahora también hace falta haber ganado una medalla marcada como "aparece en público" — hoy Oro para arriba. De yapa ("mejoralo como veas conveniente"): la medalla más alta se lista primero (Diamante antes que Oro), coherente con ser la más difícil de ganar.
- **`Driver/Profile.vue`**: nueva sección con la medalla vigente, los puntos totales, y — si todavía no llega — cuántos puntos le faltan para la próxima medalla que habilita el directorio.
- El mapa fijo de 4 medallas hardcodeado en 3 pantallas (`Ride/Request.vue`, `Fleet/Show.vue`, `Admin/Drivers.vue`) se reemplazó por datos que manda el backend (`tier: { name, badge_emoji, color_key }`) + un diccionario chico de colores válidos de Tailwind (`Utils/tierBadge.js`) — necesario porque ahora el admin puede agregar una 5ta medalla, y una clase de Tailwind guardada en la base nunca se generaría en el build.

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php` (+3: 1 punto por carrera corta, 2 por larga, se acumulan entre carreras), `tests/Unit/DriverTierTest.php` (nuevo), `tests/Feature/Directory/DriverDirectoryTest.php` (+2: no aparece por debajo de Oro, Diamante antes que Oro), `tests/Feature/Admin/AdminDriverTierMaintenanceTest.php` (nuevo, 6 casos, calcado de `AdminPlanMaintenanceTest`). 3 tests existentes ajustados (`FleetInvitationFlowTest`, `StaleDriverAvailabilityTest` ×2 — ahora necesitan `total_points` para seguir siendo visibles en el directorio). Bug propio encontrado y corregido en el camino: `points_earned` no estaba en `Ride::$fillable`, así que no se guardaba pese a que `total_points` sí se incrementaba — lo agarraron los tests nuevos antes de darlo por terminado. Suite completa: 432 tests OK, Pint limpio, build limpio.

### Forma de pago: el cliente la elige al pedir la carrera, el conductor la ve antes de aceptar
Pedido explícito del usuario: no existía (ni existió nunca — se confirmó contra el código que tampoco se había quitado ningún botón de confirmación de pago de carreras) ningún campo para que el cliente indicara cómo va a pagar. Nuevo `payment_method` (`efectivo` por defecto — el usuario pidió justamente ese default — o `transferencia`) en `ride_requests`, elegido en `Ride/Request.vue` con el mismo estilo de pastillas que "Mi flota/Público/Ambos", y copiado a `rides` al aceptar (mismo patrón que `rate_per_km_snapshot`/`price`, ver `RideRequestController::accept()`). Visible para el conductor en los tres momentos donde importa: el aviso en vivo de carrera nueva (`IncomingRideRequestModal.vue`), la lista de solicitudes entrantes (`Ride/Index.vue`), y el desglose de precio del detalle de la carrera (`Ride/Show.vue`).

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php` (+2: default a efectivo, se guarda y se copia a la carrera al elegir transferencia). Suite completa: 434 tests OK, Pint limpio, build limpio.

### "Pedir carrera" con menos vueltas: opciones y lista de conductores colapsadas por defecto
Pedido explícito del usuario (con un mockup interactivo acordado antes de tocar el flujo real): la pantalla se había ido llenando de campos que casi siempre valen lo mismo. Sin sacar ninguna función:

- **"¿Cuántos van?"** pasó a un resumen de una línea ("1 pasajero · Efectivo · sin cajuela ✎") que se abre solo si se toca — los campos de pasajeros/cajuela/forma de pago siguen siendo los mismos, solo empiezan ocultos.
- **"¿A quién se la pedís?"**: "Toda mi flota disponible" queda al frente, sin la lista completa de conductores compitiéndole el espacio. Elegir uno puntual pasa a ser una acción a propósito ("Elegir un conductor puntual (N)"), que arranca abierta sola si ya venía un conductor preseleccionado (ej. desde "Mi flota") o si el backend rechazó "toda la flota" y hay que ver la sugerencia de ampliar la búsqueda.

### Tests
Sin casos nuevos (cambio de frontend puro, sin tocar ningún controlador ni la lógica de despacho) — se corrió `php artisan test --filter=Ride` (136 tests OK) para confirmar que nada del backend se vio afectado. Pint limpio, build limpio.

### Ícono de auto para conductores en el mapa, en vez del pin genérico de Leaflet
Bug de diseño reportado por el usuario (captura: los pines de conductores en el mapa de "Pedir carrera" se veían con el ícono celeste por defecto de Leaflet) — la causa era que esos marcadores nunca tenían asignado ninguno de los íconos propios de `Components/FleetMap.vue` (`ICONS.origin/destination/driver`, buscados por `marker.id` exacto), así que cualquier `id` que no calzara con esos tres caía en el ícono genérico. Nuevo `ICONS.car` (auto visto desde arriba, verde de la marca, distinto del puntito celeste que ya se usaba para el seguimiento en vivo de UN conductor puntual) — aplicado en las dos pantallas que listan varios conductores a la vez en el mapa: `Ride/Request.vue` y `Admin/Drivers.vue`.

### Tests
Sin casos nuevos (cambio visual puro, sin lógica de negocio de por medio). Build limpio.

### Lista de espera: pedir una carrera cuando toda la flota está ocupada ya no se rechaza de una
Pedido explícito del usuario, mirando la pantalla de "Pedir carrera" con los 5 conductores "En carrera": "¿puedo dejar la carrera pendiente hasta que uno se desocupe y me atienda?". Se confirmó contra el código que no — `RideRequestController::store()` rechazaba la solicitud de una con `ValidationException` ("Todos sus conductores disponibles están en otra carrera ahora mismo") sin crear ningún registro. Ahora, cuando ese es el ÚNICO motivo por el que la bolsa de candidatos está vacía (todos conectados, con capacidad y en zona — solo que ocupados), la solicitud queda en un estado nuevo, `waiting`, en vez de rechazarse. Confirmado con el usuario: **límite de 15 minutos** y **FIFO** si hay más de uno esperando.

- **`RideDispatchCandidates`**: `explainEmptyPool()` se refactorizó para delegar en un `poolEmptyReason()` interno (mismo texto de siempre, sin romper nada) — nuevo `isEmptyOnlyBecauseEveryoneIsBusy()` público, usado por `store()` para decidir si conviene esperar en vez de rechazar.
- **`RideDispatchAdvancer::activateNextWaitingRequest()`** (nuevo): se llama desde `RideController::complete()`, justo después de liberar al conductor. Recorre las solicitudes `waiting` de más antigua a más nueva y activa la primera cuya bolsa —recalculada de cero con `RideDispatchCandidates::forPool()`, ya sin este conductor como ocupado— no esté vacía; si esa primera pide algo que este conductor puntual no puede cumplir (ej. cajuela), se salta a la siguiente. Reutiliza el mismo mecanismo de ofrecer/avisar/vencer a 30 segundos que ya existía para la cascada normal (extraído a `notifyCurrentCandidate()`), así que si el conductor activado tampoco responde a tiempo, sigue cascadeando como cualquier otra solicitud.
- **`ExpireWaitingRideRequest`** (nuevo Job, calcado de `ExpireRideOffer`): a los 15 minutos, si sigue `waiting`, la marca `expired` — mismo aviso que ya existía para "nadie respondió a tiempo" en la cascada normal.
- El cliente ve su solicitud en espera en "Esperando respuesta" (`Ride/Index.vue`) con un aviso claro, y se actualiza SOLA (sin recargar) apenas se le asigna un conductor — se le sumó su propio canal personal a `RideRequested::broadcastOn()` (antes solo avisaba a conductores), verificado que es seguro: el modal de "carrera entrante" solo se monta para cuentas de conductor.

### Tests
`tests/Feature/Ride/SequentialDispatchTest.php` (+7): queda `waiting` cuando el único motivo es "todos ocupados"; se sigue rechazando igual que antes por cualquier otro motivo; se activa la más antigua (FIFO) al liberarse un conductor; se salta una que no se puede cumplir y activa la siguiente; se puede cancelar una solicitud en espera; expira a los 15 min si nadie la toma; no hace nada si ya se activó antes de que corra el Job. Suite completa: 441 tests OK, Pint limpio, build limpio.

### Bug propio: un conductor "En carrera" podía diagnosticarse como "ninguno conectado"
El usuario reportó una contradicción real en pantalla: la lista de candidatos mostraba a varios conductores "En carrera" (con distancia y todo), pero el mensaje de arriba decía "Ninguno de sus conductores está conectado ahora mismo" — encontrado apenas un día después de agregar `poolEmptyReason()` para la lista de espera. Causa: ese método chequeaba el ping general de ubicación (`DriverProfile::isReachable()`) **antes** de chequear si el conductor estaba en carrera — mientras que la lista visible (`RideRequestController::driverCardData()`) hace exactamente lo contrario (ocupado le gana a "sin ping reciente"), porque el ping general se atrasa mientras el conductor maneja una carrera real. Dos piezas de código con el mismo dato, prioridades distintas. Confirmado con datos reales: los 20 conductores demo "en carrera" (`demo:seed-many-drivers`) tenían el ping congelado desde el momento de la siembra, ya viejo para cuando el usuario probó la pantalla.

- **`RideDispatchCandidates::poolEmptyReason()`**: el chequeo de "conectado" ahora considera conectado a un conductor que tiene una `Ride` real en curso a su nombre, sin importar qué tan viejo esté su ping general — mismo criterio de prioridad que ya usaba la lista visible. Esto también corrige la lista de espera nueva: antes, un conductor ocupado-pero-con-ping-viejo hacía que la solicitud se rechazara con un mensaje sin sentido en vez de quedar `waiting`.
- Se refrescó el ping de los 20 conductores demo "en carrera" ya sembrados, para que la pantalla se vea bien de una sin tener que rehacer la siembra.

### Tests
`tests/Feature/Ride/StaleDriverAvailabilityTest.php` (+2: un conductor ocupado con ping viejo sigue contando como "conectado" en el diagnóstico; de punta a punta, la solicitud queda `waiting` en ese caso en vez de rechazarse). Suite completa: 443 tests OK, Pint limpio.

### Panel admin: paginado y filtros en conductores, módulo de clientes, y borrar la demo
Pedido explícito del usuario, mirando la tabla "Todos los conductores" (traía los ~90 de una sola vez, sin paginar ni filtrar): paginado + filtros ahí, una pantalla nueva para ver los clientes registrados con el mismo criterio, filtro por ciudad en ambas, fecha de registro y última actividad, y un botón para borrar toda la data de prueba y dejar el sistema reiniciado. Alcance del borrado confirmado con el usuario: **solo cuentas `@arka01.test`** (el dominio de toda cuenta demo, de siempre hasta las ~90 de `demo:seed-many-drivers`) — cualquier cuenta real con otro correo queda intacta.

- **`Admin\DriverController::index()`**: el roster completo ahora es `->paginate(20)`, con filtros `q` (nombre/correo), `city_id` y `status` (disponible/suspendido/desconectado), más ciudad, fecha de registro y última actividad por fila. "Disponibles ahora" (arriba, con el mapa) no cambió — sigue trayendo todos de una, son pocos por definición. "Última actividad" sale de `sessions.last_activity` (columna ya existente, sin migración nueva) agregada por lote, no una consulta por fila.
- **`Admin\ClientController`** (nuevo) + **`Admin/Clients.vue`** (nuevo, `/admin/clientes`): mismo patrón del lado cliente — nombre, correo, ciudad, registro, última actividad, cuántos conductores tiene en su(s) flota(s) y cuántas carreras completó, filtrable por `q`/`city_id`, paginado. Filtra por `role = 'cliente'` (columna ya resuelta de antes), no llama `User::isClient()` por fila para no cargar el perfil de conductor de cada uno solo para descartarlo.
- **`Admin\SystemController`** (nuevo) + **`Admin/System.vue`** (nuevo, `/admin/sistema`, pantalla propia de "zona de peligro"): un botón, con confirmación fuerte y el texto explícito de qué borra. Al confirmar: borra `User::where('email', 'like', '%@arka01.test')` dentro de una transacción (cascada ya definida en las FK se lleva flotas/carreras/suscripciones/reseñas de esas cuentas), vuelve a correr `DemoDataSeeder` para recrear el elenco base de 9 cuentas, y — como `admin@arka01.test` es casi con toda seguridad la cuenta con la que se está usando el panel — si el correo de quien lo ejecutó termina en `@arka01.test` cierra su sesión y lo manda al login; si es un admin con otro correo, se queda donde está.
- Nav del panel admin: nuevas pestañas "Clientes" y "Sistema" en `AdminLayout.vue`.

### Tests
`tests/Feature/Admin/AdminDriverControllerTest.php` (+3: paginado a 20 por página, filtro por ciudad, búsqueda por nombre/correo), `tests/Feature/Admin/AdminClientControllerTest.php` (nuevo, 5 casos: solo lista clientes —no conductores ni admins—, filtros, paginado, conteo de conductores en flota), `tests/Feature/Admin/AdminSystemControllerTest.php` (nuevo, 6 casos: borra cuentas `@arka01.test` y recrea las 9 base, una cuenta real sobrevive intacta, cascada a flota de una cuenta demo, un admin demo queda deslogueado tras el reset, un admin no-demo se queda logueado, acceso restringido a admin). Suite completa: 457 tests OK, Pint limpio, build limpio.

### Viajes en VAN: punto exacto de salida/llegada y costo aproximado antes del precio
Pedido explícito del usuario, verificando que el autocompletado de Google (usado en "Pedir carrera" y en Expresos) también estuviera en Viajes en VAN: no estaba, y no era un olvido — ese módulo solo manejaba ciudad de origen/destino (`origin_city_id`/`destination_city_id`, sin ninguna coordenada), a diferencia de Expresos y Pedir carrera que ya trabajan con un punto exacto. El usuario pidió agregarlo: marcar las coordenadas en el mapa, calcular los km, y sugerir un costo aproximado **antes** de que el conductor ponga su precio por asiento.

- **`van_trips`**: nuevas columnas `origin_lat/lng`, `origin_address`, `destination_lat/lng`, `destination_address` y `distance_km` — todas nullable, mismo criterio que `express_routes`. Un viaje se sigue pudiendo publicar solo con la ciudad, como hasta ahora; si se marca uno de los dos puntos, el otro pasa a ser obligatorio (`required_with`) para poder calcular la distancia.
- **`VanTripController::store()`**: la distancia se recalcula siempre en el backend con `App\Services\Haversine` (nunca se confía en el número que mande el navegador — mismo criterio que el precio de una carrera). `index()` ahora manda `driverRatePerKm` (la tarifa/km del propio perfil de conductor, si ya la tiene configurada) para que el frontend pueda sugerir el costo.
- **`VanTrips/Index.vue`**: nueva sección "Punto exacto de salida y llegada (opcional)" con el mismo mapa Leaflet + toggle "Marcar salida/llegada" que ya usa Expresos, y dos campos de referencia con autocompletado de Google Places (`AddressAutocomplete.vue`, mismo componente, sin nada nuevo que mantener). En cuanto los dos puntos quedan marcados, aparece un aviso con la distancia y — si el conductor ya tiene tarifa/km configurada — el costo aproximado del viaje completo y por asiento, justo antes del campo "Precio por asiento".

### Tests
`tests/Feature/VanTrips/VanTripFlowTest.php` (+4: guarda la distancia calculada al marcar los dos puntos, sigue funcionando sin coordenadas —queda `null`—, falla la validación si falta la mitad de un par, `driverRatePerKm` llega correcto a la pantalla). Suite completa: 461 tests OK, Pint limpio, build limpio.

### Bug propio: el mapa no trazaba ni se centraba al elegir las referencias (Expresos y Viajes en VAN)
Reportado por el usuario probando lo de arriba. Dos causas distintas, las dos en `Components/FleetMap.vue` y en las dos pantallas que recién empezaron a usar direcciones de referencia:

- **No se centraba al marcar el segundo punto**: `FleetMap.vue` solo encuadraba los marcadores la primera vez que aparecía CUALQUIERA (`hasFitBoundsOnce`, pensado para no pelear con el pan/zoom manual cuando un conductor en vivo mueve su ping) — así que al marcar el origen se centraba bien, pero al marcar el destino ya no volvía a ajustar la vista para mostrar los dos juntos. Ahora se reencuadra cada vez que cambia la composición de la lista (cuántos marcadores hay y de qué id), no solo la primera vez — sigue sin reajustar si lo único que cambió es la posición de los mismos marcadores de siempre, que es el caso que el mecanismo original quería evitar.
- **No se trazaba ninguna línea**: "Pedir carrera" sí pide el recorrido real a OSRM apenas hay origen y destino, pero Expresos y Viajes en VAN nunca lo hacían — ese trazado no se había portado a esas dos pantallas cuando se armaron. Se sacó a `Utils/osrmRoute.js` (compartido, `Ride/Request.vue` se cambió para usarlo también en vez de tener el mismo fetch pegado tres veces) y se conectó en las tres pantallas.

### Tests
Cambios de frontend puros (Vue + un componente compartido) — se corrió `php artisan test --filter="VanTrip|Express"` (43 tests OK) para confirmar que la validación/persistencia del backend sigue intacta. Pint limpio, build limpio.

### Expresos: costo estimado y viajes de ida y vuelta
Pedido explícito del usuario, mirando el formulario de Expresos ya con mapa y trazado: "falta el costo estimado y si es ida y vuelta... debería ver una hora del origen y otra del destino y el precio que coloque que no sea menor al estimado."

- **Costo estimado**: mismo mecanismo que "Pedir carrera" — `ExpressRouteController::index()` manda `referenceRatePerKm` (promedio de tarifa/km de los conductores activos en CUALQUIERA de las flotas del cliente, ya que un Expreso no es de una flota puntual) y `minimumFare`. `Express/Index.vue` muestra la distancia × esa tarifa (con la tarifa mínima como piso) antes del campo "Precio por carrera".
- **Piso de precio**: el precio que se fije no puede ser menor al estimado — validado en el propio backend (`ExpressRouteController::suggestedPrice()`, reutiliza `App\Services\PriceCalculator` igual que una carrera normal, pero solo la parte `base` — sin recargo nocturno, porque acá el precio se pacta una sola vez para todos los días que corresponda, no tiene sentido atarlo a la hora en que se publica el Expreso). Mismo criterio aplicado en `store()` y `update()`.
- **Ida y vuelta**: nuevas columnas `is_round_trip` y `return_time` en `express_routes`. Con el checkbox marcado, "Hora de salida" pasa a ser explícitamente la del origen y aparece una segunda hora, la de vuelta desde el destino. `GenerateExpressRides` ahora genera DOS carreras por día para un Expreso de ida y vuelta (la de vuelta con origen y destino invertidos, a la hora de vuelta) — para el dueño y para cada acompañante aceptado si el Expreso está compartido.

### Tests
`tests/Feature/Express/ExpressRouteFlowTest.php` (+5: `referenceRatePerKm`/`minimumFare` llegan a la pantalla, publicar por debajo del estimado falla, ida y vuelta sin hora de vuelta falla, ida y vuelta guarda las dos horas, el motor de recurrencia genera las dos carreras del día —y no las duplica si corre dos veces—). De paso, un test existente (`AdminClientControllerTest`) resultó frágil por una fábrica (`FleetMemberFactory`) que arma un `User::factory()` nuevo para `added_by` si no se lo pasa explícito — quedó expuesto por el azar del nombre aleatorio de Faker, no por nada de esta tanda; se corrigió pasando `added_by` explícito. Suite completa: 466 tests OK, Pint limpio, build limpio.

### Bug propio: el estimado de Expresos salía en $0
Reportado por el usuario probando lo de arriba con un Expreso nuevo: "sale $0.00, deberías sacar un estimado". Causa real, no un problema de caché — `referenceRatePerKm()` promediaba solo los conductores YA en la(s) flota(s) del cliente, y un cliente publicando su primer Expreso (el caso más común: recién decide armar uno para que se postule alguien) todavía no tiene ninguno — promedio de una lista vacía, $0 siempre, para cualquier cliente nuevo. Ahora, si la propia flota no tiene con qué calcular un promedio, se usa como referencia el promedio de tarifa/km de TODA la plataforma (conductores con tarifa configurada, sin importar la flota) — deja de haber un estimado en blanco.

### Tests
`tests/Feature/Express/ExpressRouteFlowTest.php` (+1: sin conductores en la flota, el estimado cae al promedio de la plataforma). Suite completa: 467 tests OK, Pint limpio.

### Bug propio: tocar el mapa para reubicar un punto no recalculaba nada, y desglose del precio en Expresos ida y vuelta
Dos pedidos del usuario en el mismo mensaje, probando Expresos:

- **"Si moví en el mapa no se recalculó el km"**: causa real en `Components/FleetMap.vue` — un marcador de Leaflet es interactivo por default y se queda con el clic para sí mismo (para abrir su propio popup) en vez de dejarlo pasar al mapa. Volver a tocar justo donde ya está el pin de origen/destino para reubicarlo (el gesto más natural para "moverlo") caía sobre el marcador, no sobre el mapa, así que `map-click` nunca se disparaba — solo la búsqueda de referencia (que no pasa por el mapa) sí actualizaba todo. Se desactivó la interactividad de los marcadores únicamente en las pantallas donde el mapa es clickeable (Pedir carrera, Expresos, Viajes en VAN) — ahí no hace falta el popup propio del pin, el label ya se ve en el formulario.
- **"Si es ida y vuelta debería duplicar el valor... y el desglose"**: confirmado con el usuario (pregunta directa, por ser una decisión de precio real) que "Precio por carrera" sigue siendo por trayecto — se cobra ese monto en cada una de las dos carreras que se generan por día, sin cambiar nada del backend. Se agregó nomás el desglose informativo en `Express/Index.vue`: además del estimado por trayecto, con ida y vuelta marcada se ve una segunda línea "$estimado × 2 carreras/día = $total/día".

### Tests
Cambios de frontend puros (componente compartido + una pantalla) — se corrió la suite completa para confirmar que nada del backend se vio afectado. 467 tests OK, Pint limpio, build limpio.

### El mapa no arrancaba en la ciudad del cliente, y el precio de Expresos ya no exige calzar con el estimado
Dos pedidos más del usuario, mismo hilo:

- **Mapa centrado en la ciudad**: "el mapa al inicio no se posiciona donde está el cliente, debería posicionarse por lo menos en la ciudad" — `Express/Index.vue` y `VanTrips/Index.vue` nunca pasaban un `:center` a `FleetMap.vue`, así que siempre arrancaban en el default fijo del componente (Quito). Ahora, al abrir la pantalla, se intenta la geolocalización real del navegador (mismo mecanismo que "Pedir carrera") y, si no responde a tiempo o no hay permiso, se cae a la ciudad que el cliente/conductor ya tiene registrada (`ExpressRouteController`/`VanTripController` mandan sus coordenadas).
- **Piso de precio al 50%, no al 100%**: pedido explícito del usuario — "permite que sean menor al precio estimado pero que no pase menor el 50% del valor estimado". El precio de un Expreso ya no tiene que calzar con el estimado; alcanza con no bajar de la mitad. Nueva constante `ExpressRouteController::MINIMUM_PRICE_FACTOR` (0.5), única fuente de verdad para `store()`, `update()` y lo que ve el cliente en pantalla (mandada como prop `minimumPriceFactor`, en vez de tener el mismo número pisado en dos lugares).

### Tests
`tests/Feature/Express/ExpressRouteFlowTest.php` (+3: la pantalla manda la ciudad del cliente para el mapa —o `null` si no tiene—, un precio por debajo de la mitad del estimado sigue fallando, uno entre la mitad y el estimado completo ahora se acepta). Suite completa: 470 tests OK, Pint limpio, build limpio.

### Bug propio: logo duplicado en el login (más notorio en móvil)
Reportado por el usuario con una captura. `Layouts/GuestLayout.vue` ya pone el logo arriba de la tarjeta en toda pantalla sin sesión (chico en móvil, oculto en escritorio porque ahí lo lleva el panel de marca) — pero `Pages/Auth/Login.vue`, y solo esa pantalla (ninguna otra de Auth lo hacía), tenía además su propio `<ApplicationLogo>` metido adentro de la tarjeta, sin ninguna condición que lo ocultara en desktop. Se sacó el logo de más — se saca del layout compartido nomás.

### Tests
Cambio visual puro (una línea menos en una plantilla). Build limpio.

### Correo de bienvenida al registrarse
Pedido explícito del usuario ("¿existe alguna plantilla que se envía al registro?... debe ser una plantilla con un buen diseño"). Se investigó primero: no existía ninguno — `RegisteredUserController::store()` nunca mandaba correo al crear la cuenta, y el scaffolding de "verificación de email" de Laravel está inactivo a propósito (`MustVerifyEmail` comentado en `User.php`, el teléfono se verifica por WhatsApp en su lugar). Los únicos 3 correos que sí existían (login concurrente, código de sesión, alerta SOS) usan el tema markdown genérico de Laravel, sin nada de la marca — no había ninguna plantilla con diseño propio de la que partir.

- **`App\Mail\WelcomeMail`** (nuevo) + **`resources/views/emails/layout.blade.php`** (nuevo, cascarón reutilizable con el logo tipográfico de dos colores y el fondo oscuro de la marca — pensado para que otro correo futuro pueda sumarse al mismo diseño sin repetirlo) + **`resources/views/emails/welcome.blade.php`** (nuevo, el contenido: saludo, su usuario y código de socio ya generados, botón "Ir a mi cuenta"). HTML de tabla con estilos en línea (no Tailwind — un cliente de correo no lo procesa) y `color-scheme: dark` explícito para que Gmail/Apple Mail no le apliquen su propio modo oscuro encima y rompan el contraste ya pensado a propósito.
- Se manda desde `RegisteredUserController::store()`, envuelto en `try/catch` (mismo criterio que `SosAlertController`): si el correo falla, se registra en el log pero el registro de la cuenta sigue andando igual. Se manda sincrónico, no en cola — acá no corre ningún `queue:work` en local (ver el resto de los correos del proyecto, todos sincrónicos también), ponerlo en cola lo hubiera dejado sin mandar nunca en desarrollo.

### Tests
`tests/Feature/Auth/RegistrationTest.php` (+1: registrarse manda el correo de bienvenida al usuario correcto). Suite completa: 471 tests OK, Pint limpio.

### Recorrido guiado (onboarding) por rol, una sola vez
Pedido explícito del usuario: explicar los módulos importantes a cada usuario nuevo, distinto para cliente y para conductor, que se muestre una sola vez, con botón "Siguiente" y botón "Saltar guía de uso".

Antes de escribir el contenido se investigó la navegación real: hoy existen **tres listas de accesos parcialmente distintas entre sí** (la grilla de accesos rápidos de escritorio, el bottom sheet de móvil escrito a mano, y las tarjetas de "Acciones rápidas" del Dashboard) — no son la misma fuente de verdad. Por eso el tour no señala (spotlight) ningún botón real en pantalla — habría quedado frágil entre móvil/escritorio y desalineado en cuanto cambiara cualquiera de esas tres listas — sino una serie de "ventanitas" (modal, un paso a la vez) que explican cada módulo por nombre, sin depender de la posición de ningún elemento del DOM. Sin librería nueva: reutiliza `Components/Modal.vue`, que ya usa el propio panel de Ayuda.

- **`users.onboarding_completed_at`** (nueva columna, `datetime` nullable): mismo patrón ya establecido en el proyecto para "flags" de una sola vez (`phone_verified_at`, `locked_at`), no un booleano suelto. Viaja solo al frontend dentro de `auth.user` (`HandleInertiaRequests` ya comparte el modelo completo), sin tocar el middleware.
- **`Utils/onboardingSteps.js`** (nuevo): contenido de 5 pasos por rol, basado en los módulos reales — **cliente**: Mi flota → Directorio de conductores → Pedir una carrera → Mis Expresos; **conductor**: Mi perfil de conductor → Mis clientes → Carreras → Expresos disponibles (más el paso de bienvenida en los dos). Separado del componente para poder ajustar el texto sin tocar lógica.
- **`Components/OnboardingTour.vue`** (nuevo): un paso a la vez sobre `Modal.vue`, puntitos de progreso, "Saltar guía de uso" siempre visible y "Siguiente" (dice "Entendido" en el último paso).
- **`AuthenticatedLayout.vue`**: se abre solo en `onMounted` si corresponde al rol activo y todavía no se vio (no aplica al admin, nav completamente distinta). Cualquier cierre —terminarlo, saltarlo, click afuera, Escape— manda `POST onboarding.complete` (`OnboardingController`, nuevo) y ya no se muestra solo de nuevo. Se agregó "Ver guía de nuevo" dentro del panel de Ayuda ya existente, para volver a verla a propósito sin que eso reinicie el flag.

### Tests
`tests/Feature/OnboardingTest.php` (nuevo, 3 casos: una cuenta nueva arranca sin completar, `POST onboarding.complete` marca el timestamp, un invitado no puede pegarle a esa ruta). El disparo automático y el contenido de los pasos son de frontend puro, verificados con el build. Suite completa: 474 tests OK, Pint limpio, build limpio.

### "Mis indicadores" del conductor, y ayuda contextual "?" en accesos rápidos
Dos pedidos del usuario, mirando la pantalla "Carreras" del lado conductor:

- **"El reporte de conductor se siente pobre"**: el "Historial" de siempre (`Ride/Index.vue`, compartido con el cliente) era una lista plana de máximo 20 carreras, sin fecha, sin distancia, sin filtros ni nada agregado. Pidió trazabilidad completa, filtros, tarjetas de indicadores, segmentación con gráficos de barras/torta, totales, y gamificación — su meta de puntos y qué logra en la próxima medalla. Se armó una pantalla nueva, **"Mis indicadores"** (`Driver/Stats.vue`, `DriverStatsController`, ruta `rides.stats`, link desde "Carreras" solo para conductor) — `Ride/Index.vue` no se tocó, sigue igual para la gestión en vivo de ambos roles.
  - Filtros por rango de fechas y estado; tarjetas (carreras, completadas, canceladas, ganado, distancia, calificación); una **dona** completadas-vs-canceladas (a propósito sin el filtro de estado, si no quedaría de una sola porción) y **barras** de ganancia por día — ninguna con librería (no hay ninguna instalada en el proyecto): dona con círculos SVG concéntricos y `stroke-dasharray`, barras con `<div>` de alto proporcional, mismo criterio que ya usaba `Admin/Operations.vue`. Nuevos `Components/charts/{DonutChart,BarChart}.vue`, reutilizables.
  - Gamificación: medalla vigente + puntos + cuánto falta para la próxima (`DriverTier::nextAfter()`, nuevo, generaliza el cálculo que ya existía acoplado a "próxima medalla pública" en `Driver/Profile.vue`) con barra de progreso.
  - Historial paginado de a 20, con los mismos filtros, ahora con fecha, origen→destino, distancia, forma de pago, estado y puntos ganados por carrera.
- **"La guía de bienvenida siempre está en el mismo lugar"**: le gustó el recorrido guiado, pero quería que cada paso apareciera en la pantalla real de esa acción. Se evaluó y no conviene: hoy hay tres listas de accesos parcialmente distintas (grilla de escritorio, bottom sheet de móvil, tarjetas del Dashboard) e Inertia recarga la página completa en cada navegación — perdería el estado del tour. Se tomó la alternativa que el propio usuario ofreció: un ícono **"?"** en cada módulo de "Accesos rápidos" (grilla de escritorio y bottom sheet de móvil) que explica qué hace y con qué otro se relaciona, sin moverse de donde ya está. Nuevo `Components/HelpTip.vue` (mismo mecanismo de abrir/cerrar que `Dropdown.vue`, pero autocontenido — anidar un `Dropdown` dentro de otro, que es donde vive la grilla de escritorio, hubiera peleado por overlay y z-index). La guía automática de una sola vez queda intacta, tal como estaba.

### Tests
`tests/Feature/DriverStatsControllerTest.php` (nuevo, 8 casos: solo suma las carreras de ese conductor, filtro de fecha, la torta ignora el filtro de estado, medalla vigente/siguiente, sin siguiente en la medalla más alta, paginado de a 20, calificación promedio real). `tests/Unit/DriverTierTest.php` (+2: `nextAfter()` devuelve la siguiente medalla y `null` en la más alta). Suite completa: 484 tests OK, Pint limpio, build limpio.

### Bug propio: las alertas de confirmación no quedaban centradas ni siempre por encima de todo
Reportado por el usuario con una captura: la alerta ("¿Seguro que quiere sacar a este conductor de su flota?") aparecía pegada arriba de la pantalla en vez de centrada. Dos causas reales en `Components/Modal.vue` (de donde cuelgan `ConfirmDialogHost` —todas las alertas de confirmación de la app—, el panel de Ayuda y la guía de bienvenida, así que arreglarlo ahí lo arregla para todos a la vez):

- **Sin centrado vertical real**: el contenido solo tenía `mb-6` (margen abajo) y centrado horizontal — nunca centrado vertical. Con la página scrolleada, la alerta quedaba donde el flujo del documento la dejara, no en el medio de lo que se ve. Ahora usa el patrón robusto de "`flex items-center` sobre un contenedor `min-h-full`" (evita el corte de contenido que da centrar así en un modal más alto que la pantalla).
- **`z-50`, por debajo de los controles de Leaflet**: los controles propios de Leaflet llegan hasta z-index 1000 (mismo caso ya resuelto antes en `AddressAutocomplete.vue`, con un comentario explícito al respecto) — con una alerta abierta sobre una pantalla con mapa, el mapa le podía ganar. Subido a `z-[1600]`, por encima de cualquier otra capa conocida de la app.

### Tests
Cambio visual puro en un componente compartido, sin lógica de negocio de por medio — se corrió la suite completa para confirmar que nada más lo usa de una forma que dependiera de la estructura anterior. 484 tests OK, build limpio.

### Bug propio: recuadro vacío en el detalle de una carrera completada
Reportado por el usuario con una captura: un recuadro con fondo y sombra, sin nada adentro, entre el desglose de precio y "Calificar". Causa: `Ride/Show.vue` tiene una tarjeta de "acciones" (iniciar viaje, marcar completada, cancelar) cuyo contenido es todo condicional según `ride.status` — para una carrera ya **completada** ninguna de esas acciones aplica, así que la tarjeta se seguía dibujando pero sin ningún caso que le tocara mostrar algo. Ya existía un mensaje para "cancelada" ahí mismo; se agregó el que faltaba, "✅ Carrera completada [fecha]", con el mismo criterio.

### Tests
Cambio visual puro (un `<p v-if>` de más en una plantilla ya existente). Build limpio.

### Bug propio: la lista de planes de conductor no decía cuál incluía Viajes VAN
Reportado por el usuario: "Viajes VAN" avisa que hace falta "un plan superior", pero ningún plan de la lista (`/mi-plan` conductor) decía cuál lo incluye. Causa: `SubscriptionPlan.van_trips_enabled` (y `express_enabled`) ya viajaban al frontend desde siempre (`MyPlanController` manda el modelo completo) — la lista de características de cada plan en `Plan/Driver.vue` simplemente no los mostraba, a diferencia de `public_visibility`/`priority_listing`/`verified_badge`, que sí. Se agregaron los dos `<span v-if>` que faltaban, mismo criterio que los demás.

### Tests
Cambio visual puro (dato que ya llegaba del backend, solo faltaba mostrarlo). Build limpio.

### Promociones de precio por tiempo limitado en los planes
Pedido explícito del usuario: poder "regalar o promocionar" un plan por un tiempo determinado (ej. un mes gratis), que se vea "pagá tanto y ahorrá tanto — después de tal fecha pagarías el valor real", y que se oculte sola a quien ya la usó. Se confirmaron 3 puntos con el usuario antes de construir (`AskUserQuestion`): descuento como **precio fijo** (no %), un precio en **$0 se activa solo** (mismo criterio que ya existía para un plan gratis de catálogo), y la elegibilidad es **automática** (se oculta sola a quien ya la usó, sin elegir usuarios a mano).

No existía nada de esto: los planes no tenían ningún campo de descuento, y una suscripción nunca guarda el precio pagado (siempre se asume el de catálogo). El precedente de "vigente entre tal fecha y tal fecha" más cercano era `AdBanner` (para publicidad externa) — se siguió el mismo patrón (`starts_at`/`ends_at` + `scopeVisible()`), sin reutilizar el modelo.

- **`PlanPromotion`** (nuevo, tabla `plan_promotions`): plan, etiqueta libre, precio promocional, vigencia por fecha, activa/inactiva. `isEligibleFor($user)` se oculta sola si ese usuario ya tiene un pedido de esa promo en curso o aprobado (uno rechazado no cuenta, puede reintentar).
- **`subscription_requests` ganó `plan_promotion_id`** (nullable): necesario para que la elegibilidad tenga algo que consultar — incluso el camino de activación gratis, que hoy no dejaba ningún registro, ahora deja uno igual (solo cuando hay promo de por medio, un plan gratis de catálogo de siempre sigue sin dejar rastro).
- **`SubscriptionRequestController::store()`**: generaliza el atajo "$0 se activa sin comprobante" al precio EFECTIVO (de promo o de lista), revalidando siempre contra lo que hay en la base, nunca contra lo que mostró el navegador.
- **`MyPlanController`**: cada plan del catálogo (conductor y cliente) trae `active_promotion` ya resuelta y validada.
- **`Admin\PlanPromotionController`** + **`Admin/PlanPromotions.vue`** (nuevas, `/admin/promociones`): mismo esqueleto que el mantenimiento de banners, sin imagen. El precio promocional tiene que ser menor al de lista, si no se rechaza. El admin, al revisar un comprobante con promo de por medio, ve qué monto correspondía (`Admin/Subscriptions.vue`).
- **`Plan/Driver.vue` y `Plan/Client.vue`**: recuadro destacado con la promo vigente ("pagá $X y ahorrá $Y... válido hasta tal fecha, después $Z/mes"), precio de lista tachado, y "Elegir" manda la promo junto con el plan.

### Tests
`tests/Feature/Plan/SubscriptionRequestFlowTest.php` (+7: promo gratis auto-activa y queda registrada, no se puede usar dos veces, promo paga crea el pedido esperando comprobante, una vencida se rechaza, una de otro plan se rechaza, el catálogo expone/oculta `active_promotion` según corresponda). `tests/Feature/Admin/AdminPlanPromotionMaintenanceTest.php` (nuevo, 5 casos: solo admin, CRUD completo, precio promocional no puede ser mayor o igual al de lista). Suite completa: 496 tests OK, Pint limpio, build limpio.

- Paleta oscura + verde (sección 9.9), design tokens en `tailwind.config.js` (`bg-arka-*`, `text-arka-*`, `rounded-arka`).
- Barra de navegación inferior en móvil con botón central flotante (FAB) que abre un **bottom sheet** (modal que sube desde abajo, no lateral ni centrado — preferencia de diseño confirmada con el usuario) con accesos rápidos — es el único lugar donde vive ese listado en móvil, no se repite en ningún otro menú.
- Header (escritorio y móvil) con búsqueda, ayuda, grilla de accesos rápidos (solo escritorio) y avatar de cuenta acotado a lo estrictamente personal — ver "Ajuste transversal: diseño del header" arriba.
- Logotipo tipográfico de dos colores (`Components/ApplicationLogo.vue`), sin ícono genérico.
- Pantallas de sesión con panel de marca reutilizable (`Components/AuthBrandingPanel.vue`) en escritorio.
- Componentes reutilizables entre las tres experiencias (cliente/conductor/futuro admin): `Components/{PrimaryButton,SecondaryButton,DangerButton,RatingStars,FleetMap,BottomSheet,Modal}.vue`.

### Manuales de uso + refuerzo de Términos y Privacidad
Pedido explícito del usuario: manuales de uso de cliente y de conductor como material base para hacer gráficos de promoción ("que no se te pase nada"), y de paso reforzar las políticas legales con la postura de que **Arka01 es únicamente un prestador de servicios de software** ("no quiero ser responsable de nadie"), más el detalle completo de privacidad de datos: dónde se alojan, por cuánto tiempo, qué se hace con ellos, si se comparten con empresas aliadas, y de qué manera se usan.

- **`Manual_Cliente.md`** y **`Manual_Conductor.md`** (nuevos, raíz del proyecto): guías completas en Markdown, un capítulo por función real de la app (registro, login múltiple, onboarding, armar flota/pedir carrera/Expresos/Viajes en VAN según el rol, seguridad SOS, planes y promociones, perfil, notificaciones, PWA, sesión única) más preguntas frecuentes — recorridas contra el historial completo de este documento para no dejar ninguna función construida sin mencionar.
- **`Legal/Terms.vue`**: sección 1 ampliada (Arka01 no es agencia de viajes ni operador turístico, no fija tarifas ni supervisa el viaje, "es un prestador de servicios de software, nada más"); sección 9 nueva "Servicios de terceros integrados" (WhatsApp/Meta, Google, OpenStreetMap/OSRM, correo — Arka01 no responde por sus fallas; las promociones de empresas aliadas son responsabilidad de cada aliado, Arka01 solo las exhibe); sección de limitación de responsabilidad (ahora 10) reforzada en el mismo sentido. Resto de secciones renumeradas (11 a 15).
- **`Legal/Privacy.vue`**: reescrita con base legal explícita (Ley Orgánica de Protección de Datos Personales del Ecuador — LOPDP) y responsable del tratamiento identificado; sección "Con quién se comparte" ahora distingue con precisión tres grupos — la otra parte del viaje, los encargados de tratamiento reales (Meta/WhatsApp, Google, OSM/Nominatim, OSRM, proveedor de correo, cada uno con el dato mínimo que su función necesita) y las empresas aliadas de cupones/banners (que **no** reciben datos personales, solo se exhiben dentro de la app); sección nueva de transferencia internacional de datos; "Dónde se guardan" ahora incluye retención por tipo de dato (cuenta mientras esté activa, comprobantes de pago 7 años por obligación tributaria del SRI, sin rastreo histórico de ubicación fuera de un viaje en curso); sección nueva de seguridad de la información; sección de derechos ampliada a acceso/rectificación/cancelación/oposición/revocación (LOPDP) con mención de la Superintendencia de Protección de Datos Personales; sección nueva sobre menores de edad.
- El proveedor y la región exactos de hospedaje quedan señalados en el propio texto como un dato a confirmar antes de un despliegue a producción real (todavía no hay hosting de producción decidido en el proyecto) — mismo criterio que credenciales externas: se avisa qué falta completar, no se inventa.
- `routes/web.php`: `updatedAt` de ambas páginas legales actualizado a la fecha de esta revisión.

### Tests
Cambio de contenido/documentación, sin lógica de aplicación nueva — se verificó con `npm run build` (compila limpio, incluye `Terms-*.js` y `Privacy-*.js`). No aplica `php artisan test` ni Pint (no se tocó código PHP).

### Bug propio: editar un plan (o cupón, o motivo de calificación) no se veía hasta recargar
Reporte del usuario: en el catálogo de planes del panel admin, guardar una edición no reflejaba el cambio en la lista — había que recargar la página a mano para verlo.

Causa: `Admin/Plans.vue` armaba `driverPlans`/`clientPlans` con `props.plans.filter(...)` como una **constante plana**, calculada una sola vez cuando el componente se monta. Inertia sí actualiza `props.plans` después de guardar (sin recargar la página), pero esas dos listas ya habían quedado fijadas con los datos viejos — solo una recarga completa volvía a ejecutar el `filter` con datos frescos. Se corrigió envolviendo ambas en `computed()`, para que se recalculen solos cada vez que `props.plans` cambia.

Se encontró el mismo patrón (y se corrigió igual) en **`Admin/Coupons.vue`** (`clientCoupons`/`driverCoupons`) y **`Admin/RatingReasons.vue`** (`clientToDriver`/`driverToClient`) — mismo bug, mismo síntoma, no reportado todavía por el usuario en esas dos pantallas pero ya corregido de una vez. Se revisó el resto de pantallas admin en busca del mismo patrón (`props.x.filter/map/sort/slice/find` fuera de un `computed`) y no aparecieron más casos.

### Tests
`npm run build` compila limpio. No aplica `php artisan test` ni Pint (cambio de frontend, no de PHP). Verificación manual pendiente del lado del usuario: editar un plan/cupón/motivo y confirmar que el cambio se ve al instante, sin recargar.

### Bug propio: el pedido de plan no reflejaba el precio de la promoción, y proyección de ganancia mensual por plan
Reporte del usuario (con capturas): eligió el plan Plus con una promoción activa ("pague $7.00/mes y ahorre $8.00/mes"), pero la pantalla de "Pedido de plan" le pedía transferir $15.00 (el precio de lista) — sin ninguna mención de la promo, sin coherencia con lo que mostraba el catálogo un renglón más abajo.

Causa: `MyPlanController::pendingRequestFor()` cargaba la relación `plan` pero nunca `planPromotion` — el pedido sí guardaba `plan_promotion_id` desde que se creó (ver la pasada de Promociones), pero el frontend no tenía cómo enterarse. `SubscriptionRequestPanel.vue` siempre mostraba `pendingRequest.plan.monthly_price`, ciego a la promo. Se corrigió el eager load y se agregó al panel el mismo recuadro visual (`🎁`, fondo lima) que ya usan `Plan/Driver.vue`/`Plan/Client.vue`, más un `effectivePrice()` que calcula el monto a transferir a partir de la promoción cuando corresponde.

Segundo pedido, en el mismo mensaje: "indiquemos en cada plan las carreras estimadas y un estimado a ganar mensualmente... en el básico... 150 carreras mensuales... 450 por ser un ticket de 3 por carrera" — y que ese texto sea mantenible sin volver a tocar código.

- **`subscription_plans` gana `estimated_monthly_rides`** (nullable, solo aplica a planes de conductor): editable desde `/admin/planes`. Valores iniciales sembrados en la propia migración, coherentes con la conversación sobre precios (Institucional ≈ 400 carreras/mes, comparable a un conductor de alto volumen tipo Uber) y con el ejemplo del usuario (Básico = 150): gratis 60, básico 150, plus 220, pro 300, institucional 400.
- **`pricing_settings` gana `average_ticket_price`** (default $3.00, el mismo valor del ejemplo del usuario): editable desde `/admin/tarifas`, junto al resto de parámetros del cálculo de precio sugerido.
- **`MyPlanController::attachEarningsProjection()`** (nuevo, solo en `driver()`): `carreras × ticket = ganancia estimada`, resuelto en el backend y adjuntado a cada plan como `earnings_projection` — igual patrón que `active_promotion`.
- **`Plan/Driver.vue`**: nueva línea "📊 Proyección: ~150 carreras/mes ≈ $450.00/mes (ticket promedio $3.00/carrera)" bajo cada plan que tenga el dato cargado.
- **`Admin/Plans.vue`**: campo "Carreras estimadas por mes" en los formularios de crear/editar (solo conductor), con vista previa en vivo de a cuánto se traduce en dólares mientras se escribe, y el mismo dato resumido en el renglón colapsado de cada plan.
- **`Admin/Pricing.vue`**: campo "Ticket promedio por carrera (USD)" con la explicación de para qué se usa.

### Tests
`tests/Feature/Admin/AdminPlanMaintenanceTest.php` (+1: admin guarda `estimated_monthly_rides`). `tests/Feature/Admin/AdminPricingMaintenanceTest.php` (test existente ajustado: ahora manda y verifica también `average_ticket_price`, campo requerido nuevo). `tests/Feature/Plan/SubscriptionRequestFlowTest.php` (+2: el pedido pendiente expone la promo con la que se creó; el catálogo de conductor expone `earnings_projection` calculada). Suite completa: 499 tests OK, Pint limpio, build limpio.

### Foto del cliente en la notificación de carrera entrante
Pedido explícito del usuario (con una captura de referencia, tipo tarjeta de app de delivery): que la solicitud que le llega al conductor muestre la foto del cliente, no solo el nombre.

`App\Events\RideRequested::broadcastWith()` no mandaba ningún dato de foto — se agregó `client_avatar_url` (mismo accesor `User::getAvatarUrlAttribute()` que ya usa `<UserAvatar>` en el resto de la app: login con Google trae la foto de la cuenta, y si no hay ninguna cae a `null`). `IncomingRideRequestModal.vue` ahora usa `<UserAvatar>` junto al nombre del cliente y su calificación — mismo componente reutilizable de siempre, con su respaldo automático a iniciales si no hay foto (nunca una imagen rota). No fue necesario tocar `Utils/incomingRideRequest.js`: reenvía el payload del broadcast tal cual llega.

### Tests
No aplica un test nuevo (cambio de un campo más en un payload de broadcast ya cubierto por `SequentialDispatchTest`/`DriverCoverageRangeTest`, que verifican el evento se despacha, no la forma exacta del array). Suite completa: 499 tests OK, Pint limpio, build limpio.

### Ajuste: origen/destino más legibles en la notificación de carrera entrante
Feedback del usuario sobre la pasada anterior (con captura): la foto quedó bien, pero origen y destino iban los dos pegados en una sola línea con "→" y se veían amontonados al hacer *wrap* con direcciones largas. También preguntó por qué ya no aparecía "el tiempo" — aclarado que no se tocó: el aviso de segundos para responder es condicional y solo aplica a una solicitud ofrecida a toda la flota (despacho secuencial), no a una dirigida a un conductor puntual como la de su captura — comportamiento de antes de esta pasada, no una regresión.

`IncomingRideRequestModal.vue`: origen y destino ahora van cada uno en su propia fila (sector en negrita, dirección completa debajo en gris), conectados por una línea vertical con un punto verde (origen) y uno rojo (destino) — mismo lenguaje de color que ya usa el mapa para distinguir los dos puntos.

### Tests
`npm run build` compila limpio, Pint limpio (solo cambio visual de plantilla, sin lógica nueva).

### Diagnóstico: un conductor con el switch prendido aparecía "Desconectado" para su cliente
Reporte del usuario (con dos capturas: el Inicio de Luis mostrando "● Disponible", y el Inicio de Gabriela mostrando a Luis "Desconectado" en su flota). Se investigó con datos reales del entorno local (no se pudo reproducir a ciegas, así que se consultó la base): `driver_profiles` de Luis tenía `is_available = true` pero `location_updated_at` de hacía 12 minutos — más de los 2 minutos que exige `DriverProfile::isStale()`, y sin ventana de WhatsApp abierta como respaldo. No es un bug de sincronización: es el comportamiento ya existente de `DriverProfile::isReachable()` (agregado en una pasada anterior para que un conductor sin ping de ubicación reciente no siga recibiendo carreras como si estuviera activo) funcionando como se diseñó — pero el propio conductor no tenía forma de enterarse de que, aunque él se ve "Disponible", ya dejó de ser alcanzable para sus clientes.

Causa de fondo: `is_available` (la intención — prendió el switch) y `is_reachable` (además, un ping de ubicación reciente o WhatsApp abierto) son dos cosas distintas en el modelo, pero el Inicio del conductor solo mostraba la primera.

- **`DashboardController::index()`**: `driverStats` suma `is_reachable`, mismo cálculo (`DriverProfile::isReachable()`) que ya usa el roster de "Mi flota" del cliente.
- **`Dashboard.vue`** (vista de conductor): si está disponible pero no alcanzable, un aviso amarillo bajo el badge "● Disponible": "Sin ubicación reciente — sus clientes pueden seguir viéndolo desconectado. Revise que el navegador tenga permiso de ubicación y que la app siga abierta."

No se tocó el criterio de `isReachable()` en sí (sigue siendo la fuente de verdad para el despacho de carreras) — este ajuste es solo de visibilidad, para que el conductor entienda su propio estado real en vez de confiar ciegamente en un switch que no refleja si sigue mandando ubicación.

### Tests
`tests/Feature/DashboardTest.php` (+2: `driverStats.is_reachable` en `false` con ubicación vieja, en `true` con ubicación fresca). Suite completa: 501 tests OK, Pint limpio, build limpio.

### El umbral de inactividad del conductor ahora se ajusta desde el panel admin
Pregunta directa del usuario, en el mismo hilo del diagnóstico de Luis: "¿ese tiempo de inactividad lo puedo subir desde el panel de administrador?". No se podía — `DriverProfile::STALE_AFTER_MINUTES` era una constante fija en el código (2 minutos), usada tanto por `isStale()`/`isReachable()` (tiempo real, en toda la app) como por el barrido automático `drivers:sweep-stale-availability`.

- **`pricing_settings` gana `driver_stale_after_minutes`** (default 2, mismo valor de siempre — no cambia nada para quien no lo toque), en la misma tabla singleton que ya aloja `minimum_fare`/`average_ticket_price`/recargo nocturno.
- **`DriverProfile`**: la constante `STALE_AFTER_MINUTES` se reemplaza por `staleAfterMinutes(): int` (lee `PricingSetting::current()`), única fuente de verdad para `isStale()`.
- **`SweepStaleDriverAvailability`**: usa el mismo `staleAfterMinutes()` en vez de la constante — el barrido en sí sigue corriendo cada 2 minutos (frecuencia del cron, no el umbral), eso no cambió.
- **`Admin/Pricing.vue`**: campo nuevo "Minutos sin ubicación antes de marcar a un conductor desconectado", con la aclaración de que el barrido automático sigue corriendo cada 2 min sin importar el valor (si lo bajan por debajo de eso, ESE comando puntual puede tardar hasta 2 min en aplicarlo — el resto de la app lo aplica al instante).

### Tests
`tests/Feature/Admin/AdminPricingMaintenanceTest.php` (+1: un umbral de 10 min hace que un conductor con 7 min sin ping ya no cuente como stale). Suite completa: 502 tests OK, Pint limpio, build limpio.

### Ajuste: el link a Google Maps no arrancaba la navegación
Reporte del usuario (con captura): "Ir a buscar al cliente"/"Llevar al destino" (Ride/Show.vue, sección 8/9.3) abrían Google Maps, pero solo en la pantalla de elegir modo de viaje y previsualizar la ruta — el conductor tenía que buscar el botón para arrancar la navegación él mismo, en vez de arrancar manejando de una.

El link armaba la URL a mano con un solo parámetro (`destination`). Se agregó `travelmode=driving` (para que no arranque en otro modo — a pie, transporte público, como se veía en la captura) y `dir_action=navigate`, parámetro documentado de la API de URLs de Google Maps que hace que la app (en el celular) arranque derecho en navegación turn-by-turn en vez de la pantalla de previsualización. El origen se sigue dejando sin especificar a propósito — Google Maps lo resuelve solo con el GPS del dispositivo, que es lo que se necesita (el conductor se está moviendo).

### Tests
Cambio de una URL armada en el frontend, sin lógica de backend — se verificó con `npm run build` (compila limpio). No aplica `php artisan test` ni Pint.

### Primer despliegue a Google Cloud Platform (piloto económico)
Pedido explícito del usuario: ya tiene cuenta, proyecto y facturación de GCP con $300 de crédito por 86 días, y pidió ayuda para el primer despliegue "económico y eficiente para comenzar". Se armó un plan (revisado con el usuario antes de tocar nada, con 3 decisiones confirmadas por `AskUserQuestion`: región `us-central1` — la más barata, priorizando costo sobre latencia a Ecuador —, VM `e2-small` de 2GB en vez de `e2-micro`, y despliegue por `git pull` porque el repo ya está en GitHub/GitLab).

**Arquitectura elegida: una sola VM Compute Engine ("todo en una caja")**, no Cloud Run/serverless — la app usa discos de archivos LOCALES de Laravel (licencias de conductor, comprobantes de pago en el disco `local` privado; fotos de vehículo/avatares en `public`), no S3/GCS. Migrar eso a Cloud Storage habría exigido reescribir el filesystem de la app + sumar Cloud SQL + Memorystore — mucho más caro y complejo para arrancar un piloto. Una VM con disco persistente no exige tocar una sola línea de código de la app.

Importante: yo (Claude Code) no tengo `gcloud` ni credenciales de la cuenta de GCP del usuario en este entorno — no aprovisioné nada yo mismo. Armé todos los archivos/scripts, y el usuario corre los comandos reales desde Google Cloud Shell (cero instalación) y por SSH en el servidor. Tampoco corrí ningún comando `git` — los `git clone`/`git pull` de los scripts los ejecuta él en el servidor.

Archivos nuevos en `deploy/` (los 2 `.conf` de Supervisor ya existían de una pasada anterior y se reutilizan sin cambios):
- **`gcloud-provision.sh`**: crea la VM (`e2-small`, Ubuntu 22.04 LTS, `us-central1`, disco `pd-balanced` 30GB), IP estática, firewall (80/443 al mundo; SSH restringido al rango de IAP en vez de abierto), bucket Nearline para backups, y snapshot diario automático del disco completo.
- **`bootstrap-server.sh`**: instala Nginx, PHP 8.3-FPM + extensiones (Ubuntu 22.04 trae PHP 8.1 por defecto, Laravel 12 exige `^8.2` — se suma el PPA `ondrej/php`), MySQL (Ubuntu sí trae MySQL real; en Debian 12 el paquete `mysql-server` en realidad instala MariaDB, por eso se eligió Ubuntu), Composer, Node 20 (solo para compilar assets — `public/build` está en `.gitignore`), Supervisor, Certbot. Ajusta `php.ini`, hace tuning de MySQL (`innodb_buffer_pool_size=256M`, para no quedarse sin RAM junto a PHP-FPM+Reverb en 2GB) y crea un swapfile de 1GB de respaldo.
- **`nginx-arka01.conf`**: server block para Laravel + un `location` que reversa-proxea Reverb (`/app/...` WebSocket del cliente, `/apps/...` API que el propio backend usa para publicar eventos) a `127.0.0.1:8080` — el puerto real de Reverb nunca queda expuesto directo, todo pasa por el 443 de Nginx/Certbot.
- **`.env.production.example`**: plantilla lista con lo que cambia respecto a `.env.example` (`APP_ENV=production`, `APP_DEBUG=false`, `BROADCAST_CONNECTION=reverb`, `SESSION_SECURE_COOKIE=true`, dominio real, `REVERB_HOST/PORT/SCHEME` públicos vs. `REVERB_SERVER_HOST/PORT` internos), y comentarios `[COMPLETAR]` marcando cada secreto real que hace falta generar.
- **`deploy.sh`**: script corto para actualizaciones futuras (`git pull` → `composer install` → `npm run build` → `migrate --force` → cachear → recargar PHP-FPM/Supervisor).
- **`backup-mysql.sh`**: `mysqldump` diario comprimido a Cloud Storage, complementa el snapshot de disco (más rápido de restaurar solo-la-base).
- **`README.md`**: reescrito como guía única, paso a paso y en orden, de "cuenta de GCP recién creada" a "app funcionando en `https://arka01.com`", con la sección de verificación y la checklist de variables opcionales vs. recomendables antes de invitar usuarios reales.

Costo estimado: ~$18-22/mes (VM + disco + snapshots + backups + egress) — en los 86 días del crédito, ronda los $52-63 de los $300, dejando bastante margen.

### Tests
No aplica `php artisan test`/Pint/`npm run build` (son scripts de infraestructura, no código de la app) — se verificó la sintaxis de los 4 scripts `.sh` con `bash -n` (sin errores). La verificación real (que el servidor levante, que Reverb conecte, que una carrera de prueba funcione de punta a punta) queda documentada paso a paso en `deploy/README.md` para que la corra el usuario, ya que no hay una VM real disponible en este entorno para probarlo de antemano.

### Arka01 en producción: `https://arka01.com` está en vivo
Continuación directa de la pasada anterior — con el SDK instalado y autenticado, se ejecutó el plan completo en la infraestructura real del usuario (no solo se dejaron los scripts listos). Dos hallazgos reales en el camino:

- **Cloud Shell no estaba disponible** para la cuenta del usuario ("cuenta de Google Workspace no apta"), así que se instaló el Google Cloud SDK local (`winget install Google.CloudSDK`) en su Windows — mismo `gcloud`, misma sesión autenticada (`gcloud init`, corrido por el usuario por el login interactivo), pero corriendo desde su propia máquina en vez del navegador. Confirmado que funciona igual: aprovisionó la VM, el bucket, el snapshot y el resto sin diferencias.
- **`composer.lock` pedía PHP 8.4+** (se había generado con el PHP 8.5 del entorno local), pero el servidor tenía 8.3 instalado según `composer.json` (`^8.2`) — `composer install` fallaba. Se instaló PHP 8.4 en el servidor en vez de tocar el lock file (evita arriesgar versiones de paquetes ya probadas con la suite completa), y se corrigieron `deploy/bootstrap-server.sh`, `deploy/deploy.sh` y `deploy/nginx-arka01.conf` para que apunten a 8.4 de manera consistente en cualquier despliegue futuro.

Importante sobre los límites que se respetaron: en ningún momento se corrió un comando `git` desde acá — ni local ni en el servidor remoto por SSH. El `git clone` y los dos `git pull` (uno normal, otro después del fix de PHP) los ejecutó el usuario a pedido; todo lo demás (aprovisionar, instalar paquetes, `.env`, Composer, `npm run build`, migraciones, Nginx, Supervisor, Certbot, backup) se corrió directo por SSH con `gcloud compute ssh --tunnel-through-iap`.

Estado final verificado en vivo:
- VM `arka01-vm` (`e2-small`, `us-central1-a`), IP estática `35.254.73.121`, DNS de `arka01.com`/`www.arka01.com` propagado y apuntando ahí.
- `https://arka01.com` responde `200`, con certificado válido (Let's Encrypt, vence 2026-11-08, renovación automática ya programada por Certbot) y todos los headers de `SecurityHeaders.php` presentes.
- Los 74 migraciones corrieron limpias sobre la base `arka01` nueva.
- `arka01-queue-worker:00/01` y `arka01-reverb` en `RUNNING` bajo Supervisor; se confirmó el WebSocket de punta a punta con un handshake real (`101 Switching Protocols`, header `X-Powered-By: Laravel Reverb`) a través del proxy de Nginx.
- Cron del scheduler instalado (`* * * * *`).
- Backup diario de MySQL a Cloud Storage instalado, corrido una vez a mano para confirmar que funciona (subió 16KB — base recién migrada, sin datos de demo todavía).
- Bloqueo de `.env`/`.git` por URL confirmado andando solo (los primeros bots ya empezaron a escanear la IP a los pocos minutos, todos devueltos con 403).

Pendiente, deliberadamente fuera de esta pasada (documentado en `deploy/.env.production.example` y `deploy/README.md`): completar las variables opcionales antes de invitar gente real (WhatsApp App Secret, Google OAuth, Maps, Sentry, VAPID, SMTP real) y decidir si se siembra algo de catálogo/demo o se arranca en blanco. También quedó un aviso de "reinicio de kernel pendiente" del propio Ubuntu (normal tras instalar paquetes) — no urgente, se puede reiniciar la VM en cualquier momento sin perder nada (todo corre bajo Supervisor/systemd, vuelve solo).

### Tests
No aplica — despliegue real de infraestructura, no cambios de código. Verificación end-to-end hecha en vivo contra el servidor real: `curl -I https://arka01.com` (200 + headers), handshake WSS real contra Reverb, `sudo supervisorctl status` (los 3 procesos `RUNNING`), backup de prueba confirmado en el bucket.

### Post-despliegue: permisos, y por qué completar el `.env` no alcanzaba solo
Tres problemas reales encontrados por el usuario ya con la app en producción, completando las variables opcionales una por una:

- **Permisos de `storage/`/`bootstrap/cache` mal pensados**: quedaron con dueño `www-data:www-data` — el propio usuario (su cuenta SSH) no podía correr `artisan` sin `sudo` (`php artisan config:cache` tiraba "Permission denied"). Se corrigió a dueño compartido (`$(whoami):www-data`, grupo con permiso de escritura) tanto en el servidor real como en `deploy/README.md`, y se reordenó la guía para fijar permisos ANTES de los comandos `artisan` que ya escriben ahí (antes quedaban después, mismo problema en el primer intento).
- **`config:cache` no alcanzaba para que tomaran efecto las credenciales nuevas** (Google, WhatsApp): la causa real era `opcache.validate_timestamps=0` (activado a propósito por rendimiento en `bootstrap-server.sh`) — PHP-FPM sigue sirviendo desde memoria la versión VIEJA de `bootstrap/cache/config.php` aunque el archivo en disco ya se haya reescrito, hasta que se reinicia el proceso. Hace falta `sudo systemctl reload php8.4-fpm` después de cada `config:cache` para que se note.
- **El autocompletado de Google Maps no aparecía ni con eso**: `VITE_GOOGLE_MAPS_API_KEY` se "hornea" en el JavaScript compilado en build time (`npm run build`), no se lee en runtime como el resto de las variables — cambiar el `.env` nunca iba a alcanzar sin recompilar. Se corrió `npm run build` de nuevo con la clave ya puesta; confirmado que quedó grabada en el chunk `AddressAutocomplete-*.js`.

Se agregó la secuencia correcta a `deploy/README.md` para que no se repita: **cualquier cambio de variable no-`VITE_`** → `config:cache` + `systemctl reload php8.4-fpm`; **cualquier variable `VITE_...`** → además, `npm run build` antes de esos dos pasos.

### Tests
No aplica — mismo motivo que la pasada anterior. Verificado en vivo: `googleLoginEnabled":true` en el HTML servido de `/login`, `config('services.whatsapp.business_number')` resuelto en `tinker` después del reload, y la clave de Google Maps confirmada dentro del build con `grep`.

### Fix: el CSP bloqueaba el autocompletado de Google Places en producción
Reportado por el usuario con la consola del navegador: `AddressAutocomplete` cargaba el script bien, pero las sugerencias nunca volvían — `RpcError: Rpc failed due to xhr error`, con el CSP marcando explícitamente que bloqueó la conexión a `https://places.googleapis.com/$rpc/google.maps.places.v1.Places/AutocompletePlaces`.

Causa: la API "nueva" de Places (`Utils/googleMaps.js`) manda el autocompletado por gRPC-Web a `places.googleapis.com` — un origen DISTINTO al que usa el resto de Maps (`maps.googleapis.com`, el único permitido en `connect-src`). `App\Http\Middleware\SecurityHeaders` solo contemplaba el segundo. Se agregó `https://places.googleapis.com` a `connect-src`.

### Tests
`php artisan test` (502 tests OK, sin tests que dependan del string exacto del CSP), Pint limpio. Verificado en vivo con `curl -sI https://arka01.com` mostrando el header actualizado, después de `git pull` + `sudo systemctl reload php8.4-fpm` (código nuevo, mismo problema de opcache que las demás pasadas — un simple `git pull` no alcanza sin reiniciar PHP-FPM).

### Tanda de 3 pedidos post-despliegue: notificación de plan activado, logs visibles, y reconexión automática de ubicación
Mensaje del usuario con tres pedidos concretos, ya con el piloto en vivo:

- **"Activé un plan a un cliente y no le llegó la notificación"**: no era un fallo — la notificación directamente no existía. `SubscriptionActivator::activate()` (único lugar donde se activa un plan, sea por el admin a mano, por aprobar un comprobante, o por auto-activación de un plan/promo en $0 — los 3 casos confirmados con `grep`) nunca avisaba a nadie. **`App\Notifications\PlanActivatedPushNotification`** (nueva, mismo patrón que el resto de las push — `RideCancelledPushNotification`, etc.) se dispara ahí, fuera de la transacción de base de datos a propósito (un push que fallara no tiene por qué revertir la activación real).
- **"Quiero que se activen los logs en producción para ver qué está pasando"**: `LOG_LEVEL=error` (puesto en la pasada de preparación para el despliegue) silenciaba todos los `Log::info()`/`Log::warning()` que la app ya usa a propósito para diagnóstico de negocio (activaciones, avisos de WhatsApp, etc.). Se subió a `LOG_LEVEL=info` en `deploy/.env.production.example` — el canal `stack` ya rota solo por día con 14 días de retención (`config/logging.php`), así que no acumula sin límite.
- **"Un conductor que se va a segundo plano y recarga la página no aparece conectado — debería ser automático o al menos un botón"**: bug real de raíz, no un problema de umbral. `DriverAvailabilityToggle.vue` solo llamaba a `startWatching()` (el `navigator.geolocation.watchPosition()` que manda el ping cada ~15s) desde el manejador del click del switch — **nunca al montar el componente**. Un conductor que recargaba la página con `is_available=true` se quedaba con el switch prendido en pantalla, pero sin ningún ping nuevo saliendo del navegador, hasta que lo apagaba y prendía de nuevo a mano. Se agregó un `onMounted()` que retoma `startWatching()` solo si ya estaba disponible. Además, pedido explícito de un respaldo manual: botón "Actualizar ubicación ahora" en el aviso de "Sin ubicación reciente" del Inicio del conductor (`Dashboard.vue`), que fuerza un ping ya mismo vía una función nueva expuesta con `defineExpose({ refreshNow })`.

### Tests
`tests/Feature/Admin/AdminSubscriptionTest.php` (+1: activar un plan a mano notifica al usuario). `tests/Feature/Plan/SubscriptionRequestFlowTest.php` (+1 aserción sobre un test existente: el auto-activado de un plan gratis también notifica). Suite completa: 503 tests OK, Pint limpio, build limpio. El fix de `DriverAvailabilityToggle.vue`/`Dashboard.vue` es frontend puro, sin test automatizado (no hay suite de JS en este proyecto) — verificar a mano recargando el Inicio de un conductor "disponible" y confirmando que el ping de ubicación se retoma solo.

### Incidente en producción: login con Google devolvía 500 (y el fix real destapó un segundo bug)
Reportado por el usuario con captura: `/auth/google/callback` tiraba "500 Error del servidor" al intentar entrar con una cuenta que ya tenía sesión activa en otro dispositivo/navegador.

**Causa raíz, en dos capas:**
1. **El log de errores de hoy tenía el dueño/grupo mal** (`laravel-2026-08-10.log` había quedado `User:User` en vez de con grupo `www-data`, porque lo creó sin querer una sesión de `tinker` corrida por SSH antes que cualquier request de PHP-FPM). PHP-FPM (que corre como `www-data`) no podía escribir ahí — `Permission denied` — y esa falla de logging TAPABA el error real, dejando un 500 sin ningún rastro en ningún log. Se activó `APP_DEBUG=true` **momentáneamente** (el usuario marcó, con razón, que no había que dejarlo así en producción — se apagó apenas se vio el error real) para ver el error de verdad en pantalla, que resultó ser justamente `UnexpectedValueException` de Monolog por el permiso.
2. **Fix del permiso**: `chown` del log del día al dueño correcto, y — para que esto no vuelva a pasar con ningún archivo nuevo — se le puso el bit **setgid** (`chmod g+s`) a `storage/logs`, `storage/framework/*`, `storage/app`, `bootstrap/cache`: de ahora en más, cualquier archivo nuevo que se cree ahí (lo cree PHP-FPM o un comando corrido a mano por SSH) hereda el grupo `www-data` del directorio, sin importar qué usuario del sistema lo haya creado.

**Con el logging arreglado, apareció el bug real** (el que efectivamente causaba el 500 original, aunque ya estaba parcialmente resuelto por el propio `try/catch` de `GoogleAuthController` — el mensaje de "sesión activa" sí llegaba a mostrarse): el usuario recordó que el login con contraseña ofrece pedir un código por WhatsApp para cerrar la otra sesión, y ese atajo no aparecía viniendo de Google.

- Causa: `Auth/Login.vue` solo activaba el widget de "pedir código" mirando `form.errors.login` (el error de validación del formulario de contraseña) — el camino de Google nunca pasa por ahí, llega por un simple `session('status')` flasheado, un mecanismo distinto que el widget no revisaba.
- Aunque se hubiera revisado `status`, el widget tampoco iba a poder mandar nada: necesita saber A QUÉ CUENTA pedirle el código (`form.login`), y ese campo nunca se llegó a escribir viniendo de Google (a diferencia del camino de contraseña, donde el usuario ya lo tipeó).
- Fix: `GoogleAuthController` ahora flashea también `login_hint` (el correo) junto con `status`; `AuthenticatedSessionController::create()` lo pasa como prop nueva `loginHint`; `Login.vue` lo usa para pre-llenar `form.login`, y `showsSessionBlockedError` ahora revisa `status` además de `form.errors.login` — el widget aparece y funciona igual sin importar por cuál de los dos caminos se llegó al bloqueo.

### Tests
`tests/Feature/Auth/SingleActiveSessionTest.php` (+1, reutilizando el helper `fakeOtherDeviceSession()` ya existente — la técnica correcta para simular "otra sesión activa" en tests, insertando la fila directo en `sessions`; un primer intento con dos llamadas `get()` seguidas resultó frágil por la regeneración de sesión que hace `Auth::login()`, y se descartó). Suite completa: 504 tests OK, Pint limpio (reordenó imports en `AuthenticatedSessionController.php` solo), build limpio.

### Fix: avatar y nombre superpuestos en /admin/suscripciones
Reportado por el usuario con captura, en pantalla angosta: el avatar y el nombre del usuario se pisaban en la lista de "Usuarios y su plan vigente". Causa: la columna de nombre/correo (`Admin/Subscriptions.vue`) no tenía `min-w-0` — por defecto un hijo flex no se achica por debajo del ancho natural de su contenido, así que un nombre o correo largo empujaba todo el layout en vez de truncar. Se agregó `min-w-0` a los contenedores relevantes, `truncate` a nombre/correo, y el mismo patrón "apilar en mobile" (`flex-col sm:flex-row`) que ya usa el resto del panel admin para filas con acciones al costado.

### Tests
`npm run build` compila limpio. Cambio de clases de Tailwind únicamente, sin lógica — no aplica `php artisan test` ni Pint.

### Invitar por WhatsApp a un cliente desde "Mis clientes de confianza"
Pedido explícito del usuario: que el conductor pueda invitar por WhatsApp a un cliente para que lo sume a su flota.

Investigado antes de construir nada: la mayor parte ya existía. "Referí a tu conductor" (pasada anterior, del lado cliente) ya arma un link público por `invite_code` (`/referidos/{invite_code}`, `ReferralController`) donde cualquier cliente logueado puede sumar a ese conductor a su flota con un clic, o crear cuenta si no tiene una — el conductor ya tiene ese mismo `invite_code` (`Driver/Profile.vue` lo muestra como QR, `Dashboard.vue` deja copiarlo pelado). Lo único que faltaba era una forma cómoda de que el conductor comparta ESE link — hoy solo podía copiar el código suelto, sin el link ni un botón de WhatsApp.

- **`DriverInvitationController::index()`**: suma `inviteCode` (`driverProfile->invite_code`) a los props de "Mis clientes de confianza".
- **`Driver/Invitations.vue`**: nueva tarjeta "Invite a un cliente" arriba de todo, con los mismos dos botones que ya usa `Fleet/Show.vue` del lado cliente (`shareInviteByWhatsApp`/`shareInviteGeneric`) — mismo cuidado de no duplicar el link en el mensaje de WhatsApp (bug real de una pasada anterior), reutilizando `route('referrals.show', inviteCode)` en vez del link genérico de registro.

No hizo falta tocar `ReferralController`/`Referral/Show.vue` ni la lógica de invitación en sí — la invitación que se manda al aceptar en esa pantalla sigue pasando por el mismo `FleetInvitationController::store()` de siempre (el conductor igual tiene que aceptarla desde "Invitaciones recibidas", protección contra que alguien reenvíe el link sin que él se entere).

### Tests
`tests/Feature/Fleet/FleetInvitationFlowTest.php` (+1: la pantalla expone el `inviteCode` correcto del conductor logueado). Suite completa: 505 tests OK, Pint limpio, build limpio.

### SMTP en producción no mandaba correos, aunque el usuario ya había completado las credenciales
El usuario pegó directo los logs de producción (ya con `LOG_LEVEL=info` de la pasada anterior — se usaron tal cual, sin pedirle nada más) mostrando `stream_socket_client(): ... getaddrinfo for  failed` al mandar el aviso de sesión concurrente por correo. Dos problemas encadenados, diagnosticados mandando un correo de prueba real desde `tinker` (más rápido que pedirle reintentar una acción real cada vez):

- **`MAIL_ENCRYPTION=tls` con `MAIL_PORT=465`**: combinación inválida — 465 es SSL directo, `tls` (STARTTLS) es la pareja del puerto 587. Corregido a `MAIL_ENCRYPTION=ssl`.
- **`MAIL_FROM_ADDRESS=soporte@arka01.com` no existe como buzón real** en el servidor de correo (`mail.siglotecnologico.com`, de un dominio distinto) — el servidor rechazaba tanto usarlo de remitente ("Sender verify failed") como de destinatario de prueba ("No Such User Here"). El usuario eligió, para probar ya, usar el mismo buzón autenticado (`wpgo@siglotecnologico.com`) como remitente en vez de crear un buzón nuevo — queda pendiente, a su criterio, crear `soporte@arka01.com` de verdad más adelante para los correos reales a usuarios.

Confirmado con un envío real a un correo externo (Gmail) — llegó.

### Tests
No aplica — configuración de infraestructura (`.env`), no código. Verificado con un envío real desde `tinker` en el servidor.

### Recuperar sesión primero por WhatsApp, luego pedir el código
Pedido explícito del usuario: que el widget de sesión única invite a escribirle primero al WhatsApp oficial (sin que se note el mecanismo interno) — el bot confirma y recién ahí tiene sentido tocar "Pedir código" en la web, en vez de arriesgarse a que salga por correo porque la ventana de 24h todavía no estaba abierta.

- **`WhatsAppWebhookController`**: nueva frase disparadora ("recuperar mi sesión", comparada sin mayúsculas ni tilde) — si el mensaje entrante la contiene, `openWindowFor()` despacha `SendWhatsAppSessionRecoveryPrompt` en vez de la confirmación genérica de "activarme" del conductor (que no tenía sentido acá, ni para un cliente ni para un conductor que solo quería recuperar su sesión).
- **`WhatsAppFreeformSender::sendSessionRecoveryPrompt()`** (nuevo): "✅ ¡Listo! Ya puede volver a la página de inicio de sesión de Arka01 y tocar 'Pedir código'".
- **`Utils/whatsapp.js`**: `buildSessionRecoveryWhatsAppUrl()` arma el link `wa.me` con la frase exacta — a propósito SIN `(ref:ID)` como el de "activarme" (acá el usuario todavía no probó nada; exponer un ID filtraría si la cuenta existe, violando el mismo criterio de privacidad que ya usa `SessionTakeoverController::request()`).
- **`Auth/Login.vue`**: el widget ahora numera dos pasos — "1. Escríbanos por WhatsApp primero →" (opcional, si ya tenía la ventana abierta puede saltarlo) y "2. Pedir código". `AuthenticatedSessionController::create()` suma `whatsappBusinessNumber` para armar el link.

### Tests
`tests/Feature/WhatsApp/WhatsAppSessionTest.php` (+2: la frase dispara el job de recuperación y no el genérico; la comparación tolera falta de tilde/mayúsculas). Suite completa: 507 tests OK, Pint limpio (reformateó comillas en `WhatsAppFreeformSender.php`), build limpio.

### Tanda de mejoras de registro/login y de "Mis flotas"
Cinco pedidos en un mismo mensaje, del usuario probando la app ya en producción:

- **Google no preguntaba tipo de cuenta**: registrarse (o loguearse por primera vez) con Google armaba la cuenta directo como "cliente", sin el paso de elegir que sí tiene el registro normal (`Register.vue`, paso "account_type" — ni siquiera se persiste en `users`, la fuente de verdad real es si existe un `DriverProfile`). Investigado antes de tocar nada: `GoogleAuthController::callback()` solo distinguía "cuenta encontrada" vs "cuenta nueva" — nunca por si el click vino del botón de Login o de Register, así que no hacía falta duplicar nada por ahí.
  - **`Auth/ChooseAccountType.vue`** (nueva) + **`AccountTypeController`** + ruta `cuenta/tipo`: mismo diseño del paso 1 de `Register.vue` (dos tarjetas grandes), pero como pantalla propia — sin formulario propio, "Conductor" enlaza directo a `driver.profile.edit` (ya soporta "todavía no tengo perfil", lo usa el registro normal) y "Pasajero" a Inicio.
  - **`GoogleAuthController::callback()`**: agregado `$isNewUser` — solo una cuenta CREADA en este mismo request (ni por `google_id` ni por email) redirige a `cuenta/tipo`; una cuenta que ya existía (aunque recién se linkee con Google ahora) sigue directo a Inicio como siempre, porque ya tiene un rol elegido. Esto resuelve los dos pedidos del usuario a la vez (registro con Google Y login con Google a una cuenta inexistente), porque los dos casos pasan por la misma rama "cuenta nueva".
- **Ojito de mostrar/ocultar contraseña en el registro**: no existía (sí en `Login.vue`) — mismo ícono/comportamiento ahora en los dos campos de contraseña del paso 5, un solo toggle para ambos (no tiene sentido mostrar uno sí y el otro no cuando lo que se compara es que coincidan).
- **"Mis flotas" (`Fleet/List.vue`)**:
  - Botón nuevo **"+ Agregar conductores"** en cada fila de flota, al lado del nombre — antes la única entrada a esa función era tocar el nombre y encontrar el buscador ya adentro de `Fleet/Show.vue`.
  - El botón de crear flota ya no queda deshabilitado con un texto de ayuda al costado (fácil de pasar por alto) — ahora siempre está activo: si el plan no alcanza, lleva directo a `Mi plan` (`client.plan.edit`) en vez de mostrar el formulario.
- **Consistencia del flujo de WhatsApp del conductor** (a pedido del usuario, comparándolo con el widget de recuperar sesión que armamos antes): el aviso de `Dashboard.vue` ("le enviamos a WhatsApp para confirmar su turno") y la tarjeta de `Driver/Profile.vue` ahora usan el mismo lenguaje numerado "1. .../2. ..." en vez de una sola oración — no cambió el mecanismo (sigue siendo un solo click + confirmación automática del bot), solo la forma de explicarlo, para que se sienta el mismo patrón en toda la app.

### Tests
`tests/Feature/Auth/GoogleAuthTest.php` (renombrado y ajustado: una cuenta nueva ahora redirige a `account-type.choose`, no a `dashboard`; +1: esa pantalla responde `200` después de un alta nueva por Google). Suite completa: 508 tests OK, Pint limpio, build limpio (hizo falta recompilar para que el manifest de Vite conociera `ChooseAccountType.vue` antes de que los tests de Inertia pudieran renderizarla).

### Tarjeta de invitación recibida, pareja a las de "Mis clientes de confianza"
El usuario mostró capturas: la tarjeta de una invitación pendiente (`Driver/Invitations.vue`) se veía pelada (solo nombre + Aceptar/Rechazar) al lado de las fichas ricas de "Flotas a las que pertenecés" (foto, calificación, medalla). También pidió confirmar que la tarjeta "Invite a un cliente" (de la pasada anterior) ya estuviera ahí — sí estaba, el problema era que producción sigue atrasada (ver más abajo), no que faltara construirla.

- **`DriverInvitationController`**: la lógica de calificación/categoría del cliente (antes duplicada solo en `activeMemberships`) pasó a un método privado `clientReviewStats()`, reutilizado también en `pendingInvitations` (que antes no llevaba ningún cálculo extra, solo el registro crudo de la invitación).
- **`Driver/Invitations.vue`**: la tarjeta de invitación pendiente ahora usa `UserAvatar`, la insignia de calificación (`★ X.X` / "Sin calificaciones") y la medalla de categoría — mismo bloque visual que ya usan las flotas activas, sin duplicar el cálculo en el frontend.

Pendiente aparte, no de código: producción quedó confirmada (por SSH, revisando el commit desplegado) todavía en el fix de "admin/suscripciones" de dos pasadas atrás — nada de lo construido después (esta tarjeta, invitar por WhatsApp, recuperar sesión, selector de cuenta de Google, botones de flota) llegó al servidor. Falta que el usuario suba y confirme el `git push`/`pull` para desplegar todo el atraso junto.

### Tests
`tests/Feature/Fleet/FleetInvitationFlowTest.php` sigue OK sin cambios (la tarjeta es solo presentación, no cambia contrato de datos más allá de los dos campos nuevos que el backend ya mandaba desde antes). Suite completa: 508 tests OK, Pint limpio, build limpio.

### Tabla de "Suscripciones" del panel admin, con filtro y orden
El usuario mostró una captura: la lista de suscriptores era una pila de tarjetas grandes (una por usuario, con avatar, insignia de plan y botones apilados) — ocupaba mucho espacio por cada uno y no había forma de filtrar ni de ordenar, solo un buscador de texto.

- **`Admin/SubscriptionController::index()`**: suma filtro por rol (`role=cliente|conductor|admin`), por estado de plan (`plan_status=con_plan|gratis` — descarta admins, que no tienen plan de ningún lado) y orden (`sort=name|expiry`, `direction=asc|desc`). El orden por vencimiento trae el `expires_at` más próximo de la suscripción vigente del usuario como columna aparte (`selectSub`) solo para poder ordenar por ella — no se puede resolver en el navegador porque depende de datos de otros usuarios (paginado).
- **`Admin/Subscriptions.vue`**: la lista pasó de tarjetas a una tabla (Usuario · Rol · Plan vigente · Acciones), con encabezados de columna clicables para ordenar (nombre / plan vigente) y una barra de filtros arriba (buscador + rol + estado de plan + "Limpiar filtros"). El formulario de activar plan, que antes se abría inline empujando la fila hacia abajo, pasó a un modal (ya no calza con una tabla de columnas fijas).

### Tests
`tests/Feature/Admin/AdminSubscriptionTest.php` (+2: filtrar por rol y por estado de plan devuelve exactamente los usuarios esperados, incluido que un admin no cae en "gratis"; ordenar por vencimiento próximo trae primero al que vence antes). Suite completa: 510 tests OK, Pint limpio (reordenó imports y espaciado en `SubscriptionController.php`), build limpio.

### Bug: al cliente no le llegaba en vivo que el conductor finalizó la carrera
Reporte del usuario: "cuando el conductor finaliza la carrera no se le actualiza al cliente que se finalizó o se completó la carrera". Investigado antes de tocar nada (mismo criterio de siempre con reportes de "no llega en vivo": revisar si falta el evento o si falta el listener, no asumir que Reverb está roto): el backend sí transmitía todo bien — `RideController::complete()` dispara `RideCompleted` (`ride.completed`), que llega al canal de flota y al canal personal de las dos partes, exactamente igual que `RideStarted`/`RideCancelled`.

El problema estaba solo del lado del cliente: `Ride/Show.vue` (la pantalla de seguimiento de una carrera puntual) tenía el listener de `.ride.started` y de `.ride.cancelled` en el canal de flota, pero nunca se agregó el de `.ride.completed` — por eso quien tenía esa pantalla abierta se quedaba viendo "en curso" aunque el conductor ya hubiera terminado (en la lista general `Ride/Index.vue` sí funcionaba, porque esa pantalla escucha por el canal personal, no por el de flota). Se agregó el mismo bloque que ya usan los otros dos eventos en ese archivo.

### Tests
No aplica — la suscripción a un canal de WebSocket (Laravel Echo) no se puede reproducir en un test de Feature de PHP. Verificación real queda para cuando esté desplegado.

### Nuevos hitos de carrera: "ya llegué" y "ya recogí al cliente"
Pedido explícito del usuario: que el conductor pueda marcar que llegó al punto de encuentro (avisando al cliente en vivo que lo está esperando) y, por separado, que marque cuándo recogió al cliente de verdad, guardando la fecha y hora para poder calcular esa información después (tiempo de espera, duración real del viaje).

Investigado antes de construir: el único estado que ya existía era `in_progress`, de principio a fin del viaje (desde que el conductor arranca a buscar al cliente hasta que lo completa) — no había ningún hito intermedio, ni columna, ni evento para esto. Es dos funciones nuevas por completo, no una reetiquetada de algo existente.

- **Migración** `add_arrived_and_picked_up_at_to_rides_table`: dos columnas nuevas en `rides`, `arrived_at` y `picked_up_at` (nullable — ninguna carrera vieja las tiene, y no son obligatorias para completar la carrera).
- **`RideController::arrived()`** (`POST /carreras/{ride}/llegue`) y **`::pickedUp()`** (`POST /carreras/{ride}/recogido`): solo el conductor, solo mientras la carrera está `in_progress`, cada uno una sola vez. `arrived()` transmite `RideArrived` (`ride.arrived`) y manda `RideArrivedPushNotification` al cliente — mismo patrón de fan-out (flotas + canal personal de las dos partes) que `RideStarted`/`RideCompleted`. `pickedUp()` transmite `RidePickedUp` (`ride.picked_up`) para que la pantalla del cliente se refresque sola, sin push (no lo pidió el usuario para este paso).
- **`Ride/Show.vue`**: dos botones nuevos para el conductor ("📍 Ya llegué" / "🧍 Ya recogí al cliente"), uno a la vez según cuál ya se marcó — ninguno bloquea "Marcar como completada" si el conductor se los saltea. Del lado del cliente, el banner de "en curso" ahora distingue tres momentos: "va en camino" (con ETA, como antes), "lo está esperando" (apenas el conductor marca "ya llegué") y "viaje en curso hacia el destino" (una vez recogido). Primer uso de los datos guardados: una fila de "Tiempo de espera" en el desglose del precio, calculada entre `arrived_at` y `picked_up_at`.

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php` (+6: el conductor puede marcar llegada y el cliente recibe el push; el cliente no puede marcarla; no se puede marcar dos veces; el conductor puede marcar recogido con el evento correspondiente; tampoco se puede marcar dos veces). Suite completa: 515 tests OK, Pint limpio, build limpio.

### La tarifa mínima del conductor no tenía ningún efecto
El usuario reportó: "la tarifa mínima que coloca el conductor no tiene efecto porque el admin es el que tiene prioridad". Investigado antes de tocar nada: no era una cuestión de prioridad — `DriverProfile.minimum_fare` (el campo "Tarifa mínima" del perfil del conductor) nunca se leía en ningún lado. `PriceCalculator::suggestedPrice()` solo usaba la tarifa mínima general de `/admin/tarifas`, sin enterarse jamás de lo que el conductor hubiera declarado — un campo del formulario completamente muerto.

Pedido explícito del usuario para la nueva jerarquía: la tarifa mínima del conductor SÍ se respeta, siempre que sea menor o igual a la de la plataforma (un conductor dispuesto a cobrar menos puede hacerlo); si intenta declarar una mayor, se le indica en su configuración que la plataforma no lo permite, en vez de guardarla en silencio sin que sirva para nada.

- **`PriceCalculator::suggestedPrice()`**: nuevo parámetro opcional `driverMinimumFare` — si viene, el piso usado es `min(la del conductor, la de la plataforma)` (doble candado: aunque alguien se salte la validación de abajo, o el admin haya bajado su tarifa después de que el conductor guardó una más alta cuando todavía era válida, nunca se cobra de más).
- **`DriverProfileController::update()`**: la regla `minimum_fare` ahora tiene un `max:` dinámico contra `PricingSetting::current()->minimum_fare`, con mensaje explicando el tope. `edit()` manda `platformMinimumFare` a la pantalla.
- **`Driver/Profile.vue`**: texto de ayuda junto al campo ("No puede superar $X, tope de la plataforma").
- **`RideRequestController`**: nuevo `referenceMinimumFare()` (mismo criterio que `referenceRatePerKm()`) — si la solicitud es a un conductor puntual, usa la suya; si es "a toda la flota" (todavía sin conductor), usa la general, sin promediar mínimos entre conductores. También se expone `minimum_fare` por conductor en la ficha que ya arma `driverCardData()`.
- **`Ride/Request.vue`**: el estimado que ve el cliente antes de mandar la solicitud ahora replica la misma jerarquía (mismo criterio que ya existía para no mostrar un estimado mentiroso por el mínimo general).

### Tests
`tests/Feature/Driver/DriverMinimumFareTest.php` (nuevo, 3: guardar una menor funciona; guardar una mayor se rechaza con el mensaje correcto; la pantalla expone el tope). `tests/Feature/Ride/RidePriceNegotiationTest.php` (+2: la tarifa propia del conductor gana si es menor; una tarifa vieja por encima del tope igual se recorta al cobrar). Suite completa: 521 tests OK, Pint limpio, build limpio.

### Mensajes en español al subir una foto de perfil que pesa de más
El usuario reportó que el mensaje de error al subir una foto de perfil demasiado pesada no era claro. Investigado: no existe `lang/es/validation.php` en el proyecto — Laravel cae al inglés del framework para cualquier regla de validación sin mensaje propio explícito (no es solo esta pantalla, es cualquier campo sin un mensaje a medida). Se cubrió puntualmente para la foto de perfil en vez de traducir todo el archivo de validación del framework (mucho más de lo que hacía falta):

- **`ProfileUpdateRequest::messages()`** (nuevo): mensajes en español para `avatar.image` y `avatar.max`.
- **`Profile/Partials/UpdateProfileInformationForm.vue`**: aviso del límite (4 MB) visible ANTES de elegir el archivo, y una validación del lado del navegador que avisa al toque si la foto pesa de más (con el peso real de la foto en el mensaje) — sin esperar a mandarla entera al servidor para recién ahí enterarse.

### Tests
`tests/Feature/ProfileTest.php` (+1: subir una foto de más de 4 MB devuelve el mensaje en español esperado). Suite completa incluida en el conteo de arriba (521 tests OK).

### Rediseño del Inicio del cliente (mockup provisto por el usuario)
El usuario compartió una captura de referencia y, tras confirmar que sí la quería construida (no solo opinión), se rearmó la pantalla de Inicio del lado cliente para que coincida:

- **Saludo + insignia de rol/plan**: "Cliente · Plan {{X}}" junto al nombre, mismo criterio que ya usaba el lado conductor ("Conductor ✓ · Disponible"). Nuevo: `DashboardController` inyecta `PlanLimits` y expone `clientPlanName` (`PlanLimits::forClient()`, ya existía, no se tocó).
- **"Tu flota"**: pasó de una lista rica (buscador + tarjetas por conductor con "Pedir carrera" directo) a una tarjeta resumen glanceable (cuántos disponibles ahora + avatares + flecha) que lleva a `Fleet/Show.vue` — investigado antes de sacar nada: esa pantalla ya tiene el buscador, invitar y "Pedir carrera" por conductor, así que no se perdió funcionalidad, solo se dejó de duplicarla en el Inicio.
- **Dos accesos grandes**: "Pedir carrera" (viaje inmediato) y "Programar carrera" (elegir fecha y hora) pasaron de ser dos ítems chicos de una grilla de 4 a la acción principal de la pantalla.
- **"Más opciones"**: grilla con Expresos, Viajes en VAN, Mis rutas favoritas y Cupones y beneficios — las cuatro ya existían como pantallas propias (`express-routes.index`, `van-trips.index`, `ride-requests.create` para rutas guardadas, `coupons.index`), no hizo falta backend nuevo.
- **"Seguridad siempre"**: reemplazó la tarjeta genérica "Viaje con confianza". Investigado antes de simular un botón SOS que no hiciera nada: `SosAlertController::store()` necesita una carrera `in_progress` puntual (saca el conductor/vehículo de ESA carrera) — no existe un SOS "sin carrera". La tarjeta lleva a "Mis contactos de confianza" en vez de inventar un mecanismo de emergencia nuevo sin que el usuario lo haya pedido.

### Tests
`tests/Feature/DashboardTest.php` (+1: el cliente recibe `clientPlanName` para la insignia). Suite completa: 522 tests OK, Pint limpio, build limpio.

## Fase 1 del roadmap grande ("PROMPT — MEJORAS INTEGRALES"): bugs críticos
El usuario mandó un roadmap de 20 secciones a implementar por fases, verificando cada bloque antes de seguir. Esta entrada cubre la Fase 1 (bugs críticos); las demás fases quedan pendientes de confirmación antes de arrancarlas.

### Bug crítico: un conductor sin documentos quedaba "en revisión" y bloqueado para subirlos
Reporte del usuario: un conductor guardó su perfil sin subir ninguna foto, y el sistema igual mostraba "Pendiente de revisión" y no lo dejaba subir nada — y del lado admin no aparecía en la cola de verificación (no había nada que revisar). Investigado antes de tocar nada: `verification_status` era una columna `ENUM` con default `'pending'` — al crear la fila del perfil por primera vez sin subir ninguna foto, `DriverProfileController::update()` no incluía esa clave en absoluto (solo la setea cuando de verdad se sube un archivo), así que el INSERT caía en el default de la columna, no en una decisión real del código. `DriverProfile::canUploadDocuments()` (que ya tenía la intención correcta en su comentario: "o todavía no subió ninguna") se topaba con ese 'pending' espurio y bloqueaba igual.

- **Migración** `make_verification_status_nullable_on_driver_profiles_table`: la columna pasa de `ENUM` a `string` nullable, sin default — 'pending' ahora SOLO lo pone el código cuando se sube un archivo de verdad. Incluye backfill: las filas ya afectadas (`pending` sin ninguna foto) se corrigen a `null`.
- **`Driver/Profile.vue`**: insignia y textos nuevos para el estado `null` ("Sin documentos"), sin tocar la lógica de bloqueo (que ya estaba bien escrita, solo esperaba nunca ver un 'pending' falso).

### Bug: el precio de la carrera quedaba "pegado" en un valor anterior
Investigado a fondo antes de tocar nada (el cálculo en sí, vía `computed()`, ya era reactivo y correcto — no era un problema de caché de Vue ni del backend, que ya recalcula todo desde las coordenadas que llegan en cada pedido). El problema real eran dos condiciones de carrera de async:

- **`useCurrentLocationAsOrigin()`** (`Ride/Request.vue`): el intento silencioso de geolocalización al abrir la pantalla ya protegía el TEXTO de la dirección para no pisar lo que el cliente ya hubiera escrito, pero `originLat`/`originLng` se pisaban sin ninguna condición un par de líneas más abajo. Si el cliente elegía un origen por autocompletar mientras la geolocalización todavía no resolvía, la respuesta tardía del GPS terminaba pisando esas coordenadas igual — el precio quedaba calculado sobre el punto viejo aunque el texto en pantalla mostrara el correcto.
- **`AddressAutocomplete.vue::selectSuggestion()`**: sin token de selección, tocar una sugerencia y después otra (o la misma dos veces) antes de que la primera resolviera sus coordenadas dejaba que la que resolviera último "ganara", sin importar cuál se tocó último de verdad.

### Botón "X" en origen y destino
Pedido explícito del usuario, parte del mismo bug de fondo: antes solo se podía borrar el texto a mano, y eso NO limpiaba lat/lng/sector ya elegidos — el precio seguía calculado sobre el punto viejo aunque el campo se viera vacío. `AddressAutocomplete.vue` ahora tiene una "X" que limpia texto + emite un evento `clear` nuevo; `Ride/Request.vue` lo escucha y suelta lat/lng/sector de verdad.

Sobre "validaciones backend" (parte de la Fase 1 en el pedido del usuario): ya estaban — `RideRequestController::store()` recalcula distancia y precio sugerido desde las coordenadas del pedido en curso, nunca confía en un número que mande el navegador, y ya rechaza cualquier oferta por debajo de ese estimado (confirmado al investigar el bug del precio, no hizo falta agregar nada acá).

### Tests
`tests/Feature/Security/DriverVerificationTest.php` (+1: guardar el perfil la primera vez sin fotos deja `verification_status = null`, no `'pending'`, y `canUploadDocuments()` da `true`). Los bugs de `Request.vue`/`AddressAutocomplete.vue` son de reactividad de Vue en el navegador — no se pueden reproducir en un test de Feature de PHP (mismo criterio que otros fixes de frontend de esta sesión). Suite completa: 523 tests OK, Pint limpio, build limpio.

## Fase 2 del roadmap grande: experiencia de viaje

### Notificaciones push por cambio de estado — casi todo ya existía
Investigado antes de construir nada: de los cinco avisos que pedía el roadmap ("aceptó", "va en camino", "llegó al punto de recogida", "viaje comenzó", "viaje finalizó"), cuatro ya estaban implementados de sesiones anteriores (`RideAcceptedPushNotification`, `RideStartedPushNotification`, `RideArrivedPushNotification`, `RideCompletedPushNotification`). Solo faltaba uno: cuando el conductor marca "ya recogí al cliente" (función agregada hoy mismo, antes solo transmitía por WebSocket sin push). Se agregó **`RidePickedUpPushNotification`** ("▶️ Su viaje comenzó"), mismo patrón que las demás — sin duplicar el evento ni el broadcast que ya existía.

### Chat temporal cliente↔conductor
Pedido explícito del usuario: un chat que SOLO existe mientras hay una carrera programada o en curso entre esas dos personas puntuales — nunca antes de que el conductor acepte, ni después de que termine o se cancele. Sin exponer teléfonos.

- **`ride_messages`** (tabla nueva): cada carrera es su propio hilo (`ride_id`), no hace falta una tabla de "conversación" aparte ni un estado propio — `Ride::chatIsOpen()` (`status` en `scheduled`/`in_progress`) decide si se puede seguir escribiendo.
- **Canal de broadcast nuevo, `ride.{id}`** (`routes/channels.php`): a propósito separado del canal de flota — acá SOLO pueden escuchar las dos partes de esa carrera puntual, ningún otro miembro de la flota. Evento `RideMessageSent` (`ride.message.sent`).
- **`RideMessageController::store()`**: valida que quien escribe sea cliente o conductor de esa carrera, que el chat siga abierto, y el texto (máx. 500 caracteres). Responde JSON directo (no Inertia) para que el remitente agregue su propio mensaje sin esperar el eco del WebSocket, que sí le llega a la otra parte.
- **`Ride/Show.vue`**: tarjeta de chat nueva, visible mientras `chatOpen` — historial con scroll automático, respuestas rápidas distintas por rol (5 para conductor, 5 para cliente, tal cual las pidió el usuario) y campo de texto libre. Un sonido (`playUpdateChime`) avisa cuando llega un mensaje nuevo de la otra parte.

Sobre "estados en tiempo real" (parte de la Fase 2 en el pedido del usuario): ya estaba cubierto — cambios de estado de carrera, ubicación del conductor y ahora el chat usan todos la misma infraestructura existente (Laravel Reverb + Echo), sin agregar ninguna dependencia nueva, tal como pidió el usuario.

### Tests
`tests/Feature/Ride/RideRequestFlowTest.php` (+1: `pickedUp()` manda el push nuevo). `tests/Feature/Ride/RideChatTest.php` (nuevo, 7: mandar mensaje mientras está en curso o programada; un desconocido no puede; no se puede escribir con la carrera completada o cancelada; validación del texto; el historial se expone en `rides.show`). Suite completa: 530 tests OK, Pint limpio, build limpio.

## Fase 3 del roadmap grande: Viajes en VAN por rol
Investigado a fondo antes de tocar nada (`VanTripController`, `VanTrip`, `VanTripReservationController`, las tres pantallas Vue, `routes/web.php`): el módulo YA separaba los roles correctamente donde importa de verdad — `store()` bloquea a cualquier cuenta que no sea conductor (`isDriver()` + `van_trips_enabled` del plan), `Index.vue` (gestión) esconde el formulario de publicar si `canPublish` es falso, `Show.vue` ya distingue dueño/no-dueño. No hacía falta reconstruir nada de eso.

El único bug real era propio de esta sesión: la tarjeta "Viajes en VAN" que agregué hoy en el Inicio del cliente (rediseño de mockup) apuntaba a `van-trips.index` — la pantalla de gestión del CONDUCTOR ("Mis viajes VAN"), que para un cliente se ve siempre vacía porque filtra por `driver_user_id`. Corregido a `van-trips.browse`, el catálogo real de viajes publicados (origen → destino, fecha, hora, cupos, precio por persona — exactamente lo que pedía el roadmap). Se revisó también la tarjeta "Expresos" del mismo bloque por si tenía el mismo problema — no lo tenía: `express-routes.index` ya es la pantalla correcta para el cliente (`ExpressRouteController::index()` incluso redirige a un conductor que intente entrar ahí).

### Tests
No aplica — es un cambio de una sola URL de destino en un `<Link>` de Vue, sin lógica nueva de backend que probar (el backend ya estaba bien). Suite completa sin cambios: 530 tests OK, Pint limpio, build limpio.

## Fase 4 del roadmap grande: WhatsApp configurable, Monitoreo y Auditoría

### Configuración de WhatsApp desde /admin/integraciones/whatsapp
Pedido explícito del usuario: "evitar tener que modificar constantemente el .env". Se armó la jerarquía completa: base de datos primero, `.env` como respaldo (nunca se elimina), nunca se expone un token real al frontend, valores sensibles cifrados.

- **`whatsapp_settings`** (tabla nueva, singleton — mismo patrón que `pricing_settings`): `token`, `webhook_verify_token` y `app_secret` con cast `'encrypted'` (cifrados con la propia `APP_KEY`) y `$hidden` en el modelo; `phone_number_id`/`verification_template`/`business_number` en texto plano (no son secretos). `updated_by` para saber quién hizo el último cambio.
- **`App\Services\WhatsAppConfig`** (nuevo): un solo lugar con la jerarquía "base de datos si hay algo, si no `.env`" — los 12 puntos del código que antes leían `config('services.whatsapp.*')` directo (controladores, middleware de firma del webhook, los dos servicios que mandan mensajes) ahora pasan por acá.
- **`Admin\WhatsAppSettingController`**: la pantalla nunca manda el valor real de un campo sensible, solo si está "Configurado acá" / "Usando el .env" / "Sin configurar". Dejar un campo sensible en blanco al guardar significa "no tocar lo que ya había" (no lo borra); los no sensibles sí se pueden vaciar de verdad.

### Monitoreo (Administración → Monitoreo)
Pedido explícito del usuario: "poder detectar problemas importantes sin revisar directamente logs del servidor" — un módulo de triage, no un visor de logs.

- **`system_events`** (tabla nueva) + **`App\Services\SystemEventLogger`**: un punto único para dejar un registro (severidad, módulo, tipo de evento, mensaje, código de error del proveedor, contexto) — nunca se guarda un token ni una contraseña ahí.
- Se conectó en los lugares donde el código YA sabía que algo había fallado, sin instrumentar todo desde cero: `WhatsAppFreeformSender`/`WhatsAppVerificationSender` (fallo al mandar), `SosAlertController` (correo a un contacto que no salió), y un hook nuevo en `App\Exceptions\Handler` (excepciones no atrapadas — al lado de Sentry, que ya existía pero necesita un panel aparte y solo funciona si está configurado; con su propio try/catch, para que un fallo de base de datos durante el reporte de un error no tumbe el reporte en sí).
- **`Admin\SystemEventController`**: filtros por módulo, severidad, estado, fecha y texto libre; marcar un evento como resuelto sin borrarlo.

### Auditoría (sección 18)
- **`admin_audit_logs`** (tabla nueva) + **`App\Services\AdminAuditLogger`**: registro inmutable de quién cambió qué. Para un campo sensible (token, etc.) nunca guarda el valor real — solo si cambió o no ("cambiado"/"sin cambios"), el resto de los campos sí se guarda tal cual (no son secretos). Arrancó con la configuración de WhatsApp (el primer módulo que lo necesitaba); reutilizable para lo que se sume después sin otra tabla. Se muestra en la misma pantalla de Integraciones en vez de una pestaña aparte casi vacía (sección 15 del pedido: "no implementar literalmente la estructura sugerida si la navegación actual permite algo mejor").

### Tests
`tests/Feature/Admin/WhatsAppSettingsTest.php` (nuevo, 7: acceso solo admin, nunca expone el secreto real, guardar campos normales, la base gana sobre el `.env`, el `.env` sigue de respaldo, dejar en blanco no borra, el cambio queda auditado sin el valor real). `tests/Feature/Admin/SystemEventTest.php` (nuevo, 4: acceso solo admin, filtros por módulo/severidad, marcar resuelto, un envío de WhatsApp fallido de verdad deja el registro — no solo el modelo/factory). Suite completa: 541 tests OK, Pint limpio, build limpio.

## Fase 5 del roadmap grande: Centro de Ayuda y Soporte

### Preguntas frecuentes por rol
- **`faqs`** (tabla nueva, con catálogo inicial sembrado en la propia migración — mismo criterio que `rating_reasons`): `audience` (cliente/conductor/ambos), categoría, pregunta, respuesta. Administrable desde `/admin/preguntas-frecuentes` (CRUD completo, mismo patrón que `RatingReasonController`).
- **`Support/Index.vue`**: buscador (filtra en el navegador, ya viene toda la lista de una) + acordeón por categoría, mostrando solo las de "ambos" más las del rol de quien mira (`Faq::scopeForAudience()`).

### "Hablar con soporte"
Pedido explícito del usuario: un ticket por usuario a la vez — mientras no esté cerrado, "Hablar con soporte" retoma el mismo en vez de abrir uno nuevo cada vez (mismo criterio de "un hilo por relación" que ya se usó para el chat de carreras).

- **`support_tickets`** + **`support_ticket_messages`** (tablas nuevas). `SupportTicket::openOrCreateFor()` resuelve "retomar o crear". Si el usuario le escribe a un ticket que soporte había dejado "esperando usuario" o "resuelto", vuelve a quedar como pendiente de atender — evita que un cliente quede sin salida si necesita hacer una repregunta.
- **Canal de broadcast nuevo, `support-ticket.{id}`** (mismo patrón que el de una carrera): solo el dueño del ticket o cualquier admin puede escuchar. Evento `SupportMessageSent`.
- **`SupportController`** (cliente/conductor): mandar mensaje, con respuestas rápidas según el rol (las 5 de conductor y las 5 de cliente, tal cual las pidió el usuario).
- **`Admin\SupportTicketController`**: lista de tickets con filtro por estado, vista de conversación con respuestas rápidas propias del admin, y cambio de estado explícito (Nuevo/En atención/Esperando usuario/Resuelto/Cerrado) — no se infiere solo, el admin lo elige.

### Tests
`tests/Feature/Support/SupportCenterTest.php` (nuevo, 7: FAQ filtradas por rol, inactivas no se ven, primer mensaje crea ticket, un segundo mensaje reutiliza el mismo, un ticket cerrado abre uno nuevo, responder a uno resuelto lo reabre). `tests/Feature/Admin/SupportTicketTest.php` (nuevo, 4: acceso solo admin, filtro por estado, responder mueve a "esperando usuario", cambiar estado a mano). `tests/Feature/Admin/FaqTest.php` (nuevo, 4: acceso solo admin, crear, desactivar, eliminar). Suite completa: 556 tests OK, Pint limpio, build limpio.

## Fase 6 del roadmap grande: Home público

### "¿Cómo funciona ARKA01?"
Pedido explícito del usuario: nada de bloques de texto — un flujo ilustrado con los pasos que dio textualmente, uno para Cliente y otro para Conductor, con emojis y una línea conectora entre pasos. Se agregó directo en `Welcome.vue` (ya era una pantalla simple, sin necesitar backend nuevo), respetando la identidad gráfica existente (tema oscuro, verde de marca).

### "Ayúdanos a mejorar ARKA01"
Formulario público en el Home, sin necesidad de cuenta (nombre y correo opcionales) — con throttle (6 por minuto) porque no hay una cuenta detrás que límite el abuso.

- **`platform_feedback`** (tabla nueva) + **`PlatformFeedbackController::store()`** (público, fuera del grupo `auth`).
- **`Admin\PlatformFeedbackController`**: `/admin/opiniones` — filtrar por estado/tipo, clasificar (Nueva → Revisando → Considerada → Implementada → Descartada) y dejar una nota interna que nunca ve quien mandó la opinión (no hay cuenta a la que devolvérsela).

### Tests
`tests/Feature/PlatformFeedbackTest.php` (nuevo, 4: un visitante sin cuenta puede mandar una opinión, el comentario es obligatorio, acceso admin restringido, clasificar con nota interna). Suite completa: 560 tests OK, Pint limpio, build limpio.

## Fase 7 del roadmap grande: optimización de UI (última fase)

### Saludo movido al navbar
Pedido explícito del usuario: el "¡Hola, X!" grande ocupaba espacio importante dentro del contenido de Inicio. Se movió a `AuthenticatedLayout.vue` — en escritorio, discreto junto a los íconos de cuenta; en móvil, aprovecha la columna del medio del header que quedaba vacía (ahí vive la navegación de escritorio, oculta en pantallas chicas). Se sacaron los títulos duplicados de `Dashboard.vue` (cliente y conductor), dejando solo la insignia de rol/plan/disponibilidad, que sí es información funcional.

### Diseño roto en móvil de "Conductores en su flota" + avatares por defecto
Esto quedó pendiente de un reporte anterior (con captura), que el usuario pidió explícitamente dejar agrupado acá con el resto de la Fase 7:

- **`Fleet/Show.vue`**: la fila de cada conductor era `flex items-center` sin apilar en pantallas angostas — los tres botones de acción ("Pedir carrera", "Referir", "Sacar", todos `shrink-0`) le comían todo el ancho al nombre, sin importar el `min-w-0` que ya tenía ese bloque. Se apila en columna en móvil (mismo patrón ya usado en el panel admin) y los botones ahora pueden envolver en vez de desbordar.
- **`UserAvatar.vue`**: sin foto, ya no se muestran iniciales — un ícono genérico distinto según el rol (volante para conductor, persona para cliente/admin), para que se vea igual de "terminado" en cualquier pantalla en vez de notarse que "falta diseño". Se aplica en TODA la app de una sola vez, al ser un componente compartido.

Se revisaron también "Conductores cerca" (`Dashboard.vue`) y el directorio público (`Directory/Index.vue`) contra el pedido de "no mostrar únicamente la fotografía" (sección 2 del roadmap) — ya mostraban foto, nombre, calificación, distancia e indicador de disponibilidad de antes, no hizo falta tocarlos.

### Tests
No aplica — cambios puramente visuales/de layout en Vue, sin lógica de backend nueva que probar. Suite completa sin cambios: 560 tests OK, Pint limpio, build limpio.

---

### Bug crítico: cuentas trabadas para siempre si fallaba el envío del código de WhatsApp
Reporte del usuario, con casos reales en producción: gente que se registró y nunca pudo pasar de la pantalla de verificar teléfono, porque el código nunca llegaba. Investigado a fondo: la app YA tenía el criterio correcto para cuando la integración de WhatsApp NO está configurada (auto-verifica, no bloquea a nadie por una integración pendiente) — pero ese mismo criterio nunca se aplicaba cuando la integración SÍ estaba configurada y el envío fallaba de verdad (token vencido, límite de Meta, plantilla no aprobada). En ese caso, `sendCode()` devolvía `false`, pero los tres lugares que lo llaman solo lo registraban en el log — la cuenta quedaba esperando un código que nunca iba a llegar, y ni siquiera "Reenviar código" ayudaba: repetía el mismo fallo en silencio y decía "le mandamos un código nuevo" igual, fuera verdad o no. No existía ningún otro escape (ni admin, ni automático).

- **`RegisteredUserController::store()`**: si el envío falla al registrarse, el teléfono queda auto-verificado ahí mismo — la cuenta nueva nunca llega a quedar trabada.
- **`DriverProfileController::update()`** (cambio de número desde el perfil): mismo criterio.
- **`PhoneVerificationController::resend()`**: mismo criterio, y es la salida real para quien YA está trabado hoy en producción — con este cambio desplegado, tocar "Reenviar código" una vez más los desbloquea solos, sin necesitar que un admin toque nada a mano en la base de datos. El mensaje que ve el usuario ahora también es honesto ("no pudimos mandarle el código, lo dejamos verificado igual") en vez de siempre decir que se mandó.

### Tests
`tests/Feature/Auth/PhoneVerificationTest.php` (+2: registrarse con el envío fallando de verdad no bloquea el dashboard; reenviar con el envío fallando desbloquea la cuenta). `tests/Feature/Driver/DriverProfilePhoneUpdateTest.php` (+1: cambiar el número con el envío fallando queda verificado igual). Suite completa: 563 tests OK, Pint limpio (sin cambios de frontend, no hizo falta build).

---

### Rediseño del Home público (mockup provisto por el usuario)
El usuario compartió un mockup completo del Home público y pidió que quedara así, más un ajuste puntual en el Inicio del cliente (con captura anotada a mano). Confirmado antes de tocar nada (`AskUserQuestion`): la línea "Cliente · Plan Gratis" tachada se elimina del todo, no se hace más chica — esa info ya está en Mi perfil.

- **`Welcome.vue`**: reemplaza el flujo de pasos que se había armado en la Fase 6 por el diseño del mockup — encabezado con logo + "Tu círculo. Tus viajes. Tu decisión." + botones, dos fichas "Para Clientes"/"Para Conductores" con su lista de beneficios, el diagrama del medio (Clientes · A01 · Conductores conectados, con los tres puntos de "Red privada y segura/Control total/Sin intermediarios"), la franja "¿Por qué elegir Arka01?" (Seguridad/Privacidad/Precios justos/Califica y mejora/Soporte real), y "Ayúdanos a mejorar Arka01" — que pasó de formulario siempre visible a una barra angosta que abre el mismo formulario en un modal, sin ocupar la página de entrada. Sin librerías nuevas: todo con SVG propios en el mismo estilo que ya usa el resto de la app.
- **`Dashboard.vue`**: se sacó la insignia "Cliente · Plan X" del Inicio del cliente (confirmado con el usuario) — junto con el `clientPlanName` que ya no se usaba en ningún lado (prop, controlador, inyección de `PlanLimits` en `DashboardController`, y el test que lo cubría).

### Tests
Se quitó el test que cubría `clientPlanName` (prop eliminado). Sin tests nuevos — cambios de layout público, sin lógica de backend nueva. Suite completa: 562 tests OK, Pint limpio, build limpio.

---

## Roadmap grande — resumen final

Las 7 fases del "PROMPT — MEJORAS INTEGRALES PLATAFORMA ARKA01" quedaron completas: bugs críticos (documentos, precio, limpieza de campos), notificaciones push + chat de carrera, separación de roles en Viajes VAN, WhatsApp configurable + Monitoreo + Auditoría, Centro de Ayuda + Soporte, Home público (cómo funciona + opiniones), y optimización de UI. Todo verificado por bloques (tests + Pint + build después de cada fase), sin romper funcionalidad existente. Como el resto de lo construido en esta sesión, queda pendiente del mismo `git push` + despliegue pendiente de confirmación del usuario.

---

### "Reiniciar demo" ya no borra el admin ni las configuraciones
Pedido explícito del usuario, ajuste a una función ya existente: antes, "Borrar demo y reiniciar" (`/admin/sistema`) borraba TODAS las cuentas `@arka01.test` — incluida la cuenta admin de prueba, que se volvía a crear de cero — y si esa era la cuenta con la que se estaba usando el panel, cerraba la sesión. El usuario pidió que en vez de eso solo se toquen los suscriptores de prueba (clientes y conductores), nunca ninguna cuenta admin ni ninguna configuración ya hecha.

- **`Admin\SystemController::resetDemo()`**: el filtro de borrado ahora es `email LIKE '%@arka01.test' AND is_admin = false` — ninguna cuenta admin se toca nunca, sea cual sea su correo. Como consecuencia, ya no hace falta la lógica de "cerrar sesión si te borraste a vos mismo" (no puede pasar: quien usa este botón es admin, y los admin ya no se borran) — se sacó esa rama entera.
- **Limpieza de archivos en disco** (pedido explícito: "imágenes de esos usuarios... y transacciones"): el cascade de las FKs ya borraba las filas de la base (carreras, reseñas, suscripciones, flotas), pero nunca tocaba los archivos — quedaban huérfanos en el disco. Ahora, antes de borrar cada suscriptor demo, se borra también su foto de perfil (si no es una URL externa de Google), la foto de licencia y de vehículo de su perfil de conductor, las fotos de sus Viajes en VAN publicados, y el comprobante de pago de sus pedidos de plan — los cuatro tipos de archivo que un suscriptor de prueba puede haber subido.
- **`DemoDataSeeder`**: la creación del admin pasó de `create()` a `firstOrCreate()` — necesario porque ahora, al no borrarse nunca, `admin@arka01.test` ya existe cuando el seeder se vuelve a correr después de un reinicio, y un `create()` sin este chequeo hubiera tirado un error de correo/teléfono duplicado y roto el reinicio entero.
- **`Admin/System.vue`**: texto actualizado para reflejar el alcance real — deja explícito qué se borra y, en negrita, qué NUNCA se toca (cuentas admin y configuraciones).

Confirmado con el usuario antes de implementar (no se tocó ninguna tabla de configuración en ningún momento — planes, tarifas, cupones, banners, etc. — esas tablas nunca estuvieron en el alcance de este botón, solo cuentas de usuario).

### Tests
`tests/Feature/Admin/AdminSystemControllerTest.php` (reescrito: +2 confirmando que una cuenta admin, incluso con correo `@arka01.test`, nunca se borra y sigue siendo la misma fila después del reinicio; +2 verificando que las fotos de un conductor demo y el comprobante de pago de un cliente demo se borran del disco; +1 confirmando que el catálogo de planes no se toca; se quitó el test que esperaba el logout del admin de prueba, ya no aplica). Suite completa: 565 tests OK, Pint limpio, build limpio.

---

### Ajustes puntuales: desplegables blancos, teléfono expuesto, tarjeta "Tu flota" sin datos
Reportes del usuario con capturas, varios de una sola vez:

- **`<select>` nativo con el tema del sistema operativo** (captura: panel del "Tipo" en el formulario de opiniones, blanco sobre blanco): mismo bug ya conocido y ya resuelto antes en el formulario de carrera (el navegador pinta el desplegable de un `<select>` con su propio estilo, sin que ningún CSS lo alcance). Se reemplazaron por `SearchableSelect` (el mismo componente ya usado en el resto de la app) en el modal de opiniones de `Welcome.vue` y, de paso, en las cuatro pantallas nuevas de esta sesión que tenían el mismo problema sin que nadie lo hubiera reportado todavía: `Admin/Faqs.vue` (los dos formularios), `Admin/Monitoring/Index.vue` (módulo/severidad/estado), `Admin/Support/Index.vue` y `Admin/Support/Show.vue` (estado del ticket).
- **Teléfono del conductor expuesto en "Conductores en su flota"** (`Fleet/Show.vue`, captura): pedido explícito del usuario, se sacó de esa tarjeta por privacidad — se mantiene la tarifa por km, que sí tiene sentido ver ahí. La búsqueda para invitar a alguien nuevo (una pantalla distinta, donde el teléfono sirve para confirmar identidad) no se tocó.
- **Tarjeta "Tu flota" del Inicio del cliente solo mostraba fotos**: el usuario recordó su pedido original (sección 2 del roadmap de mejoras: "no quiero que se muestre únicamente la fotografía — foto, nombre, calificación, distancia e indicador de disponibilidad"), que esta tarjeta puntual no cumplía (se había priorizado que fuera un resumen bien compacto). Pasó de una tira de avatares sueltos a una lista corta (hasta 3) con avatar + nombre + calificación + distancia, todos datos que `DashboardController::fleetDriversFor()` ya calculaba y mandaba sin usarse en esta tarjeta.
- **Correo `hello@example.com` en Términos/Privacidad**: no es un bug de código — `config('mail.from.address')` ya estaba bien conectado, pero el `.env` local todavía tiene el placeholder de Laravel sin completar (`.env.example` ya documenta correctamente `soporte@arka01.com`). Le señalé al usuario cuál variable completar en su `.env` local en vez de tocar el código (las credenciales/config van en `.env`, no se hardcodean) — en producción esto ya estaba resuelto de una pasada anterior (`wpgo@siglotecnologico.com`, mientras no exista el buzón real).

### Tests
No aplica — todos cambios puramente visuales/de layout en Vue (sin lógica de backend nueva) y una aclaración de configuración local. Suite completa sin cambios: 565 tests OK, Pint limpio, build limpio.

---

### Segunda vuelta: "Tu flota" simétrica y el saludo a otro lugar
El usuario probó los cambios anteriores y pidió dos ajustes puntuales sobre lo recién hecho:

- **"Tu flota" volvió a ser horizontal**, pero ya no una tira de solo avatares: ahora es una grilla simétrica de 4 columnas iguales, con los datos (nombre truncado, calificación, distancia) debajo de cada foto en vez de al lado en una lista vertical — mismo criterio de "no solo la fotografía" que antes, con el layout que sí le gustaba.
- **El saludo bajó de la barra superior** (donde había quedado tras la Fase 7 del roadmap grande) **a la cabecera de "Inicio"**, a la izquierda — reemplaza el título "Inicio" en `Dashboard.vue` por "¡Hola, {{nombre}}! 👋" en ese mismo lugar, en vez de vivir arriba junto a los íconos de cuenta. Se sacó por completo de `AuthenticatedLayout.vue` (ya no aparece en ninguna otra pantalla, solo en Inicio, que es donde tiene sentido un saludo).

### Tests
No aplica — cambios de layout en Vue. Suite completa sin cambios: 565 tests OK, Pint limpio, build limpio.

---

### Botón "Actualizar ubicación ahora" que no hacía nada + aviso de ubicación/notificaciones

El usuario reportó (con captura) que el botón "Actualizar ubicación ahora" del aviso "Sin ubicación reciente" del conductor no hacía nada al tocarlo, y pidió además un aviso para que tanto cliente como conductor activen ubicación y notificaciones si no las tienen prendidas — explícitamente **no** en `Welcome.vue`, solo dentro de la app logueada.

- **Causa real del botón mudo** (`DriverAvailabilityToggle.vue`): `refreshNow()` cortaba de una si `available.value` (el estado interno del switch) era `false` — y ese es justo el estado en el que queda casi siempre que aparece este aviso: `startWatching()` ya lo había puesto en `false` en su callback de error apenas falló la geolocalización una vez (permiso denegado, GPS sin señal un instante), aunque el conductor siga marcado disponible en el servidor. El botón entraba al guardia y no pasaba nada, sin ningún mensaje. Ahora `refreshNow()` intenta igual pedir la ubicación sin importar ese estado interno, y si el navegador la entrega, recupera el switch (lo vuelve a prender y retoma `watchPosition()`) en vez de quedarse callado.
- **`Components/PermissionsPrompt.vue`** (nuevo): tarjeta discreta y descartable (se recuerda 7 días en `localStorage`) que revisa el estado real de los permisos de ubicación (`navigator.permissions.query`) y notificaciones (`Notification.permission`) — solo se muestra si falta alguno de los dos, con un botón que dispara el pedido nativo del navegador (nunca automático al cargar la página, mismo criterio ya usado para las notificaciones push) o, si el navegador ya lo bloqueó permanentemente, un texto indicando que hay que revisarlo en los ajustes del sitio en vez de un botón que tampoco haría nada (mismo tipo de bug que se acaba de corregir arriba). Montada en `AuthenticatedLayout.vue` (cliente, conductor y admin por igual), a propósito fuera de `Welcome.vue`.

### Tests
No aplica — ambos cambios son de comportamiento del navegador (geolocalización/notificaciones), sin lógica de backend nueva. Suite completa sin cambios: 565 tests OK, Pint limpio, build limpio.

---

### "Tu flota" más apretada, con círculo "+N" en vez de texto aparte

El usuario mandó una captura pidiendo que la tarjeta "Tu flota" quedara más compacta para que quepan más conductores de un vistazo, pero sin perder prolijidad — y, si la flota es chica, evitar la sensación de grilla con columnas vacías.

- **`Dashboard.vue`**: la fila pasó de `grid grid-cols-4` (siempre 4 columnas, aunque haya menos conductores) a `flex flex-wrap` con columnas de ancho fijo más angosto (avatar de `h-10` en vez de `h-11`, texto más chico) — con una flota chica no quedan huecos, y con una grande caben más antes de necesitar el "+N".
- El texto suelto "+N más — vea todos" debajo de la fila se reemplazó por un círculo al final de la misma fila, con el mismo tamaño y layout que cualquier otro avatar (más una etiqueta debajo, igual que el nombre de cada conductor).
- Límite de vista previa configurable en `FLEET_PREVIEW_LIMIT = 5` (antes eran 4 fijos por la grilla).
- **Ajuste posterior** (captura de referencia: círculo punteado con "+"): ese círculo final ahora es siempre punteado, y funciona como llamado a la acción — si sobran conductores muestra "+N · Ver todos" (gris), y si la flota todavía tiene lugar muestra un "+" en color de acento con la etiqueta "Agregar", invitando directamente a sumar más conductores en vez de solo indicar cuántos faltan por ver.

### Tests
No aplica — cambio puramente visual/de layout en Vue. Suite completa sin cambios: 565 tests OK, Pint limpio, build limpio.

---

### "Viajes VAN" pasó a llamarse "Rutas y Turismo" + demanda insatisfecha

El usuario, viendo la pantalla de búsqueda vacía, pidió dos cosas: (1) que el nombre "VAN" dejara de usarse porque no calzaba con el servicio real (buseta, microbús, turismo — no solo van), y (2) que cuando una búsqueda no encuentra ningún viaje, esa ruta quede guardada para proponérsela a los conductores, y que mientras tanto se le muestren igual los viajes ya publicados (del público y de su propia flota) en vez de dejar la pantalla vacía.

- **Renombre**: se cambió el texto visible de "Viajes VAN" / "Viajes VAN / turismo" a **"Rutas y Turismo"** en todas las pantallas, menú de accesos rápidos, tarjeta del Inicio, plan del conductor, panel admin de planes y textos legales (Términos/Privacidad). Confirmado el nombre con el usuario antes de aplicarlo. Los nombres internos (`VanTrip`, tabla `van_trips`, rutas `van-trips.*`, el flag de plan `van_trips_enabled`) se dejaron como están — es una decisión de branding hacia el usuario, no de arquitectura, y renombrar clases/tablas no aporta nada y sí arriesga romper algo sin necesidad.
- **`van_trip_search_requests`** (tabla nueva) + `App\Models\VanTripSearchRequest`: cuando `VanTripController::browse()` no encuentra ningún viaje Y la búsqueda sí especificó una ruta concreta (origen + destino), se guarda esa búsqueda — sin duplicar si el mismo cliente repite la misma búsqueda (comparación con `whereDate()`/`whereNull()`, no con igualdad de string, porque no todos los motores de base de datos truncan la hora de una columna `date` de la misma forma — se encontró justamente ese problema entre MySQL y SQLite al escribir los tests).
- **`VanTrips/Index.vue`** (lado conductor): nueva sección "📋 Rutas que están pidiendo los clientes" — agrupa por ruta las búsquedas sin resultado de los últimos 30 días, con la cantidad de personas y la fecha más próxima pedida.
- **`VanTrips/Browse.vue`** (lado cliente): cuando no hay resultados, además del aviso "guardamos su búsqueda", se listan hasta 10 viajes ya publicados (sin los filtros que no dieron resultado) como alternativa — los de conductores de la propia flota del cliente aparecen primero con la etiqueta "De su flota", el resto sin etiqueta (viajes VAN no tienen flota propia como concepto, así que "público" es simplemente el resto).

### Tests
`tests/Feature/VanTrips/VanTripFlowTest.php` (+6): guarda la demanda solo con ruta concreta, no duplica una búsqueda repetida, no guarda nada si la búsqueda vino sin filtros, el listado de respaldo aparece con la flota propia primero, la demanda se agrupa correctamente para el conductor, y la demanda de más de 30 días ya no aparece. Suite completa: 571 tests OK, Pint limpio, build limpio.

---

### Cambios del conductor sin sonido ni vibración en la carrera del cliente

El usuario insistió: "sigue el problema que no llega las notificaciones de los cambios al cliente cuando existe un cambio por parte del conductor" y pidió un sonido fuerte y, si se podía, vibración.

- **Causa real** (`Ride/Show.vue`): los 5 listeners de cambios de una carrera activa (`ride.started`, `ride.cancelled`, `ride.completed`, `ride.arrived`, `ride.picked_up`) solo hacían `router.reload()` en silencio — ningún sonido, ninguna vibración. Si quien tenía la pantalla abierta no estaba mirándola en ese instante exacto, el cambio pasaba desapercibido del todo (la notificación push del sistema operativo tampoco suena si la pestaña ya está enfocada — ver el comentario ya existente en `Utils/liveAlert.js`). Se agregó `playAttentionAlert()` (sonido más fuerte + vibración, ya existía en el proyecto para "carrera nueva") a los 5 listeners. Como el backend ya manda estos eventos con `->toOthers()`, la alerta solo le suena a quien NO hizo la acción — nunca a quien la disparó.
- **`Ride/Index.vue`** (lista de "Carreras"): los mismos 3 eventos de carrera activa (`ride.completed`, `ride.cancelled`, `ride.started`, canal personal) usaban el chime suave sin vibración — se subieron también a `playAttentionAlert()` para que suenen igual en cualquiera de las dos pantallas. Los eventos de negociación previa (oferta aceptada/contraoferta/expiró/rechazada) se dejaron con el chime suave — son una categoría distinta, antes de que exista una carrera real en curso.

### Tests
No aplica — cambio puramente de audio/vibración en el navegador, sin lógica de backend nueva (no hay forma de probar Web Audio API/`navigator.vibrate` desde PHPUnit). Suite completa sin cambios: 571 tests OK, Pint limpio, build limpio.

---

### Error 500 al "Borrar y reiniciar demo" en producción

El usuario mandó capturas: `POST /admin/sistema/borrar-demo` devolvía 500 en producción, y Sentry mostraba `Call to undefined function Database\Factories\fake()` en `UserFactory.php`.

- **Causa real**: `fakerphp/faker` estaba en `require-dev` de `composer.json`. El propio Laravel (`vendor/laravel/framework/.../Foundation/helpers.php:509`) solo declara la función global `fake()` si la clase `Faker\Factory` existe — y en el servidor, el deploy corre `composer install --no-dev`, que nunca instala paquetes de `require-dev`. Como `DemoDataSeeder` usa `User::factory()`/`DriverProfile::factory()` (y estos, aunque casi todos sus campos vienen sobreescritos, igual ejecutan `fake()` dentro de su `definition()` base antes de aplicar el override), la función simplemente no existía en producción y todo el reinicio de demo tronaba.
- **Fix**: se movió `fakerphp/faker` de `require-dev` a `require` en `composer.json` — es la corrección correcta porque "Reiniciar demo" es una función pensada para usarse en producción (parte del panel admin), no solo en tests; reescribir los factories para no usar `fake()` hubiera sido más frágil y hubiera dejado sin arreglar el mismo problema en `php artisan demo:seed-many-drivers` (otro comando que también usa factories y que el usuario puede correr a mano en el servidor). `composer.lock` se regeneró con `composer update fakerphp/faker --with-all-dependencies` para que quede clasificado del lado correcto.
- **De paso**: ese mismo `composer update` reveló que `league/commonmark` (dependencia de Laravel, no elegida por el proyecto) tenía 6 avisos de seguridad conocidos (denegación de servicio al parsear cierto Markdown) en la versión que traía instalada — se actualizó con `composer update league/commonmark` dentro del mismo rango que exige `laravel/framework` (`^2.8.1`), sin tocar nada más. `composer audit` quedó en cero avisos.

### Tests
No aplica — cambio de dependencias/`composer.json`, no de lógica de la aplicación (los tests locales no distinguen `require`/`require-dev`, así que no hubieran detectado este bug — se verificó a mano que `fakerphp/faker` quedó en la sección `packages` de `composer.lock`, no en `packages-dev`). Suite completa sin cambios: 571 tests OK, Pint limpio, build limpio.

**Importante para el despliegue**: como esta vez sí cambió `composer.lock`, el paso de `composer install --no-dev` del `deploy.sh` en el servidor tiene que volver a correr (ya lo hace siempre), pero avisá si el deploy script está fijado a alguna versión vieja del lock — no debería, pero es la única vez en la sesión que un cambio toca dependencias en vez de solo código de la app.

---

### Registro en móvil: preguntas, layout roto del teléfono, y validación real de celular ecuatoriano

El usuario mandó capturas del registro en el celular: el paso 2 preguntaba "¿Cómo se llama?" (suena raro) y en el paso 4 el selector de país con el nombre completo del país aplastaba el campo del número a una cajita minúscula. También pidió validar que un número ecuatoriano sea un celular real — 9 dígitos (ya con el código de país puesto) y no basura como 9999999999 o 090000000.

- **Pregunta reformulada**: "¿Cómo se llama?" → "¿Cuál es su nombre?" (`Auth/Register.vue`) — mismo tono formal ("usted") que el resto de las preguntas de este mismo formulario ("¿Cuál es su correo electrónico?", "¿Cuál es su número de teléfono?").
- **Layout del teléfono en móvil**: el selector de país era un `<select>` nativo dentro de una fila `flex gap-2` — como no tenía ancho fijo, "🇪🇨 +593 Ecuador" completo competía por espacio contra el campo del número (que pedía `w-full`), y al no caber los dos, el número era el que terminaba aplastado casi a cero. Se reemplazó por `SearchableSelect` con un ancho fijo y angosto (`w-28 shrink-0`), y el campo del número pasó a `flex-1 min-w-0` para quedarse con el espacio que sobra, no al revés. Se agregó `shortLabel` opcional a `SearchableSelect.vue` (además del `label` de siempre): el selector ya cerrado muestra solo "🇪🇨 +593" (compacto), pero la lista desplegable sigue mostrando el nombre completo del país para poder reconocerlo/buscarlo. Mismo arreglo aplicado en `Driver/Profile.vue` (cambio de número de WhatsApp), que tenía exactamente el mismo problema.
- **`App\Rules\ValidPhoneNumberLocal`** (regla nueva, reutilizada en `RegisteredUserController` y `DriverProfileController`, reemplazando el `regex:/^[0-9]{7,10}$/` suelto que había en los dos): para Ecuador (+593) exige exactamente 9 dígitos que empiecen en 9 (sin el 0 inicial, que ya lo reemplaza el código de país) y rechaza números con los 9 dígitos repetidos (999999999, 000000000, etc.) — "de relleno" obvios que antes pasaban el formato viejo. Para el resto de países se mantiene la validación suelta (7 a 10 dígitos), porque no tenemos el formato exacto de cada uno. La misma regla se replicó en el front (`isValidPhoneLocal()` en `Register.vue`) para que el botón "Siguiente" no se habilite con un número que el backend va a rechazar igual.
- Un test existente (`RegistrationTest::test_new_users_can_register`) usaba `999999999` como número de prueba — justo el tipo de valor que ahora se rechaza — se cambió a un número válido.

### Tests
`tests/Feature/Auth/RegistrationTest.php` (+5): rechaza 10 dígitos, rechaza no empezar en 9, rechaza dígitos repetidos, acepta un celular ecuatoriano válido, confirma que otro país sigue con la validación suelta. `tests/Feature/Driver/DriverProfilePhoneUpdateTest.php` (+1): mismo rechazo de número obviamente falso al cambiar el teléfono desde el perfil. Suite completa: 577 tests OK, Pint limpio, build limpio.

---

### El desplegable del código de país quedaba aplastado al abrirse

El usuario mandó una captura del selector de país recién angostado (el arreglo anterior): al abrirlo, el buscador y la lista de países se veían comprimidos y pegados con el texto de ayuda de al lado.

- **Causa real** (`SearchableSelect.vue`): el panel desplegable usaba `w-full`, heredando el mismo ancho que el botón que lo abre — al angostar ese botón a propósito (`w-28`, para que el código de país no le robara espacio al campo del teléfono, ver la pasada anterior), el panel abierto se angostó con él, aunque adentro tuviera que mostrar nombres de países completos y un buscador.
- **Fix**: se agregó `min-w-56` (piso de ancho, no importa qué tan angosto sea el botón) y `max-w-[90vw]` (para que no se salga de la pantalla en un celular chico) al panel. No afecta ningún otro uso existente de `SearchableSelect` en la app — donde el botón ya era ancho, el mínimo nunca entra en juego.

### Tests
No aplica — cambio puramente de CSS/layout en un componente compartido. Suite completa sin cambios: 577 tests OK, Pint limpio, build limpio.

---

### Eliminar cuentas reales desde el panel admin

Pedido explícito del usuario: una opción para borrar una cuenta real (no de prueba) desde el panel admin, y que al hacerlo se borre todo lo suyo — archivos, historial de carreras, conexiones (flotas/membresías) y reseñas/comentarios.

Antes de tocar código se revisó a fondo TODO el esquema actual (26 tablas con llaves foráneas a `users.id`, incluyendo las agregadas esta sesión: soporte, chat de carrera, VanTrip, auditoría) para confirmar que ninguna quedara con una FK restrictiva que tumbara el borrado a mitad de camino — todas ya son `cascadeOnDelete()` o `nullOnDelete()`, así que un `$user->delete()` de verdad se lleva puesto todo: flotas, membresías, invitaciones, carreras (como cliente o como conductor), reseñas (hechas y recibidas), suscripciones, tickets de soporte y sus mensajes, sesiones de WhatsApp, Viajes VAN publicados y sus reservas, rutas guardadas, alertas SOS, contactos de confianza, etc. Lo único que el cascade NUNCA toca son los archivos en disco.

- **`App\Services\UserFileCleanup`** (nuevo): se extrajo la limpieza de archivos que antes vivía duplicada dentro de `Admin\SystemController` (avatar, licencia/vehículo, fotos de Viajes VAN, comprobantes de pago) a un servicio compartido — tanto "Reiniciar demo" como esta función nueva la usan igual, sin repetir la lógica.
- **`Admin\UserProfileController::destroy()`** (nueva acción, ruta `DELETE /admin/usuarios/{user}`): nunca permite borrar una cuenta admin (mismo criterio que "Reiniciar demo" — de paso cubre que un admin se borre a sí mismo, porque su propia cuenta también es admin). Como es irreversible y puede tocar mucho historial real, además de la validación del backend se exige escribir el correo exacto de la cuenta antes de que el botón se habilite (mismo patrón que usan otras apps para borrados sin vuelta atrás — GitHub, por ejemplo). Queda un registro en `admin_audit_logs` (mismo mecanismo ya usado para otros cambios administrativos críticos) con quién borró qué cuenta y cuándo.
- **`Admin/UserProfile.vue`**: nueva sección "Zona de peligro" al final del perfil completo del usuario (la misma pantalla que ya mostraba toda su info, a la que se llega desde Clientes/Conductores) — solo visible si la cuenta no es admin, con el campo de confirmación y el botón deshabilitado hasta que el correo escrito coincide.

### Tests
`tests/Feature/Admin/AdminUserProfileTest.php` (+5): un usuario normal no puede borrar cuentas, un admin no puede borrar a otro admin, falla si el correo escrito no coincide, borrar un cliente se lleva su flota/carrera/reseña pero no toca al conductor del otro lado (y queda el registro de auditoría), borrar un conductor purga sus fotos del disco. Suite completa: 582 tests OK, Pint limpio, build limpio.

---

## Qué falta (roadmap, sección 12 del alcance)

| Fase | Alcance | Estado |
|---|---|---|
| 5. Suscripciones | Planes reales de conductor/cliente, pago manual, panel institucional | ✅ Hecho (panel institucional completo, fuera de alcance por ahora) |
| 6. Expresos | Rutas fijas recurrentes | ✅ Hecho (visibilidad global de directorio y Expresos institucionales compartidos, fuera de alcance por ahora) |
| 7. PWA y seguridad | Manifest, Service Worker, push, seguimiento en vivo compartible, botón SOS | ✅ Hecho (más eventos push instrumentados queda para cuando se necesiten) |
| 8. Piloto | Lanzamiento cerrado | Pendiente |

Fuera de alcance por ahora (documentado, no se construye todavía): billetera virtual con comisión (sección 6 del alcance), panel institucional completo (entidad "Organización" separada con convenios corporativos — el plan Institucional ya existe y es asignable, sección 7.2), pasarela de pago real (sección 7.5 sigue siendo transferencia + confirmación manual), Expresos institucionales compartidos por varias personas de una organización (sección 4.4).
