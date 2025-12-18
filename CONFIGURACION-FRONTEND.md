# 🔐 Configuración del Frontend para Autenticación con Docker

## ✅ Cambios Realizados en el Backend

El backend ahora está completamente configurado para autenticación basada en sesiones con Sanctum y funciona en Docker:

### 1. Middleware de Sesiones Habilitado
- ✅ `StartSession` middleware agregado a rutas API
- ✅ `EncryptCookies` para cookies seguras
- ✅ `AddQueuedCookiesToResponse` para manejo de cookies

### 2. Variables de Entorno (`.env.docker`)
```env
APP_URL=http://localhost:8080
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:3000,127.0.0.1:5173
FRONTEND_URL=http://localhost:5173
SESSION_DOMAIN=localhost
SESSION_DRIVER=database
SESSION_SECURE_COOKIES=false
```

### 3. Usuario de Prueba Creado
```
Email: admin@test.com
Password: password123
Role: super_admin
```

---

## 🎯 Configuración Requerida en el Frontend

### 1. **URL del Backend**
El backend ahora corre en **puerto 8080** (no 8000):
```javascript
const API_URL = 'http://localhost:8080'
```

### 2. **Configuración de Axios**
El frontend debe configurar Axios con `withCredentials: true` para enviar cookies:

```javascript
// axios.config.js o similar
import axios from 'axios'

const api = axios.create({
  baseURL: 'http://localhost:8080',
  withCredentials: true, // ¡CRÍTICO! Permite enviar/recibir cookies
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

export default api
```

### 3. **Flujo de Autenticación**

#### Paso 1: Obtener Token CSRF (antes del login)
```javascript
// Hacer esto ANTES de cualquier petición POST/PUT/DELETE
await api.get('/sanctum/csrf-cookie')
```

#### Paso 2: Login
```javascript
const response = await api.post('/api/login', {
  email: 'admin@test.com',
  password: 'password123'
})

// Respuesta esperada:
// {
//   "message": "Login exitoso",
//   "user": {
//     "id": 1,
//     "name": "Admin Test",
//     "email": "admin@test.com",
//     "role": "super_admin",
//     "permissions": [...]
//   }
// }
```

#### Paso 3: Obtener Usuario Actual
```javascript
const response = await api.get('/api/me')
```

#### Paso 4: Logout
```javascript
await api.post('/api/logout')
```

---

## 📝 Ejemplo Completo de Login en Vue/React

### Vue 3 + Pinia
```javascript
// stores/auth.js
import { defineStore } from 'pinia'
import axios from 'axios'

const api = axios.create({
  baseURL: 'http://localhost:8080',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    isAuthenticated: false
  }),

  actions: {
    async login(email, password) {
      try {
        // 1. Obtener CSRF token
        await api.get('/sanctum/csrf-cookie')

        // 2. Login
        const response = await api.post('/api/login', { email, password })

        this.user = response.data.user
        this.isAuthenticated = true

        return response.data
      } catch (error) {
        console.error('Login failed:', error)
        throw error
      }
    },

    async logout() {
      try {
        await api.post('/api/logout')
        this.user = null
        this.isAuthenticated = false
      } catch (error) {
        console.error('Logout failed:', error)
        throw error
      }
    },

    async fetchUser() {
      try {
        const response = await api.get('/api/me')
        this.user = response.data.user
        this.isAuthenticated = true
        return response.data.user
      } catch (error) {
        this.user = null
        this.isAuthenticated = false
        throw error
      }
    }
  }
})
```

### React + Context API
```javascript
// contexts/AuthContext.jsx
import { createContext, useContext, useState } from 'react'
import axios from 'axios'

const api = axios.create({
  baseURL: 'http://localhost:8080',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest'
  }
})

const AuthContext = createContext()

export const useAuth = () => useContext(AuthContext)

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(null)
  const [isAuthenticated, setIsAuthenticated] = useState(false)

  const login = async (email, password) => {
    try {
      // 1. Obtener CSRF token
      await api.get('/sanctum/csrf-cookie')

      // 2. Login
      const response = await api.post('/api/login', { email, password })

      setUser(response.data.user)
      setIsAuthenticated(true)

      return response.data
    } catch (error) {
      console.error('Login failed:', error)
      throw error
    }
  }

  const logout = async () => {
    try {
      await api.post('/api/logout')
      setUser(null)
      setIsAuthenticated(false)
    } catch (error) {
      console.error('Logout failed:', error)
      throw error
    }
  }

  const fetchUser = async () => {
    try {
      const response = await api.get('/api/me')
      setUser(response.data.user)
      setIsAuthenticated(true)
      return response.data.user
    } catch (error) {
      setUser(null)
      setIsAuthenticated(false)
      throw error
    }
  }

  return (
    <AuthContext.Provider value={{ user, isAuthenticated, login, logout, fetchUser }}>
      {children}
    </AuthContext.Provider>
  )
}
```

---

## 🔍 Verificación de Errores Comunes

### Error: "CSRF token mismatch"
**Causa:** No se llamó a `/sanctum/csrf-cookie` antes del login
**Solución:** Siempre llamar a `/sanctum/csrf-cookie` primero

### Error: "CORS policy blocked"
**Causa:** `withCredentials: true` no está configurado
**Solución:** Agregar `withCredentials: true` a la configuración de Axios

### Error: "Unauthenticated" en /api/me
**Causa:** Las cookies no se están enviando
**Solución:** Verificar que `withCredentials: true` esté en TODAS las peticiones

### Error: "Session store not set on request"
**Causa:** Middleware de sesión no configurado (ya solucionado en backend)
**Solución:** Ya está arreglado en `bootstrap/app.php`

---

## 🧪 Probar con cURL

### 1. Obtener CSRF Token
```bash
curl -X GET http://localhost:8080/sanctum/csrf-cookie \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173/" \
  -c cookies.txt -v
```

### 2. Login (usando cookies del paso anterior)
```bash
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "Referer: http://localhost:5173/" \
  -H "X-Requested-With: XMLHttpRequest" \
  -d '{"email":"admin@test.com","password":"password123"}' \
  -b cookies.txt -c cookies.txt
```

### 3. Obtener Usuario Actual
```bash
curl -X GET http://localhost:8080/api/me \
  -H "Accept: application/json" \
  -H "Origin: http://localhost:5173" \
  -H "X-Requested-With: XMLHttpRequest" \
  -b cookies.txt
```

---

## 📋 Checklist de Configuración

### Backend (✅ Ya Hecho)
- [x] Middleware de sesiones en rutas API
- [x] CORS configurado para `localhost:5173`
- [x] `SANCTUM_STATEFUL_DOMAINS` incluye frontend
- [x] `SESSION_DOMAIN` configurado
- [x] `withCredentials` soportado
- [x] Usuario de prueba creado
- [x] Docker corriendo en puerto 8080

### Frontend (⚠️ Pendiente)
- [ ] Cambiar URL base a `http://localhost:8080`
- [ ] Configurar `withCredentials: true` en Axios
- [ ] Implementar flujo: CSRF → Login → Fetch User
- [ ] Agregar headers `X-Requested-With: XMLHttpRequest`
- [ ] Probar login con `admin@test.com` / `password123`

---

## 🚀 Comandos Útiles

### Ver logs del backend
```bash
docker compose -f docker-compose.prod.yml logs -f app
```

### Reiniciar backend después de cambios
```bash
docker compose -f docker-compose.prod.yml restart app nginx
```

### Verificar que el backend esté corriendo
```bash
curl http://localhost:8080/up
```

### Crear más usuarios de prueba
```bash
docker compose -f docker-compose.prod.yml exec app php artisan tinker --execute="
\$user = \App\Models\User::create([
    'name' => 'Usuario Test',
    'email' => 'usuario@test.com',
    'password' => bcrypt('password123')
]);
\$user->assignRole('usuario');
echo 'Usuario creado: ' . \$user->email;
"
```

---

## 📞 Soporte

Si el login sigue fallando:

1. **Verificar headers en la petición del frontend** (usar DevTools Network tab)
2. **Verificar que las cookies se estén guardando** (DevTools Application → Cookies)
3. **Ver logs del backend** para mensajes de error específicos
4. **Verificar que el frontend esté en `localhost:5173`** (no `127.0.0.1:5173`)

---

¡El backend está listo! Ahora solo falta configurar el frontend con los cambios arriba. 🎉
