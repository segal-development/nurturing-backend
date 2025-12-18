# 🚀 EJECUTAR DOCKER AHORA - Copia y Pega Estos Comandos

## 📍 PASO 1: Ir a la carpeta del proyecto

```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
```

---

## 🎯 MÉTODO RÁPIDO (1 solo comando):

```bash
./docker-quick-start.sh
```

**¡ESO ES TODO!** El script hace todo automáticamente.

---

## 🔧 MÉTODO MANUAL (paso a paso):

### 1. Construir las imágenes Docker:
```bash
docker-compose -f docker-compose.prod.yml build
```
⏱️ Tomará 2-5 minutos la primera vez

### 2. Iniciar todos los servicios:
```bash
docker-compose -f docker-compose.prod.yml up -d
```
✅ Esto inicia: app, nginx, postgres, redis, queue worker, scheduler

### 3. Esperar 10 segundos (para que Postgres esté listo):
```bash
sleep 10
```

### 4. Ejecutar migraciones:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### 5. Ejecutar seeders:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

Esto crea:
- ✅ Roles y permisos (super_admin, usuario)
- ✅ Tipos de prospecto (PF, PJ, Empresas)
- ✅ Configuración inicial del sistema

### 6. Verificar que todo está corriendo:
```bash
docker-compose -f docker-compose.prod.yml ps
```

Deberías ver:
```
NAME                     STATUS
laravel-app              Up (healthy)
nginx-prod               Up
postgres-prod            Up (healthy)
redis-prod               Up (healthy)
laravel-queue-worker     Up
laravel-scheduler        Up
```

### 6. Verificar conexión a Redis:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo Redis::ping() ? 'Redis: CONECTADO' : 'Redis: NO CONECTADO';"
```

Debería decir: `Redis: CONECTADO`

### 7. Ver logs del queue worker:
```bash
docker-compose -f docker-compose.prod.yml logs -f queue
```

Presiona `Ctrl+C` para salir de los logs

---

## 🌐 ACCEDER A LA APLICACIÓN

Abre en tu navegador:
- **API**: http://localhost
- **Frontend**: http://localhost:5173 (si tienes el frontend corriendo)

---

## 📊 VERIFICAR QUE EL SISTEMA FUNCIONA

### Ver estado de ejecuciones:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes
```

### Ver todos los contenedores:
```bash
docker-compose -f docker-compose.prod.yml ps
```

### Ver logs en tiempo real:
```bash
# Ver TODO
docker-compose -f docker-compose.prod.yml logs -f

# Solo queue worker
docker-compose -f docker-compose.prod.yml logs -f queue

# Solo app
docker-compose -f docker-compose.prod.yml logs -f app
```

---

## 🛑 DETENER EL SISTEMA

Cuando termines de trabajar:

```bash
docker-compose -f docker-compose.prod.yml down
```

**Esto NO borra la base de datos**, solo detiene los contenedores.

---

## 🔄 REINICIAR EL SISTEMA (días siguientes)

```bash
# Iniciar
docker-compose -f docker-compose.prod.yml up -d

# Detener
docker-compose -f docker-compose.prod.yml down
```

---

## ⚡ COMANDOS RÁPIDOS CON MAKE

Si tienes `make` instalado (viene en macOS):

```bash
# Setup completo (primera vez)
make setup

# Iniciar
make up

# Ver logs del queue
make logs-queue

# Ver estado
make queue-monitor

# Reiniciar queue (después de cambiar código)
make restart-queue

# Detener
make down

# Ver todos los comandos
make help
```

---

## 🧪 PROBAR EL FLOWBUILDER

### 1. Ver que el queue worker esté procesando:
```bash
make logs-queue
# O:
docker-compose -f docker-compose.prod.yml logs -f queue
```

### 2. Crear un flujo desde el frontend y ejecutarlo

### 3. Monitorear en tiempo real:
```bash
# Terminal 1: Logs del queue
make logs-queue

# Terminal 2: Estado cada 30 segundos
watch -n 30 'docker-compose -f docker-compose.prod.yml exec app php artisan flujos:verificar-pendientes'
```

---

## ❓ PROBLEMAS COMUNES

### "Cannot connect to Docker daemon"
Docker Desktop no está corriendo. Abre Docker Desktop.

### "Port already in use"
Otro servicio usa el puerto 80 o 5434. Detén ese servicio primero.

### "Container is unhealthy"
Espera 30 segundos más, los healthchecks toman tiempo.

### Jobs no se procesan
```bash
# Reiniciar el queue worker
docker-compose -f docker-compose.prod.yml restart queue
```

---

## 🎊 ¡LISTO!

Tu sistema Docker está corriendo con:
- ✅ Laravel + PHP-FPM
- ✅ Nginx
- ✅ PostgreSQL
- ✅ Redis
- ✅ Queue Worker (procesando jobs automáticamente)
- ✅ Scheduler

**Todo está listo para usar el FlowBuilder!** 🚀
