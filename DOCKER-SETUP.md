# 🐳 Docker Setup - Nurturing Backend

Este documento explica cómo montar y usar el sistema con Docker + Redis para pruebas y producción.

## 📋 Requisitos Previos

- Docker 20.10+
- Docker Compose 2.0+

## 🚀 Cómo Montar el Sistema

### 1. Configurar Variables de Entorno

Primero, asegúrate de actualizar tu `.env` para usar Redis:

```bash
# Queue & Cache
QUEUE_CONNECTION=redis
CACHE_STORE=redis

# Redis Configuration
REDIS_HOST=redis
REDIS_PORT=6379

# Database (ya configurado para Docker)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=nurturing
DB_USERNAME=segal
DB_PASSWORD=password
```

### 2. Iniciar los Contenedores

```bash
# Construir e iniciar todos los servicios
docker-compose -f docker-compose.prod.yml up -d --build

# Ver logs en tiempo real
docker-compose -f docker-compose.prod.yml logs -f

# Ver logs de un servicio específico
docker-compose -f docker-compose.prod.yml logs -f queue
docker-compose -f docker-compose.prod.yml logs -f app
```

### 3. Ejecutar Migraciones (Primera vez)

```bash
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### 4. Verificar que Todo Funciona

```bash
# Verificar conexión a Redis
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="Redis::ping()"

# Verificar queue worker
docker-compose -f docker-compose.prod.yml exec queue php artisan queue:work --once

# Verificar estado de ejecuciones
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes
```

## 🏗️ Arquitectura de Contenedores

El sistema incluye **6 servicios**:

### 1. **app** (Laravel PHP-FPM)
- Puerto: Interno (9000)
- Función: Procesa requests PHP
- Extensiones: PostgreSQL, Redis, pcntl

### 2. **nginx** (Web Server)
- Puerto: 80 → http://localhost
- Función: Servidor web que envía requests a `app`

### 3. **postgres** (Base de Datos)
- Puerto: 5434 → localhost:5434
- Función: Base de datos principal
- Volumen persistente: `pg_data_prod`

### 4. **redis** (Cache & Queue)
- Puerto: 6379 → localhost:6379
- Función: Queue backend + Cache
- Volumen persistente: `redis_data_prod`
- Healthcheck: Verifica disponibilidad

### 5. **queue** (Queue Worker)
- Sin puerto expuesto
- Función: Procesa jobs de la queue
- Comando: `queue:work redis --sleep=3 --tries=3 --timeout=300`
- **MUY IMPORTANTE** para FlowBuilder (procesa EnviarEtapaJob y VerificarCondicionJob)

### 6. **scheduler** (Laravel Scheduler)
- Sin puerto expuesto
- Función: Ejecuta tareas programadas cada minuto
- Para futuras tareas cron

## 🔧 Comandos Útiles

### Gestión de Contenedores

```bash
# Iniciar servicios
docker-compose -f docker-compose.prod.yml up -d

# Detener servicios
docker-compose -f docker-compose.prod.yml down

# Reiniciar un servicio específico
docker-compose -f docker-compose.prod.yml restart queue

# Ver estado de contenedores
docker-compose -f docker-compose.prod.yml ps

# Ver recursos (CPU, memoria)
docker stats
```

### Acceder a Contenedores

```bash
# Bash en el contenedor app
docker-compose -f docker-compose.prod.yml exec app bash

# Artisan commands
docker-compose -f docker-compose.prod.yml exec app php artisan migrate
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes

# Tinker (REPL de Laravel)
docker-compose -f docker-compose.prod.yml exec app php artisan tinker

# Limpiar cache
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
```

### Monitorear Queue

```bash
# Ver jobs en la queue de Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli
> LLEN queues:default
> LRANGE queues:default 0 10

# Monitorear queue worker
docker-compose -f docker-compose.prod.yml logs -f queue

# Reiniciar queue worker (importante después de cambios en Jobs)
docker-compose -f docker-compose.prod.yml restart queue
```

### Backups

```bash
# Backup de PostgreSQL
docker-compose -f docker-compose.prod.yml exec postgres pg_dump -U segal nurturing > backup.sql

# Restaurar backup
cat backup.sql | docker-compose -f docker-compose.prod.yml exec -T postgres psql -U segal -d nurturing

# Backup de Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli BGSAVE
```

## 🧪 Probar el Sistema de Ejecución FlowBuilder

### 1. Iniciar ejecución de un flujo

```bash
# Usando curl (desde tu máquina local)
curl -X POST http://localhost/api/flujos/{id}/ejecutar \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -d '{
    "origen_id": "manual",
    "prospectos_ids": [1, 2, 3]
  }'
```

### 2. Monitorear la ejecución

```bash
# Ver logs del queue worker
docker-compose -f docker-compose.prod.yml logs -f queue

# Verificar estado
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes

# Ver jobs en Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli KEYS "*"
```

### 3. Probar jobs delayed

```bash
# En tinker, crear un job de prueba
docker-compose -f docker-compose.prod.yml exec app php artisan tinker

# Dentro de tinker:
\App\Jobs\EnviarEtapaJob::dispatch(1, 1, [], [1,2,3], [])->delay(now()->addMinutes(2));

# Salir y monitorear
exit
docker-compose -f docker-compose.prod.yml logs -f queue
```

## 🐛 Solución de Problemas

### Redis no conecta
```bash
# Verificar que Redis esté corriendo
docker-compose -f docker-compose.prod.yml ps redis

# Ver logs de Redis
docker-compose -f docker-compose.prod.yml logs redis

# Reiniciar Redis
docker-compose -f docker-compose.prod.yml restart redis
```

### Queue Worker no procesa jobs
```bash
# Ver logs
docker-compose -f docker-compose.prod.yml logs queue

# Reiniciar worker (SIEMPRE después de cambios en código)
docker-compose -f docker-compose.prod.yml restart queue

# Verificar conexión a Redis
docker-compose -f docker-compose.prod.yml exec queue php artisan queue:work --once
```

### PostgreSQL no conecta
```bash
# Verificar health check
docker-compose -f docker-compose.prod.yml ps postgres

# Ver logs
docker-compose -f docker-compose.prod.yml logs postgres

# Conectar manualmente
docker-compose -f docker-compose.prod.yml exec postgres psql -U segal -d nurturing
```

### Permisos en storage/
```bash
# Arreglar permisos
docker-compose -f docker-compose.prod.yml exec app chown -R www-data:www-data /var/www/storage
docker-compose -f docker-compose.prod.yml exec app chmod -R 775 /var/www/storage
```

## 🔒 Seguridad (Para Producción)

Antes de llevar a producción, cambia:

1. **Contraseñas de PostgreSQL** en `docker-compose.prod.yml`
2. **APP_KEY** en `.env`
3. **APP_DEBUG=false** en `.env`
4. **Agrega contraseña a Redis**:
   ```yaml
   redis:
     command: redis-server --requirepass YOUR_STRONG_PASSWORD
   ```
5. **Configura SSL/HTTPS** en Nginx

## 📊 Comparación: Database vs Redis Queue

| Aspecto | Database | Redis (Docker) |
|---------|----------|----------------|
| Setup | ✅ Ya incluido | ✅ En docker-compose |
| Performance | 🐌 Lento | ⚡ Muy rápido |
| Delayed Jobs | ✅ Funciona | ✅ Más preciso |
| Para FlowBuilder | ⚠️ Puede fallar con alta carga | ✅ RECOMENDADO |
| Desarrollo | ✅ Suficiente | ✅ Mejor |
| Producción | ❌ No recomendado | ✅ OBLIGATORIO |

## 🎯 Conclusión

**Para tu caso de uso (FlowBuilder con delays y condiciones):**

✅ **USA REDIS** - El docker-compose ya está configurado correctamente

**Ventajas:**
- ⚡ Jobs delayed se ejecutan en el momento exacto
- 🔄 Múltiples ejecuciones concurrentes sin problemas
- 📊 Mejor monitoreo con `redis-cli`
- 🚀 Preparado para producción

**Desventajas:**
- Ninguna (Docker maneja todo automáticamente)
