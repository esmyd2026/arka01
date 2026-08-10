#!/bin/bash
# Prepara un servidor Ubuntu 22.04 LTS recién creado (VM de
# deploy/gcloud-provision.sh) con todo lo que Arka01 necesita para correr:
# Nginx, PHP 8.3-FPM, MySQL, Composer, Node.js (solo para compilar assets en
# el deploy), Supervisor y Certbot.
#
# Corré esto UNA sola vez, por SSH, como root/con sudo:
#   sudo bash deploy/bootstrap-server.sh
#
# (Si todavía no clonaste el repo, podés pegar este archivo directo en el
# servidor y correrlo antes de tener el resto del código.)

set -euo pipefail

echo "== 1. Actualizando el sistema =="
apt update && apt upgrade -y

echo "== 2. PHP 8.3 (Ubuntu 22.04 trae 8.1 por defecto; Laravel 12 exige ^8.2) =="
apt install -y software-properties-common
add-apt-repository -y ppa:ondrej/php
apt update

echo "== 3. Instalando Nginx, PHP-FPM + extensiones, MySQL, Git, Supervisor, Certbot =="
apt install -y \
  nginx \
  php8.3-fpm php8.3-cli php8.3-common php8.3-mysql php8.3-mbstring \
  php8.3-xml php8.3-curl php8.3-zip php8.3-gd php8.3-bcmath php8.3-intl \
  php8.3-opcache \
  mysql-server \
  git unzip \
  supervisor \
  certbot python3-certbot-nginx

echo "== 4. Composer =="
if ! command -v composer >/dev/null 2>&1; then
  EXPECTED_SIG="$(curl -s https://composer.github.io/installer.sig)"
  curl -sS https://getcomposer.org/installer -o /tmp/composer-setup.php
  ACTUAL_SIG="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED_SIG" != "$ACTUAL_SIG" ]; then
    echo "Firma de Composer inválida, aborto." >&2
    exit 1
  fi
  php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm /tmp/composer-setup.php
fi

echo "== 5. Node.js 20 LTS (solo para 'npm run build' durante el deploy — public/build no se commitea) =="
if ! command -v node >/dev/null 2>&1; then
  curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
  apt install -y nodejs
fi

echo "== 6. Ajustes de php.ini (subida de fotos: hasta 6 x 4MB en Viajes VAN + comprobantes) =="
PHP_INI="/etc/php/8.3/fpm/php.ini"
sed -i "s/^upload_max_filesize = .*/upload_max_filesize = 10M/" "$PHP_INI"
sed -i "s/^post_max_size = .*/post_max_size = 32M/" "$PHP_INI"
sed -i "s/^memory_limit = .*/memory_limit = 256M/" "$PHP_INI"

echo "== 7. Opcache (rendimiento — deploy.sh recarga PHP-FPM para invalidarlo en cada deploy) =="
cat >> "$PHP_INI" <<'EOF'

; Arka01: opcache en producción, sin revalidar timestamps en cada request
; (deploy.sh hace `systemctl reload php8.3-fpm` después de cada deploy nuevo
; para que tome el código actualizado).
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=128
opcache.max_accelerated_files=10000
EOF

echo "== 8. Tuning de MySQL (e2-small tiene 2GB — sin esto, InnoDB se lleva la mayoría de la RAM) =="
cat > /etc/mysql/mysql.conf.d/arka01-tuning.cnf <<'EOF'
[mysqld]
innodb_buffer_pool_size = 256M
max_connections = 50
EOF

echo "== 9. Swapfile de 1GB (red de seguridad ante picos de memoria) =="
if [ ! -f /swapfile ]; then
  fallocate -l 1G /swapfile
  chmod 600 /swapfile
  mkswap /swapfile
  swapon /swapfile
  echo '/swapfile none swap sw 0 0' >> /etc/fstab
fi

echo "== 10. Arrancando servicios =="
systemctl restart php8.3-fpm mysql
systemctl enable nginx php8.3-fpm mysql supervisor
systemctl restart supervisor

echo
echo "== Listo =="
echo "Próximos pasos (ver deploy/README.md):"
echo "1. Crear la base de datos y el usuario de MySQL."
echo "2. Clonar el repo en /var/www/arka01 y completar el .env."
echo "3. Instalar deploy/nginx-arka01.conf y correr Certbot."
