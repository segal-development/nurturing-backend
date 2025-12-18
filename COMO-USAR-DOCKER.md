# 🐳 Cómo Usar Docker - Guía Simple

## ✨ Opción 1: UN SOLO COMANDO (La más fácil)

```bash
./docker-quick-start.sh
```

**Eso es todo!** El script hace todo automáticamente:
- ✅ Construye las imágenes
- ✅ Inicia todos los servicios
- ✅ Ejecuta migraciones
- ✅ Verifica que todo funciona

---

## 🎯 Opción 2: Comandos con Make (Recomendado)

### Primera vez (Setup completo):
```bash
make setup
```

### Comandos del día a día:

```bash
# Iniciar el sistema
make up

# Ver logs de todo
make logs

# Ver logs solo del queue worker (importante para FlowBuilder)
make logs-queue

# Ver estado de ejecuciones de flujos
make queue-monitor

# Reiniciar queue worker (después de cambiar código en Jobs)
make restart-queue

# Detener el sistema
make down

# Ver todos los comandos disponibles
make help
```

---

## 🔧 Opción 3: Comandos Docker Compose (Manual)

Si prefieres usar Docker Compose directamente:

### 1️⃣ Primera vez - Construir e iniciar:
```bash
docker-compose -f docker-compose.prod.yml up -d --build
```

**Qué hace:**
- `up`: Inicia los servicios
- `-d`: En modo background (detached)
- `--build`: Construye las imágenes primero

### 2️⃣ Ejecutar migraciones (solo primera vez):
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### 3️⃣ Verificar que todo funciona:
```bash
# Ver que todos los contenedores estén corriendo
docker-compose -f docker-compose.prod.yml ps

# Debería mostrar:
# laravel-app           ✅ Up
# nginx-prod            ✅ Up
# postgres-prod         ✅ Up (healthy)
# redis-prod            ✅ Up (healthy)
# laravel-queue-worker  ✅ Up
# laravel-scheduler     ✅ Up
```

---

## 📊 Comandos Útiles para el Día a Día

### Ver Logs (Monitoreo)

```bash
# Ver logs de TODOS los servicios en tiempo real
docker-compose -f docker-compose.prod.yml logs -f

# Ver logs solo del Queue Worker (para FlowBuilder)
docker-compose -f docker-compose.prod.yml logs -f queue

# Ver logs de la aplicación
docker-compose -f docker-compose.prod.yml logs -f app

# Ver últimas 50 líneas de logs
docker-compose -f docker-compose.prod.yml logs --tail=50 queue
```

### Ejecutar Comandos de Laravel

```bash
# Artisan commands
docker-compose -f docker-compose.prod.yml exec app php artisan migrate
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes
docker-compose -f docker-compose.prod.yml exec app php artisan cache:clear

# Tinker (consola interactiva)
docker-compose -f docker-compose.prod.yml exec app php artisan tinker

# Ejemplo en tinker:
# >>> \App\Models\Flujo::count()
# >>> Redis::ping()
```

### Reiniciar Servicios

```bash
# Reiniciar TODO
docker-compose -f docker-compose.prod.yml restart

# Reiniciar solo el queue worker (IMPORTANTE después de cambiar Jobs)
docker-compose -f docker-compose.prod.yml restart queue

# Reiniciar solo la app
docker-compose -f docker-compose.prod.yml restart app
```

### Acceder a Contenedores

```bash
# Abrir bash en el contenedor de la app
docker-compose -f docker-compose.prod.yml exec app bash

# Abrir Redis CLI
docker-compose -f docker-compose.prod.yml exec redis redis-cli

# Abrir PostgreSQL
docker-compose -f docker-compose.prod.yml exec postgres psql -U segal -d nurturing
```

### Detener el Sistema

```bash
# Detener todos los servicios (mantiene volúmenes/data)
docker-compose -f docker-compose.prod.yml down

# Detener Y eliminar volúmenes (¡BORRA LA BASE DE DATOS!)
docker-compose -f docker-compose.prod.yml down -v
```

---

## 🚀 Workflow Típico de Desarrollo con Docker

### Día 1 - Configuración inicial:
```bash
# 1. Iniciar todo
./docker-quick-start.sh

# 2. Abrir en navegador
# http://localhost
```

### Día 2+ - Trabajando normalmente:
```bash
# Al iniciar el día
make up
make logs-queue  # Dejar corriendo en una terminal

# ... trabajar en el código ...

# Si cambias algo en Jobs (EnviarEtapaJob, VerificarCondicionJob)
make restart-queue

# Al terminar el día
make down
```

---

## 🧪 Probar el Sistema FlowBuilder

### 1. Ver que el queue worker está corriendo:
```bash
make logs-queue

# Deberías ver algo como:
# [2025-11-27 10:00:00] Processing: App\Jobs\EnviarEtapaJob
```

### 2. Verificar estado de ejecuciones:
```bash
make queue-monitor

# O manualmente:
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes
```

### 3. Ejecutar un flujo (desde frontend o curl):
```bash
curl -X POST http://localhost/api/flujos/1/ejecutar \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TU_TOKEN" \
  -d '{
    "origen_id": "manual",
    "prospectos_ids": [1, 2, 3]
  }'
```

### 4. Monitorear la ejecución:
```bash
# Terminal 1: Ver logs del queue
make logs-queue

# Terminal 2: Ver estado cada 30 segundos
watch -n 30 'make queue-monitor'
```

---

## 🔍 Solución de Problemas

### ❌ Error: "Cannot connect to Redis"
```bash
# Verificar que Redis esté corriendo
docker-compose -f docker-compose.prod.yml ps redis

# Debería decir: Up (healthy)

# Si no, reiniciar Redis
docker-compose -f docker-compose.prod.yml restart redis
```

### ❌ Error: "Queue worker not processing jobs"
```bash
# 1. Ver logs del worker
make logs-queue

# 2. Reiniciar el worker
make restart-queue

# 3. Verificar manualmente
docker-compose -f docker-compose.prod.yml exec app php artisan queue:work --once
```

### ❌ Error: "Cannot connect to database"
```bash
# Verificar PostgreSQL
docker-compose -f docker-compose.prod.yml ps postgres

# Ver logs
docker-compose -f docker-compose.prod.yml logs postgres

# Reiniciar
docker-compose -f docker-compose.prod.yml restart postgres
```

### ❌ Jobs no se ejecutan en las fechas programadas
```bash
# 1. Verificar que uses Redis (no database)
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
# >>> config('queue.default')
# Debe decir: "redis"

# 2. Verificar que el worker esté corriendo
make logs-queue

# 3. Ver jobs en Redis
docker-compose -f docker-compose.prod.yml exec redis redis-cli
> KEYS *
> LLEN queues:default
```

---

## 📝 Cheat Sheet - Comandos Rápidos

```bash
# Iniciar
make up                    # o: ./docker-quick-start.sh

# Ver logs
make logs-queue           # Logs del queue worker
make logs-app             # Logs de la app

# Estado
make ps                   # Ver contenedores
make queue-monitor        # Estado de ejecuciones

# Mantenimiento
make restart-queue        # Reiniciar worker (después de cambios)
make shell                # Acceder al contenedor
make tinker               # Laravel Tinker

# Detener
make down                 # Detener todo
```

---

## 🎯 Siguiente Paso

Después de iniciar el sistema con Docker:

1. ✅ Verifica que todo esté corriendo: `make ps`
2. ✅ Abre http://localhost en tu navegador
3. ✅ Monitorea el queue worker: `make logs-queue`
4. ✅ Ejecuta un flujo desde el frontend
5. ✅ Observa los logs en tiempo real

**¡Ya está todo listo para usar el FlowBuilder con Redis!** 🚀
