# Arka01 — Registro de avances

Registro de funcionalidades grandes implementadas, en orden cronológico (más
reciente arriba). Cada entrada resume qué se hizo, por qué, y dónde vive en
el código — para retomar el contexto rápido más adelante, no como changelog
de usuario final.

---

## 2026-08-30 — Bug: "Ver perfil público" no abría en pestaña nueva

**Problema:** en `Ride/Request.vue`, el link a la cooperativa (dentro de la
tarjeta seleccionable, sección "Cooperativas" de "Elige tu conductor") usaba
el componente `<Link>` de Inertia con `target="_blank"`. Inertia v2 **ignora
`target`** — siempre intercepta el click y navega con su propio router SPA
en la misma pestaña, sacando al cliente del formulario de pedir carrera en
curso en vez de abrir una pestaña nueva.

**Fix:** reemplazado por una etiqueta `<a>` nativa (con `:href="route(...)"`,
`target="_blank"`, `rel="noopener noreferrer"`) — la regla general en
Inertia es que `<Link>` es solo para navegación SPA interna; cualquier link
que deba salir de ese flujo (pestaña nueva, sitio externo) tiene que ser un
`<a>` normal.

- `resources/js/Pages/Ride/Request.vue` (~línea 1995)

---

## 2026-08-30 — Tarifa y billetera cooperativa-conductor

**Problema:** una carrera de cooperativa cobraba al cliente el promedio de
tarifas de los conductores miembros, y ese monto quedaba como si fuera 100%
del conductor — sin separación entre lo que gana el conductor y el margen de
la cooperativa como intermediaria.

**Diseño:**
- `Cooperative.rate_per_km` (lo que cobra al cliente) y
  `Cooperative.driver_pay_rate_per_km` (lo que paga a sus conductores, único
  valor para toda la cooperativa) — ambos nullable; sin configurar, se sigue
  usando el promedio de tarifas de conductores, como siempre.
- Lo que gana el conductor es **proporcional al precio final cobrado**
  (`driver_pay_rate_per_km / rate_per_km` aplicado sobre `settled_price`,
  no un monto fijo por km) — así se beneficia también de recargos
  nocturnos/pico o de la tarifa mínima, igual que la cooperativa.
- **Billetera** (`cooperative_wallet_entries`, modelo
  `CooperativeWalletEntry`): una fila por carrera completada. Efectivo → el
  conductor se quedó con el margen de la cooperativa, le debe
  (`driver_owes_cooperative`). Transferencia → la cooperativa se quedó con
  la parte del conductor, le debe (`cooperative_owes_driver`). El saldo neto
  (`balanceFor()`) compensa ambos tipos — nunca se guarda como campo aparte,
  se calcula sumando las filas.
- `Cooperative.max_request_distance_km`: rango de cobertura desde su
  "stand" (`stand_lat/stand_lng`), mismo concepto que ya existía por
  conductor individual — filtra qué cooperativas ve el cliente al pedir
  carrera.
- Confirmado que el cliente **ya no ve conductores individuales** al elegir
  una cooperativa (solo la tarjeta con la cantidad de unidades) — eso ya
  estaba resuelto de antes, no hizo falta tocarlo.

**Dónde se ve el balance:** `Cooperative/DriverShow.vue` (panel de la
cooperativa, detalle de cada conductor) y `Driver/Profile.vue` (el propio
conductor ve su saldo con su cooperativa).

**Archivos clave:**
- `database/migrations/2026_08_30_081858_add_rate_settings_to_cooperatives_table.php`
- `database/migrations/2026_08_30_081859_create_cooperative_wallet_entries_table.php`
- `app/Models/Cooperative.php`, `app/Models/CooperativeWalletEntry.php`
- `app/Services/Ride/RideRequestCreator.php` (tarifa de cooperativa)
- `app/Http/Controllers/RideRequestController.php` (filtro de rango)
- `app/Services/Ride/RideLifecycle.php::complete()` (registra el movimiento)
- `app/Http/Controllers/CooperativeProfileController.php` (configuración +
  validación: no puede pagar más de lo que cobra)
- `resources/js/Pages/Cooperative/Profile.vue` (formulario de tarifas)
- Tests: `tests/Feature/Cooperative/CooperativeWalletTest.php`

---

## 2026-08-30 — Mejoras de WhatsApp: reconexión y aviso de sesión por vencer

**Botón "Conectarme" en el aviso de desconexión:** `sendDisconnectedAlert()`
pasó de texto plano a un botón interactivo (`wa_driver_connect`) — mismo id
que ya reconocía `WhatsAppDriverConnectHandler` cuando el conductor escribía
"conectarme" a mano. Como el aviso ahora es proactivo (el conductor no
escribió nada antes), hay que dejar `pending_intent = 'WA_DRIVER_STATUS'`
seteado de antemano en su `ChatbotConversation` para que el toque del botón
se reconozca igual que si lo hubiera tecleado.

**Aviso antes de que expire la ventana de 24h:** nuevo comando
`whatsapp:notify-expiring-sessions` (corre cada 15 min, `Kernel::schedule`)
que detecta la sesión VIGENTE de cada conductor (la más reciente por
`expires_at`) dentro del umbral de "por vencer"
(`WhatsAppSession::EXPIRING_SOON_THRESHOLD_HOURS`, 2h) y le manda botones
"Seguir conectado" / "Desconectarme". Tocar cualquiera de los dos ya reabre
la ventana por sí solo — WhatsApp cuenta ese toque como un mensaje entrante
(`WhatsAppWebhookController::openWindowFor()`). Se evita reavisar dos veces
con `whatsapp_sessions.expiring_soon_notified_at`. Solo aplica a
conductores, no a clientes.

**Archivos clave:**
- `app/Services/WhatsAppFreeformSender.php` (`sendDisconnectedAlert()`,
  `sendSessionExpiringSoonNotice()`)
- `app/Services/Chatbot/WhatsAppDriverConnectHandler.php` (botón
  `wa_session_keepalive`)
- `app/Console/Commands/NotifyExpiringWhatsAppSessions.php`,
  `app/Jobs/NotifyWhatsAppSessionExpiringSoon.php`
- Tests: `tests/Feature/WhatsApp/WhatsAppSessionExpiringSoonTest.php`,
  `tests/Feature/WhatsApp/WhatsAppDisconnectAlertTest.php`

---

## 2026-08-29/30 — Cargo por distancia de recogida

**Problema:** el precio de una carrera solo consideraba el tramo
origen→destino. El trayecto que el conductor recorre para llegar hasta el
cliente no se cobraba — solo existía un colchón fijo de 0.8 km
(`PriceCalculator::DISTANCE_PADDING_KM`) mezclado en el cálculo general, sin
relación con la distancia real del conductor.

**Diseño final** (pasó por varias iteraciones de ajuste con el usuario):
- Bajo un umbral configurable (`pricing_settings.pickup_surcharge_threshold_km`,
  default 3 km), sigue rigiendo el colchón fijo de 0.8 km, sin cambios.
- Sobre el umbral, se cobra un cargo aparte = distancia completa de
  recogida × tarifa del conductor × porcentaje configurable
  (`pricing_settings.pickup_surcharge_percent`, default 55%).
- **El precio queda fijo desde que se crea la solicitud** — no hay un
  checkbox del conductor para "cobrar o no" al aceptar (diseño descartado
  en el camino): si el cliente eligió un conductor puntual, el precio
  ofertado desde el inicio ya incluye su cargo real (el frontend lo replica
  exacto). Si pidió "a toda la flota", el cargo del candidato que resulte se
  suma de forma transparente sobre el precio ya aceptado, sin que el
  cliente lo haya podido anticipar.
- Cada conductor puede **apagar la función desde su perfil**
  (`driver_profiles.pickup_surcharge_enabled`, default `true`) — con esto
  apagado, no se le calcula ni se le muestra en ninguna solicitud.
- Redondeo: el total (viaje + recogida) se redondea hacia arriba a la
  década como TODO precio final de la plataforma — se corrigió un bug donde
  el estimado mostrado no coincidía con el precio final ofertado por este
  motivo.
- Trazabilidad: `pickup_distance_km`, `pickup_fare`, `pickup_fare_charged`
  quedan guardados en `ride_requests`/`rides`, visibles también en el panel
  admin (`Admin/RideDetail.vue`).

**Archivos clave:**
- `app/Services/PriceCalculator.php` (`pickupSurcharge()`,
  `pickupSurchargeForDriver()`)
- `app/Services/Ride/RideRequestCreator.php`,
  `app/Services/RideDispatchAdvancer.php`,
  `app/Services/Ride/RideRequestResponder.php`
- `app/Models/DriverProfile.php` (`pickup_surcharge_enabled`)
- `resources/js/Pages/Ride/Request.vue` (estimado por conductor, aviso al
  cliente), `resources/js/Pages/Ride/Index.vue` (desglose para el
  conductor), `resources/js/Pages/Driver/Profile.vue` (interruptor)
- Tests: `tests/Unit/PriceCalculatorTest.php`,
  `tests/Feature/Ride/RideRequestFlowTest.php`
