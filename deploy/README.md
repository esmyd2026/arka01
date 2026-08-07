# Procesos que Arka01 necesita corriendo solos en producción

Gap identificado antes del despliegue: en Laragon, el queue worker y (si se
prueba en vivo) Reverb se corren a mano en una terminal — apenas se cierra
esa terminal o el proceso se cae, dejan de funcionar hasta que alguien lo
note. En un servidor de producción de verdad hacen falta 3 procesos vivos,
cada uno con una responsabilidad distinta:

| Proceso | Qué pasa si se cae | Cómo mantenerlo vivo |
|---|---|---|
| `php artisan queue:work` | Los Jobs (avisos de WhatsApp, notificaciones push, etc.) se acumulan en la tabla `jobs` sin procesarse — nadie se entera hasta que alguien mira. | `supervisor-queue-worker.conf` |
| `php artisan reverb:start` | "En vivo" deja de actualizarse (ubicación del conductor, estado de carrera) — el resto de la app sigue funcionando por HTTP normal. | `supervisor-reverb.conf` |
| El scheduler (`drivers:sweep-stale-availability`, `express:generate-rides`) | Los conductores "fantasma" (ver `App\Console\Commands\SweepStaleDriverAvailability`) no se desconectan solos, y los Expresos no generan su carrera del día. | Una sola línea de cron (no hace falta Supervisor, ver abajo) |

## 1. Queue worker y Reverb (Supervisor)

Los dos `.conf` de esta carpeta son plantillas — copiarlas a
`/etc/supervisor/conf.d/`, ajustar la ruta del proyecto y el usuario del
sistema, y correr:

```
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start arka01-queue-worker:* arka01-reverb:*
```

Con eso, si cualquiera de los dos procesos se cae (error, reinicio del
servidor), Supervisor lo vuelve a levantar solo.

## 2. El scheduler (cron)

El scheduler de Laravel (`App\Console\Kernel::schedule()`) necesita UNA sola
entrada de cron que lo dispare cada minuto — desde ahí, Laravel decide solo
cuáles de sus tareas programadas (`everyTwoMinutes()`, `dailyAt('05:00')`,
etc.) le toca correr en cada minuto:

```
* * * * * cd /var/www/arka01 && php artisan schedule:run >> /dev/null 2>&1
```

Se agrega con `crontab -e` (del usuario que corre la app, ej. `www-data`).

## 3. Verificar que quedó bien

```
sudo supervisorctl status
```

Debería mostrar `arka01-queue-worker:00`, `arka01-queue-worker:01` y
`arka01-reverb` en estado `RUNNING`. Los logs de cada uno quedan en
`storage/logs/queue-worker.log` y `storage/logs/reverb.log`.
