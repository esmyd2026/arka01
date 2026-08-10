#!/bin/bash
# Backup diario de la base de datos de Arka01 a Cloud Storage — complementa
# el snapshot automático del disco completo (deploy/gcloud-provision.sh):
# este es más rápido de restaurar cuando solo hace falta la base, y guarda
# más historial de respaldos.
#
# Instalación (por SSH, una sola vez):
#   1. Guardar la password de MySQL en un archivo que solo lea root:
#        echo 'la_password_real' | sudo tee /root/.arka01-db-pass
#        sudo chmod 600 /root/.arka01-db-pass
#   2. Editar BUCKET más abajo con el nombre real del bucket (el que creó
#      deploy/gcloud-provision.sh).
#   3. Agregar el cron (corre todos los días a las 6am):
#        sudo crontab -e
#        # agregar la línea:
#        0 6 * * * /var/www/arka01/deploy/backup-mysql.sh >> /var/log/arka01-backup.log 2>&1

set -euo pipefail

DB_USER="arka01"
DB_NAME="arka01"
DB_PASS_FILE="/root/.arka01-db-pass"
BUCKET="gs://arka01-backups-tu-proyecto-gcp"   # <-- completar con el bucket real

FECHA=$(date +%F)
ARCHIVO="/tmp/arka01-${FECHA}.sql.gz"

mysqldump -u "$DB_USER" -p"$(cat "$DB_PASS_FILE")" "$DB_NAME" | gzip > "$ARCHIVO"
gcloud storage cp "$ARCHIVO" "${BUCKET}/"
rm "$ARCHIVO"

echo "Backup de ${FECHA} subido a ${BUCKET}."
