# Radio temporal de carrera de Arka01

La radio web funciona como una ayuda durante una solicitud o carrera y no
como un sistema permanente de canales privados. La sala es creada por el
servidor, pertenece a una sola carrera y admite únicamente al cliente y al
conductor asignado.

## Cuándo aparece

- Cliente: desde que envía una solicitud inmediata con origen y coordenadas.
- Conductor: después de aceptar la solicitud.
- Carreras programadas: para ambos cuando la carrera pasa a `in_progress`.
- Cancelación o finalización: se desconecta y desaparece automáticamente.

El botón no abre un selector. La primera pulsación activa el audio de la radio
autorizada y, una vez conectado, el botón grande funciona directamente como
Push-to-Talk: se mantiene presionado para hablar y se suelta para liberar el
turno.

## Seguridad

`RideRadioAccess` decide en Laravel si la persona tiene acceso. El navegador
no envía un identificador de sala en `POST /radio/session`; por tanto, no puede
inventar ni sustituir el canal.

La sala se deriva del ID interno de la solicitud mediante HMAC. Ni ese ID ni
el secreto se exponen. El token firmado incluye `public_id`, rol, sala y
caducidad. Node valida la firma, la expiración y que todos los eventos usen la
sala firmada.

No se almacena audio en disco, base de datos ni logs. Socket.IO solo retransmite
fragmentos en memoria al otro participante. Solo un socket posee el turno de
micrófono a la vez.

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

Cuando el segundo participante se conecta, quien ya escucha recibe un aviso
dentro de la app, un sonido y, si el navegador tiene permiso y está en segundo
plano, una notificación del sistema. El mensaje identifica a la persona sin
mostrar nombres ni IDs técnicos de canales.

## Archivos principales

- `app/Services/RideRadioAccess.php`: autorización y contexto de la carrera.
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
