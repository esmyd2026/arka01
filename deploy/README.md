# Desplegar Arka01 en Google Cloud Platform (piloto económico)

Guía completa para el primer despliegue, pensada para una VM única
(Compute Engine) — la app usa discos de archivos locales de Laravel
(licencias, comprobantes de pago, fotos de vehículo), así que una sola
máquina con disco persistente es más simple y económica que separar en
varios servicios gestionados (Cloud Run + Cloud SQL + Cloud Storage) para
arrancar un piloto.

Costo estimado: ~$18-22/mes (`e2-small` + disco + snapshots + backups) —
con $300 de crédito por 86 días, sobra margen de sobra.

## Orden de ejecución

### 1. Aprovisionar la infraestructura (Google Cloud Shell)

Abrí [Cloud Shell](https://shell.cloud.google.com) (ya autenticado con tu
cuenta, no hace falta instalar nada), cloná el repo ahí o pegá el script, y
completá `PROJECT_ID` adentro del archivo antes de correr:

```
bash deploy/gcloud-provision.sh
```

Esto crea: la VM (`e2-small`, Ubuntu 22.04 LTS, us-central1), una IP
estática, las reglas de firewall (80/443 abiertos; SSH solo por el rango de
IAP, más seguro), un bucket para backups, y un snapshot diario automático
del disco completo.

Al terminar, apuntá un registro **A** de `arka01.com` (y `www.arka01.com`)
a la IP estática que imprime el script, en tu proveedor de dominio.

### 2. Conectarse por SSH

```
gcloud compute ssh arka01-vm --zone=us-central1-a --tunnel-through-iap
```

### 3. Preparar el servidor (una sola vez)

```
sudo bash deploy/bootstrap-server.sh
```

Instala Nginx, PHP 8.4-FPM + extensiones, MySQL, Composer, Node.js (solo
para compilar assets durante el deploy), Git, Supervisor y Certbot — 8.4 y
no 8.3 porque `composer.lock` quedó resuelto con paquetes de Symfony que
piden PHP >=8.4 (se generó en una máquina con PHP más nuevo). También
ajusta `php.ini`, hace tuning de MySQL para los 2GB de RAM del `e2-small`, y
crea un swapfile de 1GB de respaldo.

### 4. Base de datos

```
sudo mysql -e "
CREATE DATABASE arka01 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'arka01'@'localhost' IDENTIFIED BY 'UNA_PASSWORD_FUERTE_ACA';
GRANT ALL PRIVILEGES ON arka01.* TO 'arka01'@'localhost';
FLUSH PRIVILEGES;
"
```

(El `root` de MySQL en Ubuntu se autentica por socket del sistema —
`sudo mysql` alcanza, no hace falta `mysql_secure_installation` completo.)

### 5. Clonar y desplegar la app

```
sudo mkdir -p /var/www/arka01 && sudo chown $USER:$USER /var/www/arka01
git clone <URL_DE_TU_REPO> /var/www/arka01
cd /var/www/arka01

cp deploy/.env.production.example .env
nano .env   # completar DB_PASSWORD, REVERB_APP_ID/KEY/SECRET, MAIL_*, etc.
            # (ver los comentarios "[COMPLETAR]" dentro del archivo)

composer install --no-dev --optimize-autoloader
npm ci
npm run build          # genera public/build/ (no está en git)

php artisan key:generate
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
```

### 6. Nginx

```
sudo ln -s /var/www/arka01/deploy/nginx-arka01.conf /etc/nginx/sites-available/arka01.conf
sudo ln -s /etc/nginx/sites-available/arka01.conf /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 7. Queue worker y Reverb (Supervisor)

Los `.conf` ya están armados en esta carpeta:

```
sudo cp deploy/supervisor-queue-worker.conf /etc/supervisor/conf.d/arka01-queue-worker.conf
sudo cp deploy/supervisor-reverb.conf /etc/supervisor/conf.d/arka01-reverb.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start arka01-queue-worker:* arka01-reverb:*
```

| Proceso | Qué pasa si se cae | Cómo mantenerlo vivo |
|---|---|---|
| `php artisan queue:work` | Los Jobs (avisos de WhatsApp, notificaciones push, etc.) se acumulan sin procesarse. | `supervisor-queue-worker.conf` |
| `php artisan reverb:start` | "En vivo" deja de actualizarse — el resto sigue por HTTP normal. | `supervisor-reverb.conf` |
| El scheduler (`drivers:sweep-stale-availability`, `express:generate-rides`) | Los conductores "fantasma" no se desconectan solos, y los Expresos no generan su carrera del día. | Una línea de cron (siguiente paso) |

### 8. El scheduler (cron)

```
(crontab -l -u www-data 2>/dev/null; echo "* * * * * cd /var/www/arka01 && php artisan schedule:run >> /dev/null 2>&1") | sudo crontab -u www-data -
```

### 9. HTTPS (Certbot)

```
sudo certbot --nginx -d arka01.com -d www.arka01.com
```

Certbot edita `deploy/nginx-arka01.conf` (el que quedó enlazado en
`sites-enabled`) para agregar el bloque 443 y el redirect 80→443 — no hace
falta tocarlo de nuevo, y se renueva solo.

### 10. Backups de la base de datos (cuando esté estable)

```
echo 'LA_MISMA_PASSWORD_DEL_PASO_4' | sudo tee /root/.arka01-db-pass
sudo chmod 600 /root/.arka01-db-pass
# Editar BUCKET dentro de deploy/backup-mysql.sh con el nombre real del bucket
sudo crontab -e
# agregar: 0 6 * * * /var/www/arka01/deploy/backup-mysql.sh >> /var/log/arka01-backup.log 2>&1
```

Esto complementa el snapshot diario del disco completo (ya configurado en
el paso 1) — más rápido de restaurar cuando solo hace falta la base.

## 3. Verificar que quedó todo bien

```
sudo supervisorctl status
```

Debería mostrar `arka01-queue-worker:00`, `arka01-queue-worker:01` y
`arka01-reverb` en estado `RUNNING`. Logs en `storage/logs/queue-worker.log`
y `storage/logs/reverb.log`.

```
curl -I https://arka01.com
```

Debería devolver `200` con los headers de seguridad de
`App\Http\Middleware\SecurityHeaders` (CSP, HSTS, etc.).

Después, entrar a la app desde el navegador y confirmar que "en vivo"
funciona de verdad (ej. abrir "Mi flota" en una pestaña y activar
disponibilidad en otra) — si la consola del navegador muestra el error "No
se pudo conectar a Reverb" (`resources/js/bootstrap.js`), revisar que
`REVERB_HOST`/`REVERB_PORT`/`REVERB_SCHEME` en `.env` apunten al dominio
real y que `npm run build` se haya corrido DESPUÉS de completarlos (Vite
los hornea en el JS compilado, un `.env` editado después no alcanza sin
recompilar).

## Actualizaciones futuras

Una vez que la app ya está en producción, para llevar cambios nuevos:

```
cd /var/www/arka01 && bash deploy/deploy.sh
```

## Checklist de variables antes de invitar usuarios reales

Ver los comentarios `[COMPLETAR]` dentro de `deploy/.env.production.example`
— resumen:

**Recomendable antes de un piloto con gente real:**
`APP_KEY` fresco (se genera en el paso 5), `DB_PASSWORD` fuerte,
`REVERB_APP_ID/KEY/SECRET` propios, `MAIL_*` reales (en local apunta a
mailpit, que no existe en producción), `WHATSAPP_APP_SECRET` (sin esto el
webhook de WhatsApp acepta mensajes sin validar firma), `GOOGLE_REDIRECT_URI`
actualizado al dominio real (y en Google Cloud Console).

**Opcional — la app degrada con gracia sin esto, se puede completar
después:** `SENTRY_LARAVEL_DSN`, `GOOGLE_CLIENT_ID`/`GOOGLE_CLIENT_SECRET`,
`VITE_GOOGLE_MAPS_API_KEY`, `WHATSAPP_TOKEN`/`WHATSAPP_PHONE_NUMBER_ID`/
plantilla, `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` (push — generar con
`php artisan webpush:vapid`).
