# API Documentation - Sistema de Nurturing/CRM

**Backend Laravel 12** | **Zona Horaria: America/Santiago (Chile)**

---

## 📋 Tabla de Contenidos

1. [Autenticación](#autenticación)
2. [Tipos TypeScript](#tipos-typescript)
3. [Endpoints](#endpoints)
4. [Configuración Frontend](#configuración-frontend)
5. [Manejo de Errores](#manejo-de-errores)

---

## 🔐 Autenticación

### Sistema de Tokens Dual

El backend utiliza **cookies httpOnly** para almacenar tokens de forma segura:

- **Access Token**: Expira en 60 minutos, se envía automáticamente con cada request
- **Refresh Token**: Expira en 7 días, permite renovar el access token

### ⚠️ IMPORTANTE para React

```typescript
// Configuración de Axios
import axios from 'axios';

const api = axios.create({
  baseURL: 'http://localhost:8000/api',
  withCredentials: true, // ⭐ CRÍTICO: Permite enviar/recibir cookies
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

export default api;
```

### Flujo de Autenticación

1. **Login**: El backend establece las cookies automáticamente
2. **Requests**: Las cookies se envían automáticamente con cada petición
3. **Logout**: El backend elimina los tokens y expira las cookies

**NO necesitas:**
- Guardar tokens en localStorage/sessionStorage
- Agregar headers `Authorization` manualmente
- Manejar refresh token manualmente

Las cookies httpOnly son **más seguras** contra XSS.

---

## 📦 Tipos TypeScript

### Autenticación

```typescript
// Usuario
export interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  permissions?: string[];
}

// Login Response
export interface LoginResponse {
  message: string;
  access_token: string; // Opcional: solo para testing con Postman
  token_type: 'Bearer';
  user: User;
}

// Register Request
export interface RegisterRequest {
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}

// Login Request
export interface LoginRequest {
  email: string;
  password: string;
}
```

### Prospectos

```typescript
// Tipo de Prospecto
export interface TipoProspecto {
  id: number;
  nombre: 'Deuda Baja' | 'Deuda Media' | 'Deuda Alta';
  descripcion: string;
  monto_min: number;
  monto_max: number | null;
  orden: number;
  activo: boolean;
}

// Prospecto
export interface Prospecto {
  id: number;
  nombre: string;
  email: string;
  telefono: string | null;
  tipo_prospecto_id: number;
  tipo_prospecto?: {
    id: number;
    nombre: string;
  };
  estado: 'activo' | 'inactivo' | 'convertido';
  monto_deuda: number; // Entero en pesos chilenos
  fecha_ultimo_contacto: string | null; // Formato: "20/11/2025 09:55:37"
  origen: string | null;
  importacion_id: number | null;
  importacion?: {
    id: number;
    origen: string;
    fecha_importacion: string;
  };
  metadata: Record<string, any> | null;
  created_at: string; // Formato: "20/11/2025 09:55:37"
  updated_at: string; // Formato: "20/11/2025 09:55:37"
}

// Lista paginada
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    total: number;
    per_page: number;
    last_page: number;
  };
}

// Crear/Actualizar Prospecto
export interface ProspectoFormData {
  nombre: string;
  email: string;
  telefono?: string;
  tipo_prospecto_id?: number; // Opcional si envías monto_deuda
  estado?: 'activo' | 'inactivo' | 'convertido';
  monto_deuda?: number;
  fecha_ultimo_contacto?: string;
  metadata?: Record<string, any>;
}

// Estadísticas
export interface ProspectoEstadisticas {
  total_prospectos: number;
  por_estado: {
    activo?: number;
    inactivo?: number;
    convertido?: number;
  };
  por_tipo: Array<{
    nombre: string;
    total: number;
  }>;
  monto_total_deuda: number;
}
```

### Importaciones

```typescript
// Importación
export interface Importacion {
  id: number;
  nombre_archivo: string;
  origen: string;
  total_registros: number;
  registros_exitosos: number;
  registros_fallidos: number;
  estado: 'procesando' | 'completado' | 'fallido';
  fecha_importacion: string; // Formato: "20/11/2025 09:55:37"
  user_id: number;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  metadata: {
    errores?: Array<{
      fila: number;
      errores: Record<string, string[]>;
    }>;
  } | null;
  created_at: string;
  updated_at: string;
}

// Respuesta de importación
export interface ImportarResponse {
  mensaje: string;
  data: Importacion;
  resumen: {
    total_registros: number;
    registros_exitosos: number;
    registros_fallidos: number;
    errores: Array<{
      fila: number;
      errores: Record<string, string[]>;
    }>;
  };
}
```

---

## 🌐 Endpoints

### Base URL

```
http://localhost:8000/api
```

---

### 👤 Autenticación

#### POST `/register`

**Request:**
```typescript
{
  name: string;
  email: string;
  password: string;
  password_confirmation: string;
}
```

**Response:** `201 Created`
```typescript
{
  user: User;
}
```

---

#### POST `/login`

**Request:**
```typescript
{
  email: string;
  password: string;
}
```

**Response:** `200 OK`
```typescript
{
  message: string;
  access_token: string; // Para testing
  token_type: "Bearer";
  user: User;
}
```

**Cookies establecidas:**
- `access_token` (httpOnly, secure, 60 min)
- `refresh_token` (httpOnly, secure, 7 días)

---

#### POST `/logout`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  message: string;
}
```

---

#### GET `/me`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  user: User;
}
```

---

### 📊 Prospectos

#### GET `/prospectos`

**Requiere:** Autenticación

**Query Params:**
```typescript
{
  estado?: 'activo' | 'inactivo' | 'convertido';
  tipo_prospecto_id?: number; // 1, 2, 3
  origen?: string;
  monto_deuda_min?: number;
  monto_deuda_max?: number;
  search?: string; // Busca en nombre, email, telefono
  sort_by?: 'created_at' | 'monto_deuda' | 'nombre';
  sort_direction?: 'asc' | 'desc';
  per_page?: number; // Default: 15
}
```

**Response:** `200 OK`
```typescript
PaginatedResponse<Prospecto>
```

**Ejemplo:**
```typescript
// Buscar prospectos activos con deuda alta
GET /api/prospectos?estado=activo&monto_deuda_min=1500001&per_page=20
```

---

#### POST `/prospectos`

**Requiere:** Autenticación + Permiso `crear prospectos`

**Request:**
```typescript
{
  nombre: string;
  email: string; // Único
  telefono?: string;
  tipo_prospecto_id?: number; // Si no lo envías, se determina por monto_deuda
  estado?: 'activo' | 'inactivo' | 'convertido';
  monto_deuda?: number; // Entero (pesos chilenos)
  fecha_ultimo_contacto?: string;
  metadata?: Record<string, any>;
}
```

**Response:** `201 Created`
```typescript
{
  mensaje: string;
  data: Prospecto;
}
```

**Lógica de tipo automático:**
- Si envías `monto_deuda` pero NO `tipo_prospecto_id`, se asigna automáticamente:
  - 0 - 699,000: Deuda Baja (id: 1)
  - 700,000 - 1,500,000: Deuda Media (id: 2)
  - 1,500,001+: Deuda Alta (id: 3)

---

#### GET `/prospectos/{id}`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  data: Prospecto; // Con relaciones cargadas
}
```

---

#### PUT/PATCH `/prospectos/{id}`

**Requiere:** Autenticación + Permiso `editar prospectos`

**Request:** (todos los campos opcionales)
```typescript
{
  nombre?: string;
  email?: string;
  telefono?: string;
  tipo_prospecto_id?: number;
  estado?: 'activo' | 'inactivo' | 'convertido';
  monto_deuda?: number;
  fecha_ultimo_contacto?: string;
  metadata?: Record<string, any>;
}
```

**Response:** `200 OK`
```typescript
{
  mensaje: string;
  data: Prospecto;
}
```

---

#### DELETE `/prospectos/{id}`

**Requiere:** Autenticación + Permiso `eliminar prospectos`

**Response:** `200 OK`
```typescript
{
  mensaje: string;
}
```

**Error:** `422` si el prospecto está en un flujo activo

---

#### GET `/prospectos/estadisticas`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  data: ProspectoEstadisticas;
}
```

---

### 📤 Importaciones

#### GET `/importaciones`

**Requiere:** Autenticación

**Query Params:**
```typescript
{
  origen?: string;
  estado?: 'procesando' | 'completado' | 'fallido';
  fecha_desde?: string; // "2025-11-01"
  fecha_hasta?: string; // "2025-11-30"
}
```

**Response:** `200 OK`
```typescript
PaginatedResponse<Importacion>
```

---

#### POST `/importaciones`

**Requiere:** Autenticación + Permiso `crear prospectos`

**Request:** `multipart/form-data`
```typescript
{
  archivo: File; // .xlsx, .xls, .csv (max 10MB)
  origen: string; // ej: "banco_de_chile", "campaña_verano"
}
```

**Formato Excel esperado:**

| nombre | email | telefono | monto_deuda |
|--------|-------|----------|-------------|
| Juan Pérez | juan@example.com | 123456789 | 500000 |
| María García | maria@example.com | 987654321 | 2659972 |

**Notas:**
- `email` es obligatorio
- `telefono` es opcional
- `monto_deuda` puede tener formato: 2.659.972, 2,659,972 o 2659972
- El tipo se asigna automáticamente según el monto

**Response:** `201 Created`
```typescript
ImportarResponse
```

**Ejemplo de respuesta:**
```typescript
{
  mensaje: "Importación completada exitosamente",
  data: Importacion,
  resumen: {
    total_registros: 595,
    registros_exitosos: 570,
    registros_fallidos: 25,
    errores: [
      {
        fila: 2,
        errores: {
          email: ["The email field is required."]
        }
      }
    ]
  }
}
```

---

#### GET `/importaciones/{id}`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  data: Importacion; // Con primeros 100 prospectos y usuario
}
```

---

#### DELETE `/importaciones/{id}`

**Requiere:** Autenticación

**Response:** `200 OK`
```typescript
{
  mensaje: string;
}
```

**Error:** `422` si la importación tiene prospectos asociados

---

## ⚙️ Configuración Frontend

### 1. Instalar Axios

```bash
npm install axios
```

### 2. Crear API Client

```typescript
// src/api/client.ts
import axios, { AxiosError } from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api',
  withCredentials: true, // ⭐ Esencial para cookies
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
});

// Interceptor para manejar errores 401
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    if (error.response?.status === 401) {
      // Redirigir a login
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);

export default api;
```

### 3. Crear Servicio de Autenticación

```typescript
// src/services/auth.service.ts
import api from '@/api/client';
import type { LoginRequest, LoginResponse, User } from '@/types';

export const authService = {
  async login(credentials: LoginRequest): Promise<LoginResponse> {
    const { data } = await api.post<LoginResponse>('/login', credentials);
    return data;
  },

  async register(userData: RegisterRequest) {
    const { data } = await api.post('/register', userData);
    return data;
  },

  async logout(): Promise<void> {
    await api.post('/logout');
  },

  async getMe(): Promise<User> {
    const { data } = await api.get<{ user: User }>('/me');
    return data.user;
  },
};
```

### 4. Crear Servicio de Prospectos

```typescript
// src/services/prospecto.service.ts
import api from '@/api/client';
import type {
  Prospecto,
  PaginatedResponse,
  ProspectoFormData,
  ProspectoEstadisticas
} from '@/types';

export const prospectoService = {
  async getAll(params?: {
    estado?: string;
    tipo_prospecto_id?: number;
    search?: string;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Prospecto>> {
    const { data } = await api.get<PaginatedResponse<Prospecto>>('/prospectos', { params });
    return data;
  },

  async getById(id: number): Promise<Prospecto> {
    const { data } = await api.get<{ data: Prospecto }>(`/prospectos/${id}`);
    return data.data;
  },

  async create(prospecto: ProspectoFormData): Promise<Prospecto> {
    const { data } = await api.post<{ data: Prospecto }>('/prospectos', prospecto);
    return data.data;
  },

  async update(id: number, prospecto: Partial<ProspectoFormData>): Promise<Prospecto> {
    const { data } = await api.put<{ data: Prospecto }>(`/prospectos/${id}`, prospecto);
    return data.data;
  },

  async delete(id: number): Promise<void> {
    await api.delete(`/prospectos/${id}`);
  },

  async getEstadisticas(): Promise<ProspectoEstadisticas> {
    const { data } = await api.get<{ data: ProspectoEstadisticas }>('/prospectos/estadisticas');
    return data.data;
  },
};
```

### 5. Crear Servicio de Importaciones

```typescript
// src/services/importacion.service.ts
import api from '@/api/client';
import type { Importacion, ImportarResponse, PaginatedResponse } from '@/types';

export const importacionService = {
  async getAll(params?: {
    origen?: string;
    estado?: string;
    page?: number;
  }): Promise<PaginatedResponse<Importacion>> {
    const { data } = await api.get<PaginatedResponse<Importacion>>('/importaciones', { params });
    return data;
  },

  async getById(id: number): Promise<Importacion> {
    const { data } = await api.get<{ data: Importacion }>(`/importaciones/${id}`);
    return data.data;
  },

  async importar(archivo: File, origen: string): Promise<ImportarResponse> {
    const formData = new FormData();
    formData.append('archivo', archivo);
    formData.append('origen', origen);

    const { data } = await api.post<ImportarResponse>('/importaciones', formData, {
      headers: {
        'Content-Type': 'multipart/form-data',
      },
    });
    return data;
  },

  async delete(id: number): Promise<void> {
    await api.delete(`/importaciones/${id}`);
  },
};
```

### 6. Ejemplo de Hook de React

```typescript
// src/hooks/useAuth.ts
import { create } from 'zustand';
import { authService } from '@/services/auth.service';
import type { User, LoginRequest } from '@/types';

interface AuthState {
  user: User | null;
  isAuthenticated: boolean;
  isLoading: boolean;
  login: (credentials: LoginRequest) => Promise<void>;
  logout: () => Promise<void>;
  checkAuth: () => Promise<void>;
}

export const useAuth = create<AuthState>((set) => ({
  user: null,
  isAuthenticated: false,
  isLoading: true,

  login: async (credentials) => {
    const response = await authService.login(credentials);
    set({ user: response.user, isAuthenticated: true });
  },

  logout: async () => {
    await authService.logout();
    set({ user: null, isAuthenticated: false });
  },

  checkAuth: async () => {
    try {
      const user = await authService.getMe();
      set({ user, isAuthenticated: true, isLoading: false });
    } catch {
      set({ user: null, isAuthenticated: false, isLoading: false });
    }
  },
}));
```

### 7. Componente de Login

```typescript
// src/pages/Login.tsx
import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '@/hooks/useAuth';

export function Login() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const { login } = useAuth();
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');

    try {
      await login({ email, password });
      navigate('/dashboard');
    } catch (err: any) {
      setError(err.response?.data?.errors?.email?.[0] || 'Error al iniciar sesión');
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <input
        type="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
        placeholder="Email"
        required
      />
      <input
        type="password"
        value={password}
        onChange={(e) => setPassword(e.target.value)}
        placeholder="Password"
        required
      />
      {error && <p className="error">{error}</p>}
      <button type="submit">Iniciar Sesión</button>
    </form>
  );
}
```

### 8. Variables de Entorno

```bash
# .env
VITE_API_URL=http://localhost:8000/api
```

---

## ❌ Manejo de Errores

### Estructura de Errores

```typescript
// Error 422 - Validación
{
  message: string;
  errors: {
    [field: string]: string[];
  };
}

// Error 401 - No autenticado
{
  message: "Unauthenticated.";
}

// Error 403 - Sin permisos
{
  message: string;
}

// Error 500 - Error del servidor
{
  mensaje: string;
  error?: string;
}
```

### Interceptor de Errores

```typescript
// src/api/client.ts
api.interceptors.response.use(
  (response) => response,
  (error: AxiosError<{ message: string; errors?: Record<string, string[]> }>) => {
    const status = error.response?.status;
    const data = error.response?.data;

    if (status === 401) {
      // Sesión expirada
      window.location.href = '/login';
    }

    if (status === 422 && data?.errors) {
      // Errores de validación
      console.error('Validation errors:', data.errors);
    }

    return Promise.reject(error);
  }
);
```

---

## 📝 Notas Importantes

### Cookies y CORS

Si tu frontend está en un dominio diferente (ej: `localhost:3000`):

1. **Backend Laravel**: Ya está configurado con `withCredentials: true`
2. **Frontend**: Debe usar `withCredentials: true` en axios
3. **CORS**: El backend debe permitir tu dominio frontend

### Fechas

Todas las fechas vienen en formato chileno: `dd/mm/YYYY HH:mm:ss`

```typescript
// Ejemplo de conversión a Date
const fecha = "20/11/2025 09:55:37";
const [date, time] = fecha.split(' ');
const [day, month, year] = date.split('/');
const dateObj = new Date(`${year}-${month}-${day}T${time}`);
```

### Montos de Deuda

Los montos son **enteros** (sin decimales) en pesos chilenos:

```typescript
// Formatear para mostrar
const formatMonto = (monto: number) => {
  return new Intl.NumberFormat('es-CL', {
    style: 'currency',
    currency: 'CLP',
    minimumFractionDigits: 0,
  }).format(monto);
};

// Ejemplo: 2659972 → "$2.659.972"
```

### Testing Local

```typescript
// Para testing, el backend también devuelve el access_token en JSON
// Puedes usarlo para Postman o herramientas de testing
const { access_token } = loginResponse;
```

---

## 🚀 Flujo Completo de Ejemplo

```typescript
// 1. Login
const loginResponse = await authService.login({
  email: 'test@example.com',
  password: 'password',
});
// Cookies establecidas automáticamente

// 2. Obtener prospectos
const prospectos = await prospectoService.getAll({
  estado: 'activo',
  per_page: 20,
});
// Las cookies se envían automáticamente

// 3. Crear prospecto
const nuevoProspecto = await prospectoService.create({
  nombre: 'Juan Pérez',
  email: 'juan@example.com',
  monto_deuda: 1500000, // Se asigna tipo "Deuda Media" automáticamente
});

// 4. Importar Excel
const file = document.querySelector('input[type="file"]').files[0];
const importResponse = await importacionService.importar(file, 'banco_santander');
console.log(`Importados: ${importResponse.resumen.registros_exitosos}`);

// 5. Logout
await authService.logout();
// Cookies eliminadas automáticamente
```

---

## 📞 Soporte

Si encuentras errores o necesitas más endpoints, revisa el backend en:
- Rutas: `routes/api.php`
- Controllers: `app/Http/Controllers/`
- Models: `app/Models/`

---

**Última actualización:** 20/11/2025
**Versión Backend:** Laravel 12
**Zona Horaria:** America/Santiago (Chile)
