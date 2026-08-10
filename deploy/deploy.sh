#!/bin/bash
# Despliega una actualización de Arka01 ya en producción — para el primer
# despliegue completo, ver deploy/README.md (bootstrap-server.sh primero).
#
# Corré esto por SSH, parado en /var/www/arka01, cada vez que haya cambios
# nuevos para llevar a producción:
#   cd /var/www/arka01 && bash deploy/deploy.sh

set -euo pipefail
cd "$(dirname "$0")/.."

echo "== Modo mantenimiento =="
php artisan down --render="errors::503" || true

echo "== Código nuevo =="
git pull origin main

echo "== Dependencias =="
composer install --no-dev --optimize-autoloader
npm ci
npm run build

echo "== Base de datos =="
php artisan migrate --force

echo "== Cachear config/rutas/vistas =="
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "== Reiniciando procesos =="
# Recarga PHP-FPM (invalida opcache.validate_timestamps=0, si no seguiría
# sirviendo el código viejo desde memoria) y los procesos de fondo.
sudo systemctl reload php8.3-fpm
sudo supervisorctl restart arka01-queue-worker:* arka01-reverb:*

echo "== Saliendo de mantenimiento =="
php artisan up

echo "Listo."
