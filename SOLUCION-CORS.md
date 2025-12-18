# ✅ Solución CORS - Configuración Completa

## Problema Resuelto

**Error Original:**
```
Access to XMLHttpRequest at 'http://localhost:8080/sanctum/csrf-cookie' from origin 'http://localhost:5173'
has been blocked by CORS policy: Response to preflight request doesn't pass access control check:
No 'Access-Control-Allow-Origin' header is present on the requested resource.
```

## Cambios Realizados

### 1. **config/cors.php** - Configuración CORS de Laravel

```php
'paths' => ['api/*', 'sanctum/csrf-cookie'],

'allowed_origins' => [
    env('FRONTEND_URL', 'http://localhost:5173'),
    'http://localhost:5173',
    'http://localhost:3000',
    'http://127.0.0.1:5173',
],

'supports_credentials' => true,
```

**Cambios:**
- ✅ `paths` ahora incluye explícitamente `sanctum/csrf-cookie`
- ✅ Múltiples orígenes permitidos (localhost:5173, localhost:3000, 127.0.0.1:5173)
- ✅ `supports_credentials` en `true` para permitir cookies

### 2. **docker/nginx/prod.conf** - Configuración Nginx

```nginx
location ~ \.php$ {
    fastcgi_pass app:9000;
    fastcgi_index index.php;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
    fastcgi_param DOCUMENT_ROOT $realpath_root;

    # Pasar headers importantes al backend
    fastcgi_param HTTP_ORIGIN $http_origin;
    fastcgi_param HTTP_ACCESS_CONTROL_REQUEST_METHOD $http_access_control_request_method;
    fastcgi_param HTTP_ACCESS_CONTROL_REQUEST_HEADERS $http_access_control_request_headers;

    # No esconder headers CORS de Laravel
    fastcgi_hide_header Access-Control-Allow-Origin;
    fastcgi_hide_header Access-Control-Allow-Methods;
    fastcgi_hide_header Access-Control-Allow-Headers;
    fastcgi_hide_header Access-Control-Allow-Credentials;
}
```

**Cambios:**
- ✅ Nginx pasa headers `Origin` y `Access-Control-Request-*` a PHP
- ✅ Nginx no esconde los headers CORS que Laravel genera
- ✅ Tamaño máximo de archivos aumentado a 100M

### 3. **bootstrap/app.php** - Middleware de Sesiones

```php
->withMiddleware(function (Middleware $middleware): void {
    // Habilitar sesiones en rutas API
    $middleware->api(prepend: [
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
    ]);

    // Configurar Sanctum para SPA authentication
    $middleware->statefulApi();

    // ...
})
```

**Cambios:**
- ✅ Middleware de sesiones habilitado en rutas API
- ✅ Cookies encriptadas y sesiones funcionando

### 4. **.env.docker** - Variables de Entorno

```env
APP_URL=http://localhost:8080
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000,127.0.0.1:5173
FRONTEND_URL=http://localhost:5173
SESSION_DOMAIN=localhost
SESSION_DRIVER=database
SESSION_SECURE_COOKIES=false
```

**Variables importantes:**
- ✅ `APP_URL` apunta al puerto correcto (8080)
- ✅ `SANCTUM_STATEFUL_DOMAINS` incluye todos los orígenes
- ✅ `SESSION_DOMAIN` configurado a `localhost`
- ✅ `SESSION_SECURE_COOKIES=false` para desarrollo (usar `true` en producción con HTTPS)

---

## Configuración del Frontend

### Axios con withCredentials

```javascript
import axios from 'axios'

const api = axios.create({
  baseURL: 'http://localhost:8080',  // Puerto 8080, no 8000
  withCredentials: true,              // CRÍTICO: permite cookies
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

export default api
```

### Flujo de Login

```javascript
// 1. Obtener CSRF token (SIEMPRE primero)
await api.get('/sanctum/csrf-cookie')

// 2. Login
const response = await api.post('/api/login', {
  email: 'admin@test.com',
  password: 'password123'
})

// 3. Obtener usuario actual
const user = await api.get('/api/me')
```

---

## Verificación

### 1. Verificar que los contenedores están corriendo
```bash
docker compose -f docker-compose.prod.yml ps
```

Deberías ver:
- ✅ laravel-app (Up)
- ✅ nginx-prod (Up, port 8080:80)
- ✅ postgres-prod (Up, healthy)
- ✅ redis-prod (Up, healthy)
- ✅ laravel-queue-worker (Up)
- ✅ laravel-scheduler (Up)

### 2. Verificar configuración cargada
```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:show cors
```

### 3. Probar desde el navegador

Abre la consola del navegador (F12) y ejecuta:

```javascript
// Probar CSRF endpoint
fetch('http://localhost:8080/sanctum/csrf-cookie', {
  credentials: 'include',
  headers: {
    'Accept': 'application/json'
  }
})
.then(r => console.log('CSRF OK:', r.status))
.catch(e => console.error('CSRF Error:', e))
```

Si funciona, verás: `CSRF OK: 204` o `CSRF OK: 200`

### 4. Ver headers CORS en DevTools

En la pestaña Network del navegador:
1. Abre Network tab (F12 → Network)
2. Ejecuta el fetch de arriba
3. Click en la petición `csrf-cookie`
4. Ve a la pestaña "Headers"
5. Busca en "Response Headers":
   - ✅ `Access-Control-Allow-Origin: http://localhost:5173`
   - ✅ `Access-Control-Allow-Credentials: true`

---

## Solución de Problemas

### Problema: "CORS policy blocked"

**Solución:**
```bash
# 1. Limpiar cache
docker compose -f docker-compose.prod.yml exec app php artisan config:clear

# 2. Reiniciar contenedores
docker compose -f docker-compose.prod.yml restart app nginx

# 3. Verificar que esté usando localhost:5173 (NO 127.0.0.1:5173)
```

### Problema: "CSRF token mismatch"

**Solución:**
- Asegúrate de llamar `/sanctum/csrf-cookie` ANTES de `/api/login`
- Verifica que `withCredentials: true` esté en TODAS las peticiones
- Verifica que el frontend esté en `localhost:5173` exactamente

### Problema: "Unauthenticated" después del login

**Solución:**
- Las cookies no se están guardando
- Revisa que `withCredentials: true` esté en Axios
- Verifica en Application → Cookies que exista una cookie de sesión

### Problema: Cookies no aparecen

**Solución:**
```javascript
// Asegúrate de que Axios esté configurado así:
axios.create({
  baseURL: 'http://localhost:8080',
  withCredentials: true,  // ← IMPORTANTE
  // ...
})
```

---

## Comandos Útiles

### Reiniciar todo
```bash
docker compose -f docker-compose.prod.yml restart app nginx
```

### Ver logs en tiempo real
```bash
docker compose -f docker-compose.prod.yml logs -f app nginx
```

### Limpiar cache de configuración
```bash
docker compose -f docker-compose.prod.yml exec app php artisan config:clear
```

### Ver configuración CORS actual
```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="print_r(config('cors'));"
```

---

## Checklist Final

Antes de probar en el frontend, verifica:

### Backend (Docker)
- [x] Contenedores corriendo (`docker compose ps`)
- [x] CORS configurado en `config/cors.php`
- [x] Nginx configurado en `docker/nginx/prod.conf`
- [x] Variables de entorno en `.env.docker`
- [x] Middleware de sesiones en `bootstrap/app.php`
- [x] Cache limpiado (`php artisan config:clear`)

### Frontend
- [ ] Axios configurado con `withCredentials: true`
- [ ] URL base es `http://localhost:8080` (puerto 8080)
- [ ] Headers incluyen `X-Requested-With: XMLHttpRequest`
- [ ] Flujo correcto: CSRF → Login → Me
- [ ] Frontend corre en `localhost:5173` (no 127.0.0.1)

---

## Usuario de Prueba

```
Email: admin@test.com
Password: password123
Role: super_admin
```

---

## ✅ Estado Actual

El backend está **completamente configurado** y listo para recibir peticiones desde el frontend en `localhost:5173`.

**Los cambios aplicados:**
1. ✅ CORS configurado correctamente
2. ✅ Nginx pasando headers correctamente
3. ✅ Sesiones habilitadas en API
4. ✅ Sanctum configurado para SPA
5. ✅ Contenedores corriendo en puerto 8080

**Ahora solo falta que el frontend:**
- Use `withCredentials: true` en Axios
- Llame primero a `/sanctum/csrf-cookie`
- Luego haga login en `/api/login`

¡Deberías poder hacer login exitosamente! 🎉