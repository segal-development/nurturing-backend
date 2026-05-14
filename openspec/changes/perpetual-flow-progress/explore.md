# Exploration: Perpetual Flow Progress Display

**Change**: perpetual-flow-progress  
**Date**: 2026-05-13  
**Status**: Exploration Complete

## Problem Statement

En flujos perpetuos, el UI muestra "0/X etapas" porque el conteo usa `estado = completed`, pero las etapas perpetuas nunca llegan a ese estado - quedan en `executing` indefinidamente porque siempre reciben nuevos prospectos.

## Current State

### Backend - Cálculo de progreso

El progreso de etapas se calcula contando etapas con `estado = 'completed'`:

**FlujoEjecucionController.php** (líneas 548, 754, 1116):
```php
$etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
$porcentaje = $totalEtapas > 0 ? min(100, round(($etapasCompletadas / $totalEtapas) * 100, 2)) : 0;
```

Se usa en 3 métodos:
- `show()` - detalle de ejecución
- `getActiveExecution()` - ejecución activa
- `batchExecutionState()` - estado batch de múltiples flujos

### Frontend - Visualización

**executionProgressCalculator.ts**:
```ts
const completadas = stages.filter(stage => stage.estado === 'completed').length
```

**ProgressDisplay.tsx** muestra `{completadas}/{total} etapas`

### Ciclo de vida de una etapa perpetua

1. **Creación**: `estado = 'pending'` (FlujoEjecucionController::execute)
2. **Inicio**: `estado = 'executing'` (EnviarEtapaJob::updateInitialStates)
3. **Batch completo**: `estado = 'completed'` (BatchCompletedCallback)
4. **Nuevos prospectos**: CatchUpProspectosJob detecta etapa `completed`, hace `force_dispatch = true`
5. **Re-procesamiento**: EnviarEtapaJob la vuelve a poner en `executing`

El ciclo `executing ↔ completed` se repite indefinidamente mientras lleguen nuevos prospectos.

## Campo primer_envio_at

Ya existe el campo `primer_envio_at` en `FlujoEjecucionEtapa`:

**Modelo** (FlujoEjecucionEtapa.php):
```php
'primer_envio_at', // Cuándo procesó envíos por primera vez
```

**Scope disponible**:
```php
public function scopeHanProcesadoEnvios($query)
{
    return $query->whereNotNull('primer_envio_at');
}
```

**Población** (EnviarEtapaJob::dispatchBatch):
```php
if ($etapaEjecucion->primer_envio_at === null) {
    $etapaEjecucion->update(['primer_envio_at' => now()]);
}
```

## Affected Areas

| Archivo | Método/Función | Impacto |
|---------|----------------|---------|
| `FlujoEjecucionController.php` | `show()`, `getActiveExecution()`, `batchExecutionState()` | Cambiar lógica de conteo |
| `FlujoController.php` | stats (línea 1957) | Posible cambio |
| `executionProgressCalculator.ts` | `calculateExecutionProgress()` | NO requiere cambios |
| `ProgressDisplay.tsx` | render | NO requiere cambios |

## Approaches

### 1. Usar `primer_envio_at` para flujos perpetuos (RECOMENDADO)

Cambiar la lógica de conteo solo cuando `es_perpetuo = true`:

```php
if ($ejecucion->es_perpetuo) {
    $etapasCompletadas = $ejecucion->etapas->whereNotNull('primer_envio_at')->count();
} else {
    $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
}
```

| Aspecto | Evaluación |
|---------|------------|
| **Pros** | Ya existe el campo, no requiere migraciones, mínimo impacto |
| **Cons** | Lógica bifurcada (perpetuo vs normal) |
| **Effort** | Low |
| **Risk** | Low |

### 2. Nuevo campo `ha_trabajado` boolean

Agregar campo explícito que se marca cuando la etapa procesa al menos un envío.

| Aspecto | Evaluación |
|---------|------------|
| **Pros** | Semántica más clara |
| **Cons** | Requiere migración, duplica info de `primer_envio_at` |
| **Effort** | Medium |
| **Risk** | Low |

### 3. Cambiar semántica de `completed` para perpetuos

Una etapa perpetua está "completed" cuando procesó al menos un batch.

| Aspecto | Evaluación |
|---------|------------|
| **Pros** | No cambia queries del frontend |
| **Cons** | Rompe semántica de `completed`, confusión |
| **Effort** | Medium |
| **Risk** | High |

## Recommendation

**Approach 1**: Usar `primer_envio_at IS NOT NULL` como criterio de "etapa que ha trabajado" solo para flujos perpetuos.

### Cambios necesarios

1. **FlujoEjecucionController.php**: 3 métodos con lógica condicional
2. **Verificar EnviarEtapaChunkJob**: Que popule `primer_envio_at` para volúmenes grandes
3. **Frontend**: NO requiere cambios

## Risks

1. **EnviarEtapaChunkJob no popula `primer_envio_at`**: El campo se popula en `EnviarEtapaJob::dispatchBatch()`, pero para volúmenes >5000 se usa `handleLargeVolume()` que despacha chunks. VERIFICAR.

2. **Inconsistencia visual**: Una etapa `executing` con `primer_envio_at` se contará como "completada" pero mostrará estado azul. Considerar si el label debe ser "trabajadas" vs "completadas".

3. **Otros dependientes de `estado = completed`**: Verificado que CostoService y Observers no se afectan (filtran FlujoEjecucion, no etapas).

## Questions & Answers

### Q1: Diferencia semántica "completada" vs "trabajó"
- **Técnico**: `estado = completed` = "no hay trabajo pendiente AHORA"
- **Negocio**: `primer_envio_at IS NOT NULL` = "ya envió mensajes"
- En perpetuos, la semántica de negocio es la correcta para gerencia

### Q2: ¿primer_envio_at está poblado correctamente?
- Volumen normal (<5000): Sí, en `EnviarEtapaJob::dispatchBatch()`
- Volumen grande (>5000): **VERIFICAR** - `EnviarEtapaChunkJob` podría no popularlo
- Existe migración con backfill desde tabla `envios`

### Q3: ¿Otros lugares que se romperían?
- CostoService.php: NO - filtra FlujoEjecucion
- Observers: NO - lógica de transición
- Tests: NO - assertions siguen válidas

### Q4: ¿Approach menos invasivo?
- Usar `primer_envio_at` con condicional `es_perpetuo` en 3 endpoints
- Frontend sin cambios
- Considerar renombrar a `etapas_trabajadas` en respuesta API

## Next Steps

1. Crear proposal con scope exacto
2. Verificar `EnviarEtapaChunkJob` popula `primer_envio_at`
3. Definir si renombrar campo en API response
4. Escribir specs con escenarios de test

## Ready for Proposal

**Yes** — Exploración completa. Proceder a fase de proposal.
