# Canal de seguridad temporal de Arka01

Cada cliente o conductor tiene un canal principal persistente para su círculo:
familiares y contactos de seguridad en el caso del cliente; compañeros o
miembros de su cooperativa en el caso del conductor. La membresía se conserva,
pero la transmisión solo se habilita durante una solicitud o carrera del dueño.

## Cuándo aparece

- Cliente: desde que envía una solicitud inmediata con origen y coordenadas.
- Conductor: después de aceptar la solicitud.
- Carreras programadas: para ambos cuando la carrera pasa a `in_progress`.
- Integrantes invitados: ven el canal mientras la carrera del propietario esté activa.
- Cancelación o finalización: el canal se desconecta y desaparece automáticamente.

La primera pulsación entra al canal para escuchar. Una vez conectado, el botón
grande funciona directamente como Push-to-Talk. La lista de oyentes y las
opciones para invitar están plegadas para mantener una interfaz simple. Si
coinciden varios canales activos, aparece un selector compacto.

## Seguridad

`RideRadioAccess` decide en Laravel si la persona es propietaria o integrante
del canal y si el dueño está en una carrera. El navegador puede pedir uno de
los `public_id` que recibió, pero no inventar ni sustituir una sala.

La sala se deriva del UUID del canal mediante HMAC. Los enlaces de invitación
usan un código aleatorio revocable y nunca un ID incremental. El token firmado
incluye `public_id`, rol, sala y caducidad. Node valida la firma, la expiración
y que todos los eventos usen la sala firmada.

No se almacena audio en disco, base de datos ni logs. Socket.IO solo retransmite
fragmentos en memoria a los integrantes conectados. Solo un socket posee el
turno de micrófono a la vez.

## Flujo técnico

```text
Vue consulta GET /radio/status
       |
       +-- enabled=false: oculta y desconecta la radio
       |
       +-- enabled=true: muestra el acceso de la carrera
                            |
                            v
                   POST /radio/session
                            |
                            v
                 token HMAC de corta duración
                            |
                            v
                  Socket.IO / radio-server
```

La disponibilidad se reconcilia al navegar, volver a enfocar la aplicación y
periódicamente. Esto hace que el acceso desaparezca aunque la carrera cambie
de estado desde otro dispositivo.

Cuando otro integrante se conecta, quien ya escucha recibe un aviso
dentro de la app, un sonido y, si el navegador tiene permiso y está en segundo
plano, una notificación del sistema. El mensaje identifica a la persona sin
mostrar nombres ni IDs técnicos de canales.

## Archivos principales

- `app/Services/RideRadioAccess.php`: autorización y contexto de la carrera.
- `app/Models/RadioChannel.php`: canal principal y enlace revocable.
- `app/Models/RadioChannelMember.php`: integrantes autorizados.
- `app/Http/Controllers/RadioChannelController.php`: invitaciones y membresía.
- `app/Services/RadioAccessToken.php`: sala opaca y token HMAC.
- `app/Http/Controllers/RadioSessionController.php`: estado y sesión.
- `resources/js/Components/WalkieTalkie.vue`: estado, PTT, audio y avisos.
- `radio-server/server.js`: autenticación, turnos y retransmisión en memoria.
- `tests/Feature/Security/RadioSessionTest.php`: reglas de acceso Laravel.
- `radio-server/test/radio-server.test.js`: contrato Socket.IO.

## Variables

Laravel/Vite:

```dotenv
RADIO_SHARED_SECRET=<secreto aleatorio de al menos 64 caracteres>
RADIO_TOKEN_TTL_SECONDS=1800
VITE_RADIO_URL=https://arka01.com
VITE_RADIO_SOCKET_PATH=/radio/socket.io
VITE_RADIO_AUTH_ENDPOINT=/radio/session
VITE_RADIO_STATUS_ENDPOINT=/radio/status
```

Node:

```dotenv
HOST=127.0.0.1
PORT=3000
SOCKET_PATH=/socket.io
RADIO_SHARED_SECRET=<el mismo secreto de Laravel>
ALLOWED_ORIGINS=https://arka01.com
```

En local, `VITE_RADIO_URL=http://localhost:3000` y
`VITE_RADIO_SOCKET_PATH=/socket.io`.

## Producción

El proceso Node debe ejecutarse detrás de HTTPS/WSS y el proxy inverso debe
preservar `Upgrade` y `Connection`. Tras cambiar variables `VITE_*` se debe
recompilar el frontend. Tras cambiar el servidor de radio se debe reiniciar su
proceso administrado (por ejemplo, systemd o PM2).

La radio web puede operar en segundo plano mientras el navegador mantenga la
página y el socket activos, pero los sistemas móviles pueden suspender pestañas.
Capacitor requerirá después un servicio nativo o una estrategia específica de
ejecución en segundo plano para ofrecer garantías equivalentes a una radio
tradicional.
