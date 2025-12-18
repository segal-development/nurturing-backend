#!/bin/bash

echo "🐳 Docker Quick Start - Nurturing Backend"
echo "=========================================="
echo ""

# Verificar si Docker está instalado
if ! command -v docker &> /dev/null; then
    echo "❌ Docker no está instalado. Por favor instala Docker Desktop."
    exit 1
fi

# Verificar si Docker Compose está disponible (nueva sintaxis)
if ! docker compose version &> /dev/null; then
    echo "❌ Docker Compose no está disponible."
    echo "   Asegúrate de tener Docker Desktop instalado y corriendo."
    exit 1
fi

echo "✅ Docker detectado y funcionando"
echo ""

# Verificar si existe .env
if [ ! -f .env ]; then
    echo "⚠️  No existe archivo .env"
    if [ -f .env.docker.example ]; then
        echo "📋 Copiando .env.docker.example a .env"
        cp .env.docker.example .env
        echo "⚠️  IMPORTANTE: Edita .env y configura APP_KEY y ATHENACAMPAIGN_API_KEY"
        echo ""
        read -p "Presiona Enter cuando hayas configurado .env..."
    else
        echo "❌ No se encontró .env.docker.example"
        exit 1
    fi
fi

echo "1️⃣  Deteniendo contenedores existentes..."
docker compose -f docker-compose.prod.yml down 2>/dev/null

echo ""
echo "2️⃣  Construyendo imágenes Docker..."
docker compose -f docker-compose.prod.yml build

echo ""
echo "3️⃣  Iniciando servicios..."
docker compose -f docker-compose.prod.yml up -d

echo ""
echo "4️⃣  Esperando que PostgreSQL esté listo..."
sleep 5

echo ""
echo "5️⃣  Ejecutando migraciones..."
docker compose -f docker-compose.prod.yml exec -T app php artisan migrate --force

echo ""
echo "6️⃣  Ejecutando seeders (roles, permisos, tipos de prospecto, configuración)..."
docker compose -f docker-compose.prod.yml exec -T app php artisan db:seed --force

echo ""
echo "7️⃣  Verificando conexión a Redis..."
docker compose -f docker-compose.prod.yml exec -T app php artisan tinker --execute="echo Redis::ping() ? '✅ Redis: CONECTADO' : '❌ Redis: NO CONECTADO'; echo PHP_EOL;"

echo ""
echo "✅ ¡Sistema iniciado correctamente!"
echo ""
echo "📊 Información de los servicios:"
echo "  🌐 Aplicación:  http://localhost"
echo "  🗄️  PostgreSQL:  localhost:5434 (usuario: segal, db: nurturing)"
echo "  🔴 Redis:       localhost:6379"
echo ""
echo "📝 Comandos útiles:"
echo "  Ver logs:               docker compose -f docker-compose.prod.yml logs -f"
echo "  Ver logs de queue:      docker compose -f docker-compose.prod.yml logs -f queue"
echo "  Estado de ejecuciones:  docker compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes"
echo "  Detener sistema:        docker compose -f docker-compose.prod.yml down"
echo ""
echo "O usa el Makefile:"
echo "  make logs              # Ver todos los logs"
echo "  make logs-queue        # Ver logs del queue worker"
echo "  make queue-monitor     # Ver estado de ejecuciones"
echo "  make help              # Ver todos los comandos disponibles"
echo ""
