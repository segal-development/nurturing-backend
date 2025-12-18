#!/bin/bash

MODE=$1

if [ -z "$MODE" ]; then
    echo "❌ Uso: ./switch-mode.sh [local|docker]"
    echo ""
    echo "Modos disponibles:"
    echo "  local  - Usa PostgreSQL local (127.0.0.1:5434) y database queue"
    echo "  docker - Usa PostgreSQL Docker (postgres:5432) y Redis queue"
    exit 1
fi

if [ "$MODE" = "local" ]; then
    echo "🔄 Cambiando a modo LOCAL..."

    # Backup .env actual
    cp .env .env.backup

    # Configurar para desarrollo local
    sed -i.bak 's/^DB_HOST=.*/DB_HOST=127.0.0.1/' .env
    sed -i.bak 's/^DB_PORT=.*/DB_PORT=5434/' .env
    sed -i.bak 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' .env
    sed -i.bak 's/^CACHE_STORE=.*/CACHE_STORE=database/' .env
    sed -i.bak 's/^REDIS_HOST=.*/REDIS_HOST=127.0.0.1/' .env

    rm .env.bak

    echo "✅ Configurado para modo LOCAL"
    echo ""
    echo "📝 Configuración:"
    echo "  DB_HOST: 127.0.0.1:5434"
    echo "  QUEUE: database"
    echo "  CACHE: database"
    echo ""
    echo "🚀 Para iniciar:"
    echo "  composer dev"
    echo ""
    echo "🌐 URLs:"
    echo "  Backend: http://localhost:8000"
    echo "  Frontend .env: VITE_API_URL=http://localhost:8000"

elif [ "$MODE" = "docker" ]; then
    echo "🔄 Cambiando a modo DOCKER..."

    # Backup .env actual
    cp .env .env.backup

    # Configurar para Docker
    sed -i.bak 's/^DB_HOST=.*/DB_HOST=postgres/' .env
    sed -i.bak 's/^DB_PORT=.*/DB_PORT=5432/' .env
    sed -i.bak 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=redis/' .env
    sed -i.bak 's/^CACHE_STORE=.*/CACHE_STORE=redis/' .env
    sed -i.bak 's/^REDIS_HOST=.*/REDIS_HOST=redis/' .env

    rm .env.bak

    echo "✅ Configurado para modo DOCKER"
    echo ""
    echo "📝 Configuración:"
    echo "  DB_HOST: postgres (container)"
    echo "  QUEUE: redis"
    echo "  CACHE: redis"
    echo ""
    echo "🚀 Para iniciar:"
    echo "  docker-compose -f docker-compose.prod.yml up -d"
    echo ""
    echo "🌐 URLs:"
    echo "  Backend: http://localhost"
    echo "  Frontend .env: VITE_API_URL=http://localhost"
    echo ""
    echo "⚠️  IMPORTANTE: Cambia la URL del API en el frontend!"

else
    echo "❌ Modo desconocido: $MODE"
    echo "Usa: local o docker"
    exit 1
fi
