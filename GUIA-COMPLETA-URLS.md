# 🌐 Guía Completa - URLs y Modos de Trabajo

## 📊 Comparación de Modos

| Aspecto | Modo LOCAL | Modo DOCKER |
|---------|------------|-------------|
| **Backend URL** | `http://localhost:8000` | `http://localhost` (puerto 80) |
| **Frontend URL** | `http://localhost:5173` | `http://localhost:5173` |
| **Queue** | Database (más lento) | Redis (rápido) |
| **Para qué sirve** | Desarrollo diario | Probar FlowBuilder con delays |
| **Inicio** | `composer dev` | `docker-compose up -d` |

---

## 🎯 Modo 1: LOCAL (Desarrollo Diario)

### ¿Cuándo usar?
- ✅ Desarrollo normal
- ✅ Cambios rápidos en código
- ✅ No necesitas probar delays de flujos

### Iniciar:
```bash
# Terminal 1: Backend
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
composer dev

# Terminal 2: Frontend (en tu carpeta de frontend)
npm run dev
```

### URLs:
- **Backend API:** `http://localhost:8000`
- **Frontend:** `http://localhost:5173`

### Configuración Frontend (.env):
```bash
VITE_API_URL=http://localhost:8000
```

### ¿Todo funciona igual?
✅ **SÍ**, exactamente como trabajas ahora

---

## 🐳 Modo 2: DOCKER (Pruebas con Redis)

### ¿Cuándo usar?
- ✅ Probar FlowBuilder con delays reales
- ✅ Probar sistema de ejecución automática
- ✅ Simular ambiente de producción

### Iniciar:
```bash
# Terminal 1: Backend (Docker)
cd /Users/marceloyvale/Desktop/Grupo-Segal/nurturing-backend
docker-compose -f docker-compose.prod.yml up -d

# Terminal 2: Ver logs del queue
docker-compose -f docker-compose.prod.yml logs -f queue

# Terminal 3: Frontend (local, en tu carpeta de frontend)
npm run dev
```

### URLs:
- **Backend API:** `http://localhost` (SIN :8000)
- **Frontend:** `http://localhost:5173`

### Configuración Frontend (.env):
```bash
VITE_API_URL=http://localhost
```

### ⚠️ IMPORTANTE:
Necesitas cambiar la URL en el frontend de `localhost:8000` a `localhost`

---

## 🔄 Cambiar entre Modos (FÁCIL)

He creado un script para cambiar fácilmente:

### Cambiar a modo LOCAL:
```bash
./switch-mode.sh local
```

### Cambiar a modo DOCKER:
```bash
./switch-mode.sh docker
```

El script:
- ✅ Actualiza tu `.env` automáticamente
- ✅ Hace backup del `.env` anterior
- ✅ Te dice exactamente qué cambiar en el frontend

---

## 📝 Workflow Recomendado

### Lunes - Viernes (Desarrollo Normal):

```bash
# MODO LOCAL
./switch-mode.sh local

# Iniciar backend
composer dev

# Iniciar frontend (en otra terminal)
npm run dev

# Frontend .env debe tener:
VITE_API_URL=http://localhost:8000
```

### Cuando necesites probar FlowBuilder:

```bash
# 1. Cambiar a modo Docker
./switch-mode.sh docker

# 2. Actualizar frontend .env
# Cambiar: VITE_API_URL=http://localhost

# 3. Iniciar Docker
docker-compose -f docker-compose.prod.yml up -d

# 4. Ver logs del queue (en otra terminal)
docker-compose -f docker-compose.prod.yml logs -f queue

# 5. Iniciar frontend
npm run dev

# 6. Probar FlowBuilder desde http://localhost:5173
```

### Al terminar pruebas con Docker:

```bash
# 1. Detener Docker
docker-compose -f docker-compose.prod.yml down

# 2. Volver a modo local
./switch-mode.sh local

# 3. Volver frontend .env a:
# VITE_API_URL=http://localhost:8000

# 4. Continuar desarrollo normal
composer dev
```

---

## 🧪 Ejemplo Práctico: Probar FlowBuilder

### Preparación (1 vez):

```bash
# 1. Cambiar backend a Docker
./switch-mode.sh docker

# 2. Actualizar frontend/.env
# ANTES: VITE_API_URL=http://localhost:8000
# DESPUÉS: VITE_API_URL=http://localhost

# 3. Iniciar Docker
docker-compose -f docker-compose.prod.yml up -d

# 4. Esperar 10 segundos
sleep 10

# 5. Verificar que todo está corriendo
docker-compose -f docker-compose.prod.yml ps
```

### Uso diario (mientras pruebes):

```bash
# Terminal 1: Logs del queue
docker-compose -f docker-compose.prod.yml logs -f queue

# Terminal 2: Frontend
cd /ruta/a/frontend
npm run dev

# Navegar a: http://localhost:5173
# Crear flujo y ejecutar
# Ver en Terminal 1 cómo se procesan los jobs
```

### Terminar pruebas:

```bash
# Detener Docker
docker-compose -f docker-compose.prod.yml down

# Volver a modo local
./switch-mode.sh local

# Volver frontend .env
# VITE_API_URL=http://localhost:8000
```

---

## ❓ Preguntas Frecuentes

### ¿Puedo tener ambos corriendo al mismo tiempo?
❌ **NO**. El puerto 5434 (PostgreSQL) entraría en conflicto.

### ¿Se pierde la base de datos al cambiar de modo?
✅ **NO**. Cada modo usa su propia base de datos:
- Local: PostgreSQL en `127.0.0.1:5434`
- Docker: PostgreSQL en contenedor (puerto interno 5432)

### ¿Necesito ejecutar migraciones cada vez?
- **Local:** NO (ya las tienes)
- **Docker:** Solo la primera vez: `docker-compose exec app php artisan migrate --force`

### ¿El frontend necesita estar en Docker?
❌ **NO**. El frontend puede quedarse corriendo local (`npm run dev`).
Solo cambia la URL del API en el `.env`

### ¿Cuál modo es más rápido para desarrollar?
🏃 **LOCAL** es más rápido para cambios de código
🐳 **DOCKER** es más realista para probar el sistema completo

---

## 🎯 Resumen para Ti

### Para trabajar NORMAL (desarrollo):
```bash
# NADA CAMBIA - sigue como ahora
composer dev        # Backend: localhost:8000
npm run dev        # Frontend: localhost:5173
```

### Para probar FLOWBUILDER con delays:
```bash
# 1. Cambiar a Docker
./switch-mode.sh docker

# 2. Iniciar Docker
docker-compose -f docker-compose.prod.yml up -d

# 3. Cambiar frontend .env a: http://localhost

# 4. Iniciar frontend
npm run dev

# 5. Probar en: http://localhost:5173

# 6. Al terminar
docker-compose -f docker-compose.prod.yml down
./switch-mode.sh local
```

---

## ✅ TODO FUNCIONARÁ SI:

1. **Backend en LOCAL:**
   - Backend: `composer dev` → `localhost:8000`
   - Frontend `.env`: `VITE_API_URL=http://localhost:8000`

2. **Backend en DOCKER:**
   - Backend: `docker-compose up -d` → `localhost`
   - Frontend `.env`: `VITE_API_URL=http://localhost`

**El frontend SIEMPRE corre con `npm run dev` (local)**

---

¿Queda claro? La clave es cambiar la URL del frontend según dónde corra el backend 🎯
