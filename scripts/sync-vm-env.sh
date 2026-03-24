#!/bin/bash
# =============================================================================
# Script para sincronizar variables de entorno críticas a la VM de workers
# 
# Uso: ./scripts/sync-vm-env.sh [qa|production]
#
# Este script asegura que la VM tenga las variables de entorno necesarias
# para acceder a GCS y otros servicios de GCP.
# =============================================================================

set -e

ENV=${1:-qa}
VM_NAME="nurturing-worker"
VM_ZONE="us-central1-a"
VM_USER="mtoro6"
BACKEND_PATH="/home/${VM_USER}/nurturing-backend"

echo "=== Sync VM Environment Variables ==="
echo "Environment: ${ENV}"
echo "VM: ${VM_NAME} (${VM_ZONE})"
echo ""

# Variables requeridas para GCS y servicios
REQUIRED_VARS=(
    "GOOGLE_CLOUD_PROJECT_ID=grupo-segal"
    "GOOGLE_CLOUD_STORAGE_BUCKET=nurturing-imports-${ENV}"
    "FILESYSTEM_DISK=gcs"
    "QUEUE_CONNECTION=database"
)

echo "Verificando y agregando variables..."

for VAR in "${REQUIRED_VARS[@]}"; do
    VAR_NAME="${VAR%%=*}"
    VAR_VALUE="${VAR#*=}"
    
    echo -n "  ${VAR_NAME}... "
    
    # Verificar si la variable ya existe en el .env de la VM
    EXISTS=$(gcloud compute ssh ${VM_NAME} --zone=${VM_ZONE} --command="grep -c '^${VAR_NAME}=' ${BACKEND_PATH}/.env 2>/dev/null || echo 0")
    
    if [ "$EXISTS" -eq "0" ]; then
        # Agregar la variable
        gcloud compute ssh ${VM_NAME} --zone=${VM_ZONE} --command="echo '${VAR_NAME}=${VAR_VALUE}' >> ${BACKEND_PATH}/.env"
        echo "ADDED"
    else
        echo "EXISTS"
    fi
done

echo ""
echo "Limpiando cache de configuración..."
gcloud compute ssh ${VM_NAME} --zone=${VM_ZONE} --command="cd ${BACKEND_PATH} && php artisan config:clear"

echo ""
echo "Reiniciando workers..."
gcloud compute ssh ${VM_NAME} --zone=${VM_ZONE} --command="sudo supervisorctl restart nurturing-worker:*"

echo ""
echo "=== Sync completado ==="
echo ""
echo "Variables en .env de la VM:"
gcloud compute ssh ${VM_NAME} --zone=${VM_ZONE} --command="grep -E '^(GOOGLE_|FILESYSTEM_|QUEUE_)' ${BACKEND_PATH}/.env"
