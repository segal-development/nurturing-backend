# 🚀 Comandos Paso a Paso - Docker con Seeders

## 📋 ¿Qué hacen los Seeders?

Los seeders crean datos iniciales OBLIGATORIOS para que el sistema funcione:

1. **RolePermissionSeeder**: Crea roles (`super_admin`, `usuario`) y permisos
2. **TipoProspectoSeeder**: Crea tipos de prospecto (PF, PJ, Empresas)
3. **ConfiguracionSeeder**: Crea configuración inicial del sistema (costos, etc.)

**⚠️ IMPORTANTE:** Sin los seeders, el sistema NO funcionará correctamente.

---

## ✨ Método 1: UN SOLO COMANDO (Recomendado)

Este comando hace TODO automáticamente (build, migrate, seed):

```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
./docker-quick-start.sh
```

**Incluye:**
- ✅ Construir imágenes
- ✅ Iniciar servicios
- ✅ Ejecutar migraciones
- ✅ **Ejecutar seeders**
- ✅ Verificar conexiones

---

## 🔧 Método 2: Paso a Paso Manual (Para aprender)

### 1️⃣ Ir a la carpeta del proyecto
```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
```

### 2️⃣ Construir las imágenes Docker
```bash
docker-compose -f docker-compose.prod.yml build
```
⏱️ Primera vez: 2-5 minutos

### 3️⃣ Iniciar todos los servicios
```bash
docker-compose -f docker-compose.prod.yml up -d
```
✅ Inicia: app, nginx, postgres, redis, queue, scheduler

### 4️⃣ Esperar que PostgreSQL esté listo
```bash
sleep 10
```

### 5️⃣ Ejecutar migraciones
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

### 6️⃣ Ejecutar seeders (¡IMPORTANTE!)
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

**Esto crea:**
- ✅ Roles: `super_admin`, `usuario`
- ✅ Permisos: ver, crear, editar, eliminar (para prospectos, flujos, etc.)
- ✅ Tipos de prospecto: Personas Físicas (PF), Personas Jurídicas (PJ), Empresas
- ✅ Configuración del sistema: costos de email/SMS, etc.

### 7️⃣ Verificar que todo está corriendo
```bash
docker-compose -f docker-compose.prod.yml ps
```

**Deberías ver:**
```
NAME                     STATUS
laravel-app              Up
nginx-prod               Up
postgres-prod            Up (healthy)
redis-prod               Up (healthy)
laravel-queue-worker     Up
laravel-scheduler        Up
```

### 8️⃣ Verificar conexión a Redis
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo Redis::ping() ? 'Redis: CONECTADO' : 'Redis: NO CONECTADO';"
```

**Debería decir:** `Redis: CONECTADO`

### 9️⃣ Ver logs del queue worker
```bash
docker-compose -f docker-compose.prod.yml logs -f queue
```

Presiona `Ctrl+C` para salir

---

## 🎯 Método 3: Con Makefile (Rápido)

Si tienes `make` instalado:

```bash
# Setup completo (build + up + migrate + seed)
make setup

# Ver logs del queue
make logs-queue

# Ver estado
make ps
```

---

## 🔄 Ejecutar Seeders Individualmente

Si solo quieres ejecutar seeders específicos:

```bash
# Todos los seeders
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --force

# Solo roles y permisos
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --class=RolePermissionSeeder --force

# Solo tipos de prospecto
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --class=TipoProspectoSeeder --force

# Solo configuración
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --class=ConfiguracionSeeder --force
```

---

## 🔄 Reiniciar Base de Datos (Fresh Start)

Si quieres empezar de cero:

```bash
# Borrar todo y recrear (¡CUIDADO! Borra todos los datos)
docker-compose -f docker-compose.prod.yml exec app php artisan migrate:fresh --seed --force
```

**Esto:**
- 🗑️ Borra todas las tablas
- 📋 Ejecuta migraciones desde cero
- 🌱 Ejecuta todos los seeders

---

## 📊 Verificar que los Seeders Funcionaron

### Verificar roles:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo 'Roles: ' . \Spatie\Permission\Models\Role::count();"
```
**Debería mostrar:** `Roles: 2` (super_admin, usuario)

### Verificar tipos de prospecto:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo 'Tipos: ' . \App\Models\TipoProspecto::count();"
```
**Debería mostrar:** `Tipos: 3` o más

### Verificar configuración:
```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker --execute="echo 'Config: ' . \App\Models\Configuracion::count();"
```
**Debería mostrar:** `Config: 1` o más

---

## 🧪 Crear Usuario de Prueba

Después de los seeders, puedes crear un usuario para probar:

```bash
docker-compose -f docker-compose.prod.yml exec app php artisan tinker
```

Luego ejecuta:
```php
$user = \App\Models\User::create([
    'name' => 'Test User',
    'email' => 'test@example.com',
    'password' => bcrypt('password123')
]);

$user->assignRole('super_admin');

echo "Usuario creado: " . $user->email;
exit
```

---

## 📝 Resumen de Comandos

### Primera vez (Setup completo):
```bash
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend

# Opción A: Todo en 1 comando
./docker-quick-start.sh

# Opción B: Con Make
make setup

# Opción C: Manual paso a paso
docker-compose -f docker-compose.prod.yml build
docker-compose -f docker-compose.prod.yml up -d
sleep 10
docker-compose -f docker-compose.prod.yml exec app php artisan migrate --force
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

### Ver logs:
```bash
docker-compose -f docker-compose.prod.yml logs -f queue
```

### Detener:
```bash
docker-compose -f docker-compose.prod.yml down
```

### Días siguientes:
```bash
# Iniciar
docker-compose -f docker-compose.prod.yml up -d

# Detener
docker-compose -f docker-compose.prod.yml down
```

---

## ⚠️ Errores Comunes

### "Seeders ya ejecutados"
No es un error, puedes ejecutarlos varias veces sin problema.

### "SQLSTATE[23000]: Integrity constraint violation"
Significa que ya existen los datos. Puedes:
1. Ignorarlo (los datos ya están)
2. O hacer fresh start: `migrate:fresh --seed`

### "Class 'Database\Seeders\...' not found"
```bash
# Limpiar cache y reintentar
docker-compose -f docker-compose.prod.yml exec app php artisan config:clear
docker-compose -f docker-compose.prod.yml exec app php artisan db:seed --force
```

---

## ✅ Checklist Final

Después de ejecutar todo, verifica:

- [ ] Contenedores corriendo: `docker-compose ps`
- [ ] Redis conectado: `make redis-ping` o tinker
- [ ] Roles creados: `Role::count()` = 2
- [ ] Tipos prospecto: `TipoProspecto::count()` ≥ 3
- [ ] Configuración: `Configuracion::count()` ≥ 1
- [ ] Queue worker: `make logs-queue`
- [ ] API accesible: http://localhost

**¡Todo listo para usar el FlowBuilder!** 🚀