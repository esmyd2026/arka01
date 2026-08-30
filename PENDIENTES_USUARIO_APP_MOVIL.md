# Pendientes del usuario — app móvil Arka01

> Esto es lo que **solo vos podés hacer** (crear cuentas, pagar membresías,
> generar credenciales) para que la app móvil llegue a producción. Todo lo
> demás (código, backend, diseño) lo sigo haciendo yo mientras tanto — nada
> de esta lista bloquea el trabajo que estoy avanzando ahora.
>
> Cuando tengas cada credencial, decime y la cargo en el `.env` — nunca se
> escriben hardcodeadas en el código (ver convención del proyecto). Cada
> ítem dice exactamente qué variable llenar.

## Ya resuelto (para contexto, no hace falta tocar nada)

- Notificaciones push por navegador (Web Push/VAPID) — ya configuradas.
- WhatsApp Business Cloud API — ya configurada (token, número, webhook).
- Google Maps — ya configurada.
- Google Sign-In (web) — ya configurado (`GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`).

## 1. Notificaciones push nativas (Android/iOS) — Hito 6

**Por qué hace falta:** hoy el "timbre" de una carrera nueva llega por Web
Push (solo funciona si el navegador/PWA está abierto) o por WhatsApp. Un
celular con la app instalada necesita **push nativo de verdad** (FCM en
Android, APNs en iOS) para avisar aunque la app esté cerrada. El backend ya
tiene el lugar para guardar el token de cada celular (`push_token`/
`push_provider` en cada sesión) — falta la cuenta que emite esos tokens.

- [ ] Crear un proyecto en **Firebase** (gratis): https://console.firebase.google.com
  - Agregar una app Android con el identificador de paquete definitivo
    (ver punto 4 más abajo — hay que decidir eso primero).
  - Descargar `google-services.json` (va dentro del proyecto Android de
    Capacitor, te aviso dónde exactamente cuando lo tengas).
  - Generar una "cuenta de servicio" (Service Account) desde
    Configuración del proyecto → Cuentas de servicio → Generar nueva
    clave privada (un archivo `.json`) — con eso el backend puede enviar
    notificaciones de verdad. Variables a llenar cuando lo tengas:
    `FIREBASE_PROJECT_ID`, y guardar el archivo de credenciales (te
    indico la ruta exacta cuando integre el paquete).
- [ ] Para iOS, el push (APNs) se resuelve dentro de la cuenta de Apple
  Developer del punto 3 — no es una cuenta aparte.

**Costo:** Firebase es gratis para este uso (Cloud Messaging no tiene costo).

## 2. Google Play Console — Hito 9 (publicar en Android)

**Por qué hace falta:** es la cuenta que permite subir el `.aab` a Google
Play.

- [ ] Crear la cuenta en https://play.google.com/console (pago único de
  registro, verificar el monto vigente en ese momento — no lo asumo yo).
- [ ] Decidir si la cuenta va a nombre tuyo (persona) o de una empresa —
  afecta qué papeles pide Google.
- [ ] Definir un correo de soporte público y, si tenés, un sitio web
  público para la ficha de la app (puede ser el mismo dominio de Arka01).

**No urgente todavía** — recién hace falta cerca del final, cuando la app
esté lista para la primera prueba interna.

## 3. Apple Developer Program — Hito 10 (publicar en iOS)

**Por qué hace falta:** sin esto no hay forma de firmar ni publicar nada
en iPhone, ni siquiera una prueba con TestFlight.

- [ ] Inscribirse en https://developer.apple.com/programs/ (membresía
  anual, pagada — verificar el precio vigente).
- [ ] Decidir cuenta individual o de organización (una organización pide
  un número D-U-N-S de la empresa, tarda más en aprobarse — si no tenés
  apuro, cuenta individual es más simple para arrancar).
- [ ] Reservar el nombre de la app en App Store Connect apenas esté la
  cuenta activa (nombres se agotan, conviene reservarlo temprano).

**No urgente todavía** — recién hace falta para el Hito 10 (compilación
iOS), más adelante en el roadmap.

## 4. Decisiones de identidad de la app — Hito 0

Estas no cuestan dinero, pero **si las cambiás después de publicar, es un
lío** (Google/Apple tratan un ID nuevo como una app nueva, se pierden
reseñas e instalaciones) — conviene decidirlas con calma antes de la
primera publicación, no hace falta ahora mismo mientras sigo con
backend/diseño.

- [ ] **Identificador de paquete definitivo** (hoy está puesto
  `com.arka01.app` como valor provisional). Formato típico:
  `com.tuempresa.arka01` o similar — decime cuál preferís.
- [ ] **Nombre público en las tiendas** (puede ser distinto al nombre
  interno "Arka01" si querés, ej. agregar la ciudad/país).
- [ ] Política de privacidad publicada en una URL accesible sin login
  (Google y Apple la piden sí o sí). Si ya tenés una en la web, avisame
  la URL; si no, la armamos cuando se acerque el momento.

## 5. Compilación iOS sin Mac — Hito 3/10

**Por qué hace falta:** no hay una Mac disponible en esta PC, así que la
compilación de iOS se hace en la nube.

- [ ] Crear cuenta en **Codemagic** (https://codemagic.io) — tiene un
  plan gratuito con minutos limitados por mes, alcanza para builds de
  prueba; si el volumen crece hace falta pasar a un plan pago (verificar
  precio vigente en ese momento).
- [ ] Cuando llegue el momento, hay que autorizar a Codemagic a conectarse
  con el repositorio de código — como vos manejás Git, esa conexión la
  hacés vos (o me avisás para guiarte paso a paso, pero la acción la
  ejecutás vos).

**No urgente todavía.**

## 6. Dispositivos de prueba

- [ ] Al menos un Android de gama media/baja (además del emulador que ya
  uso en esta PC) para probar en condiciones reales antes de publicar.
- [ ] Acceso temporal a un iPhone (prestado, no hace falta comprarlo) para
  probar la build de TestFlight antes de publicar en App Store.

**No urgente todavía** — hace falta recién cerca del Hito 8 (control de
calidad final).

---

### Resumen de qué NO te estoy pidiendo ahora

Nada de esto bloquea lo que sigo haciendo: backend, lógica de negocio y
diseño de las pantallas siguen avanzando igual sin estas cuentas. Esta
lista es solo para que la tengas presente y la vayas resolviendo con
calma — te aviso puntualmente si algo pasa a ser urgente antes de lo
esperado.
