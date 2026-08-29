#!/bin/bash
# Despliega una actualización de Arka01 ya en producción — para el primer
# despliegue completo, ver deploy/README.md (bootstrap-server.sh primero).
#
# Corré esto por SSH, parado en /var/www/arka01, cada vez que haya cambios
# nuevos para llevar a producción:
#   cd /var/www/arka01 && bash deploy/deploy.sh

set -euo pipefail
cd "$(dirname "$0")/.."

echo "== Preparando permisos de Laravel =="
# Artisan y PHP-FPM escriben con usuarios distintos. Se normalizan directorios
# y archivos ANTES del primer comando de Artisan para que incluso un error de
# migración pueda quedar registrado y no oculte la causa original.
mkdir -p storage/logs bootstrap/cache
sudo chown -R "$(id -un)":www-data storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} +
sudo find storage bootstrap/cache -type f -exec chmod 664 {} +

maintenance_enabled=0
restore_application() {
  if [ "$maintenance_enabled" -eq 1 ]; then
    echo "== El despliegue falló: restaurando la aplicación =="
    php artisan up || true
  fi
}
trap restore_application EXIT

echo "== Modo mantenimiento =="
php artisan down --render="errors::503"
maintenance_enabled=1

echo "== Código nuevo =="
git pull origin main

echo "== Dependencias =="
composer install --no-dev --optimize-autoloader
npm ci
npm run build
(cd radio-server && npm ci)

echo "== Base de datos =="
php artisan migrate --force

echo "== Cachear config/rutas/vistas =="
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "== Reiniciando procesos =="
# Recarga PHP-FPM (invalida opcache.validate_timestamps=0, si no seguiría
# sirviendo el código viejo desde memoria) y los procesos de fondo.
sudo systemctl reload php8.4-fpm
sudo supervisorctl restart arka01-queue-worker:* arka01-reverb:* arka01-radio-server:*

echo "== Saliendo de mantenimiento =="
php artisan up
maintenance_enabled=0
trap - EXIT

echo "Listo."
