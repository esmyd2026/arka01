#!/bin/bash
# Aprovisiona la infraestructura de GCP para el primer despliegue de Arka01
# (piloto económico — ver deploy/README.md para el contexto completo).
#
# Corré esto en Google Cloud Shell (shell.cloud.google.com) — ya viene con
# gcloud instalado y autenticado con tu cuenta, no hace falta instalar nada
# en tu máquina. Pegá el script completo, o clonar el repo ahí y correr:
#   bash deploy/gcloud-provision.sh
#
# Antes de correr: completá PROJECT_ID acá abajo con el ID real de tu
# proyecto de GCP (Consola > selector de proyecto, es el "ID", no el
# "nombre" — suele tener guiones/números).

set -euo pipefail

# --- Variables (revisar antes de correr) ---
PROJECT_ID="arka01-504618"
REGION="us-central1"
ZONE="us-central1-a"
VM_NAME="arka01-vm"
BUCKET_NAME="arka01-backups-${PROJECT_ID}"

gcloud config set project "$PROJECT_ID"

echo "== 1. Habilitando APIs necesarias =="
gcloud services enable compute.googleapis.com iap.googleapis.com storage.googleapis.com

echo "== 2. Reservando IP estática (para apuntar el DNS de arka01.com) =="
gcloud compute addresses create arka01-ip --region="$REGION" || echo "Ya existe, sigo."
echo "IP estática asignada:"
gcloud compute addresses describe arka01-ip --region="$REGION" --format='get(address)'

echo "== 3. Reglas de firewall =="
# 80/443 abiertos al mundo (tráfico normal de la app).
gcloud compute firewall-rules create arka01-allow-web \
  --network=default --direction=INGRESS --action=ALLOW \
  --rules=tcp:80,tcp:443 --source-ranges=0.0.0.0/0 --target-tags=arka01-web \
  || echo "Ya existe, sigo."

# SSH restringido al rango de Identity-Aware Proxy (más seguro que abrir el
# 22 al mundo entero, sin costo extra) — así te conectás con
# `gcloud compute ssh --tunnel-through-iap` más abajo.
gcloud compute firewall-rules create arka01-allow-iap-ssh \
  --network=default --direction=INGRESS --action=ALLOW \
  --rules=tcp:22 --source-ranges=35.235.240.0/20 --target-tags=arka01-web \
  || echo "Ya existe, sigo."

echo "== 4. Creando la VM (e2-small, Ubuntu 22.04 LTS, 30GB) =="
gcloud compute instances create "$VM_NAME" \
  --zone="$ZONE" --machine-type=e2-small \
  --image-family=ubuntu-2204-lts --image-project=ubuntu-os-cloud \
  --boot-disk-size=30GB --boot-disk-type=pd-balanced \
  --address=arka01-ip --tags=arka01-web \
  --scopes=cloud-platform \
  || echo "Ya existe, sigo."

echo "== 5. Bucket para backups (Nearline, barato para poco volumen) =="
gcloud storage buckets create "gs://${BUCKET_NAME}" \
  --location="$REGION" --default-storage-class=NEARLINE --uniform-bucket-level-access \
  || echo "Ya existe, sigo."

echo "== 6. Snapshot diario automático del disco completo (código + DB + storage/) =="
gcloud compute resource-policies create snapshot-schedule arka01-daily-snapshot \
  --region="$REGION" --daily-schedule --start-time=07:00 \
  --max-retention-days=7 --storage-location="$REGION" \
  || echo "Ya existe, sigo."

gcloud compute disks add-resource-policies "$VM_NAME" \
  --resource-policies=arka01-daily-snapshot --zone="$ZONE" \
  || echo "Ya estaba asociada, sigo."

echo
echo "== Listo =="
echo "1. Apuntá un registro A de arka01.com (y www.arka01.com) a la IP de arriba, en tu proveedor de dominio."
echo "2. Conectate por SSH con:"
echo "   gcloud compute ssh ${VM_NAME} --zone=${ZONE} --tunnel-through-iap"
echo "3. Ahí adentro, seguí con deploy/bootstrap-server.sh (ver deploy/README.md)."
