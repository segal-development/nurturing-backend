# Exploration: Duplicate Envios in Perpetual Flows

## Current State

En el sistema de nurturing, los **flujos perpetuos** permiten agregar prospectos continuamente y sincronizarlos con su etapa correcta mediante `CatchUpProspectosJob`. El Flujo Onboarding (ID 49, Ejecución 52) presenta un problema de envíos duplicados:

- **68 prospectos** en la ejecución
- **37 prospectos distintos** han recibido envíos
- **70, 68, 72 envíos** por etapa (más envíos que prospectos únicos)

Esto indica que **algunos prospectos recibieron el mismo email/SMS múltiples veces**.

## Root Cause Analysis

### Problema Principal: Falta de Check de Duplicados en SMS

**Ubicación**: `app/Services/EnvioService.php`

El método `enviarEmailAProspecto()` (líneas 305-326) **SÍ tiene** verificación de duplicados:

```php
if ($etapaEjecucionId) {
    $envioExistente = Envio::where('prospecto_id', $prospecto->id)
        ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
        ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'pendiente'])
        ->first();

    if ($envioExistente) {
        // Skip - ya existe envío
        return ['success' => true, 'envio_id' => $envioExistente->id, 'skipped' => true];
    }
}
```

**PERO** el método `enviarSmsAProspecto()` (líneas 591-669) **NO tiene este check**. Crea el envío directamente:

```php
$envio = Envio::create([
    'prospecto_id' => $prospecto->id,
    // ... sin verificar si ya existe
]);
```

### Problema Secundario: Race Conditions en CatchUpProspectosJob

**Ubicación**: `app/Jobs/CatchUpProspectosJob.php`

El job procesa prospectos en chunks de 1000 y despacha `EnviarEtapaJob` para cada chunk. Si el job se ejecuta múltiples veces antes de que `ultima_etapa_node_id` se actualice, puede despachar el mismo prospecto múltiples veces.

**Flujo del problema**:

1. `CatchUpProspectosJob` encuentra prospecto con `ultima_etapa_node_id = NULL`
2. Despacha `EnviarEtapaJob` con ese prospecto
3. ANTES de que `EnviarEtapaJob` complete y actualice `ultima_etapa_node_id`...
4. Otro `CatchUpProspectosJob` corre (por cron o re-queue)
5. Encuentra el MISMO prospecto todavía con `ultima_etapa_node_id = NULL`
6. Despacha OTRO `EnviarEtapaJob` con el mismo prospecto

### Problema Terciario: El Check de Email No Incluye Canal

El check existente busca por `prospecto_id + flujo_ejecucion_etapa_id` pero **no filtra por canal**. Si `tipo_mensaje = 'ambos'`, podría haber confusión aunque en la práctica son métodos separados.

## Affected Areas

- `app/Services/EnvioService.php` — Falta check de duplicados en `enviarSmsAProspecto()`
- `app/Jobs/CatchUpProspectosJob.php` — Race condition al despachar jobs
- `app/Jobs/EnviarEtapaJob.php` — Crea jobs sin verificar estado actual del prospecto
- `database/migrations/2025_11_19_170145_create_envios_table.php` — No tiene unique constraint

## Approaches

### 1. **Fix en EnvioService (Quick Fix)** — Agregar check de duplicados a SMS

Agregar la misma lógica de verificación que tiene `enviarEmailAProspecto()` al método `enviarSmsAProspecto()`:

```php
// En enviarSmsAProspecto(), antes de Envio::create
if ($etapaEjecucionId) {
    $envioExistente = Envio::where('prospecto_id', $prospecto->id)
        ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
        ->where('canal', 'sms')
        ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'pendiente'])
        ->first();

    if ($envioExistente) {
        return ['success' => true, 'envio_id' => $envioExistente->id, 'skipped' => true];
    }
}
```

- **Pros**: Rápido, bajo riesgo, soluciona el problema inmediato
- **Cons**: No previene race conditions, depende de lógica de aplicación
- **Effort**: Low

### 2. **Unique Constraint en DB** — Prevención a nivel de base de datos

Agregar un índice único compuesto:

```php
$table->unique(['prospecto_id', 'flujo_ejecucion_etapa_id', 'canal'], 'envios_unique_prospecto_etapa_canal');
```

- **Pros**: Garantía absoluta a nivel de DB, previene cualquier race condition
- **Cons**: Requiere migración, podría fallar si ya hay duplicados en producción, necesita manejar `QueryException`
- **Effort**: Medium

### 3. **Distributed Lock en CatchUpProspectosJob** — Prevenir despacho duplicado

Usar Redis/Cache lock antes de despachar `EnviarEtapaJob`:

```php
$lockKey = "catchup:prospect:{$prospectoId}:stage:{$stageNodeId}";
if (Cache::lock($lockKey, 300)->get()) {
    EnviarEtapaJob::dispatch(...);
}
```

- **Pros**: Previene el problema en origen, permite cancelar jobs en vuelo
- **Cons**: Más complejo, requiere infraestructura de locks distribuidos
- **Effort**: Medium-High

### 4. **Combinación de 1 + 2** — Defense in Depth

Implementar tanto el check de aplicación (enfoque 1) como el unique constraint (enfoque 2).

- **Pros**: Máxima seguridad, la DB es la última línea de defensa
- **Cons**: Más trabajo, necesita plan de migración para datos existentes
- **Effort**: Medium

## Recommendation

**Approach 4: Combinación de Fix en EnvioService + Unique Constraint**

**Razones**:

1. **Inmediato**: El fix en `EnvioService.php` se puede deployear hoy mismo
2. **Robusto**: El unique constraint previene cualquier edge case futuro
3. **Defense in Depth**: La aplicación previene, la DB garantiza

**Plan de implementación**:

1. **Fase 1 (inmediato)**: Agregar check de duplicados a `enviarSmsAProspecto()`
2. **Fase 2 (siguiente deploy)**: 
   - Script para limpiar duplicados existentes (mantener el más antiguo)
   - Migración con unique constraint
   - Agregar `try/catch` para `QueryException` con lógica de retry

## Risks

- **Datos existentes**: La migración con unique constraint fallará si hay duplicados. Necesita script de limpieza primero.
- **Performance**: El check adicional agrega una query por SMS. Impacto mínimo dado que ya hacemos múltiples queries por envío.
- **Jobs en vuelo**: Pueden existir jobs duplicados ya encolados. La primera fase los manejará con el check de aplicación.

## Data Verification Needed

Antes de aplicar el fix, verificar magnitud del problema:

```sql
-- Prospectos con múltiples envíos en la misma etapa
SELECT 
    prospecto_id, 
    flujo_ejecucion_etapa_id, 
    canal,
    COUNT(*) as total
FROM envios 
WHERE flujo_ejecucion_etapa_id IN (503, 504, 505)
GROUP BY prospecto_id, flujo_ejecucion_etapa_id, canal
HAVING COUNT(*) > 1
ORDER BY total DESC;

-- Total de duplicados por etapa
SELECT 
    flujo_ejecucion_etapa_id,
    canal,
    COUNT(*) as total_envios,
    COUNT(DISTINCT prospecto_id) as prospectos_unicos,
    COUNT(*) - COUNT(DISTINCT prospecto_id) as duplicados
FROM envios 
WHERE flujo_ejecucion_etapa_id IN (503, 504, 505)
GROUP BY flujo_ejecucion_etapa_id, canal;
```

## Ready for Proposal

**Yes** — La causa raíz está identificada y el fix es claro. El orchestrator puede proceder a crear una propuesta con:

- Fix inmediato en `EnvioService.php`
- Plan de migración para unique constraint
- Script de limpieza de duplicados existentes
