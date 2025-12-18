# ✅ Comandos Correctos para Docker Desktop

## ⚠️ IMPORTANTE: Sintaxis Nueva

Docker Desktop usa `docker compose` (SIN guión) en lugar de `docker-compose` (con guión).

**Correcto:** `docker compose` ✅
**Incorrecto:** `docker-compose` ❌

---

## 🚀 EJECUTAR AHORA - Comandos Correctos

### **Método 1: Automático (Recomendado)**
```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
./docker-quick-start.sh
```
✅ El script ya está actualizado con la sintaxis correcta

---

### **Método 2: Manual Paso a Paso**

```bash
# 1. Ir a la carpeta
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend

# 2. Limpiar contenedores anteriores (opcional)
docker compose -f docker-compose.prod.yml down -v

# 3. Construir imágenes
docker compose -f docker-compose.prod.yml build

# 4. Iniciar servicios
docker compose -f docker-compose.prod.yml up -d

# 5. Esperar 10 segundos
sleep 10

# 6. Ver estado
docker compose -f docker-compose.prod.yml ps

# 7. Ejecutar migraciones
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force

# 8. Ejecutar seeders
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force

# 9. Verificar Redis
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo Redis::ping() ? 'Redis: CONECTADO' : 'Redis: NO CONECTADO';"

# 10. Ver logs del queue
docker compose -f docker-compose.prod.yml logs -f queue
```

---

### **Método 3: Con Makefile**
```bash
# Setup completo
make setup

# Ver logs
make logs-queue

# Ver estado
make ps

# Detener
make down
```

---

## 📋 Comandos del Día a Día

### Iniciar/Detener:
```bash
# Iniciar
docker compose -f docker-compose.prod.yml up -d

# Detener
docker compose -f docker-compose.prod.yml down

# Ver estado
docker compose -f docker-compose.prod.yml ps
```

### Ver Logs:
```bash
# Todos los servicios
docker compose -f docker-compose.prod.yml logs -f

# Solo queue worker
docker compose -f docker-compose.prod.yml logs -f queue

# Solo app
docker compose -f docker-compose.prod.yml logs -f app

# Últimas 50 líneas
docker compose -f docker-compose.prod.yml logs --tail=50 queue
```

### Ejecutar Comandos:
```bash
# Artisan commands
docker compose -f docker-compose.prod.yml exec app php artisan migrate
docker compose -f docker-compose.prod.yml exec app php artisan db:seed
docker compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes

# Tinker
docker compose -f docker-compose.prod.yml exec app php artisan tinker

# Bash
docker compose -f docker-compose.prod.yml exec app bash

# Redis CLI
docker compose -f docker-compose.prod.yml exec redis redis-cli
```

### Reiniciar Servicios:
```bash
# Reiniciar todo
docker compose -f docker-compose.prod.yml restart

# Solo queue worker (importante después de cambiar Jobs)
docker compose -f docker-compose.prod.yml restart queue

# Solo app
docker compose -f docker-compose.prod.yml restart app
```

---

## 🧹 Limpiar Todo

```bash
# Detener y eliminar contenedores + volúmenes (borra base de datos)
docker compose -f docker-compose.prod.yml down -v

# Solo detener (mantiene datos)
docker compose -f docker-compose.prod.yml down
```

---

## ✅ Verificación Rápida

```bash
# ¿Docker funciona?
docker version

# ¿Docker Compose funciona?
docker compose version

# ¿Contenedores corriendo?
docker compose -f docker-compose.prod.yml ps

# ¿Redis conectado?
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo Redis::ping() ? 'OK' : 'FAIL';"
```

---

## 🎯 Resumen para Ti

**ANTES (Incorrecto):**
```bash
docker-compose -f docker-compose.prod.yml up -d  ❌
```

**AHORA (Correcto):**
```bash
docker compose -f docker-compose.prod.yml up -d  ✅
```

**La diferencia:** Sin guión entre `docker` y `compose`

---

## 🚀 Ejecuta Esto Ahora

```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
./docker-quick-start.sh
```

O si prefieres manual:

```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
docker compose -f docker-compose.prod.yml down -v
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
sleep 10
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker compose -f docker-compose.prod.yml exec app php artisan db:seed --force
docker compose -f docker-compose.prod.yml ps
```

¡Listo! 🎉