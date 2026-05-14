# Exploración Extendida: Flujos Perpetuos - Problemas Generales

**Fecha**: 2026-05-13
**Change**: perpetual-flow-progress
**Status**: extended exploration

## Executive Summary

Identificados **7 problemas** relacionados con flujos perpetuos. El más crítico es que `EnviarEtapaChunkJob` (volúmenes >5000) **NO popula `primer_envio_at`**, lo que rompe cualquier solución basada en ese campo.

---

## 1. Ciclo de Vida Completo de una Etapa Perpetua

### Transiciones de Estado

```
pending → executing → completed → [si perpetuo] → executing (de nuevo)
```

| Transición | Responsable | Archivo | Línea |
|------------|-------------|---------|-------|
| `pending → executing` | `EnviarEtapaJob::updateInitialStates()` | EnviarEtapaJob.php | 216 |
| `pending → executing` | `CatchUpProspectosJob::scheduleStageForProspects()` | CatchUpProspectosJob.php | 392-394 |
| `executing → completed` | `BatchCompletedCallback::__invoke()` | BatchCompletedCallback.php | 41-53 |
| `completed → [reuse]` | `CatchUpProspectosJob::findOrCreateEtapaEjecucion()` | CatchUpProspectosJob.php | 439-457 |

### Problema: Loop Perpetuo de Estados

En flujos perpetuos, el ciclo es:
1. Etapa se completa (`completed`)
2. `CatchUpProspectosJob` detecta nuevos prospectos rezagados
3. Reutiliza la etapa `completed` con `force_dispatch = true`
4. `EnviarEtapaJob` la pone en `executing`
5. `BatchCompletedCallback` la vuelve a poner en `completed`
6. Repetir infinitamente

**Consecuencia**: Una etapa perpetua oscila entre `executing` y `completed`, pero nunca "progresa" en el sentido tradicional.

---

## 2. Problemas Identificados

### CRITICAL-01: `EnviarEtapaChunkJob` NO popula `primer_envio_at`

**Severidad**: CRITICAL
**Archivos afectados**: `app/Jobs/EnviarEtapaChunkJob.php`

**Causa raíz**: El campo `primer_envio_at` se setea en `EnviarEtapaJob::dispatchBatch()` (línea 421-422), pero para volúmenes >5000 prospectos, se usa `handleLargeVolume()` que despacha `EnviarEtapaChunkJob` **sin pasar por `dispatchBatch()`**.

**Evidencia**:
```php
// EnviarEtapaJob.php líneas 79-83
if ($totalProspectos > 5000) {
    $this->handleLargeVolume($ejecucion, $etapaEjecucion);
    return;  // ← Nunca llega a dispatchBatch() que setea primer_envio_at
}

// dispatchBatch() es el único lugar que setea primer_envio_at (líneas 419-422)
if ($etapaEjecucion->primer_envio_at === null) {
    $etapaEjecucion->update(['primer_envio_at' => now()]);
}
```

**Impacto**: Cualquier solución que use `primer_envio_at` para contar progreso fallará para flujos con >5000 prospectos.

**Fix propuesto**:
```php
// En handleLargeVolume(), después de updateInitialStates()
if ($etapaEjecucion->primer_envio_at === null) {
    $etapaEjecucion->update(['primer_envio_at' => now()]);
}
```

---

### HIGH-01: Estados Inconsistentes - Etapa `pending` con envíos existentes

**Severidad**: HIGH
**Archivos afectados**: `app/Jobs/CatchUpProspectosJob.php`

**Causa raíz**: En `findOrCreateEtapaEjecucion()`, cuando una etapa está en estado `pending`, se le agregan prospectos pero NO se cambia el estado a `executing` inmediatamente. El job entonces despacha `EnviarEtapaJob`, que eventualmente la pone en `executing`, pero hay una ventana donde la etapa tiene envíos creados mientras está en `pending`.

**Escenario**:
1. Etapa en `pending` recibe nuevos prospectos vía CatchUpProspectosJob
2. Se crean envíos en tabla `envios`
3. Etapa sigue en `pending` hasta que el batch termina
4. Frontend consulta: ve etapa `pending` pero con envíos existentes → confusión

**Fix propuesto**: Ya existe un fix parcial en línea 392-394, pero solo aplica cuando `shouldDispatchNow` es true. Verificar que siempre se marque `executing` antes de despachar.

---

### HIGH-02: Progreso Muestra 0/X en Flujos Perpetuos

**Severidad**: HIGH  
**Archivos afectados**: 
- `app/Http/Controllers/FlujoEjecucionController.php` (líneas 548, 754, 1116)
- `app/Http/Controllers/FlujoController.php` (línea 1957)

**Causa raíz**: El cálculo de progreso usa `estado = 'completed'`:
```php
$etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
```

En flujos perpetuos, las etapas oscilan entre `executing` y `completed` constantemente. En cualquier momento dado, muchas etapas pueden estar en `executing` (porque están procesando nuevos prospectos) aunque ya hayan procesado miles de envíos exitosamente.

**Fix propuesto**: Usar `primer_envio_at IS NOT NULL` para flujos perpetuos (requiere FIX de CRITICAL-01 primero).

---

### MEDIUM-01: Frontend Calcula Progreso Localmente

**Severidad**: MEDIUM
**Archivos afectados**: 
- `nurturing-front/src/features/flujos/hooks/useFlowExecutionTracking.ts` (líneas 161-172)
- `nurturing-front/src/features/flujos/components/FlujosTable/utils/executionProgressCalculator.ts` (líneas 81-88)

**Causa raíz**: El frontend también calcula `completadas` filtrando por `estado === 'completed'`:
```typescript
const completadas = stages.filter(stage => isStageCompleted(stage.estado)).length
```

**Impacto**: Aunque el backend se arregle, si el API sigue devolviendo `estado` por etapa, el frontend puede recalcular y mostrar valores incorrectos.

**Fix propuesto**: El backend debe devolver `progreso.completadas` calculado correctamente. El frontend debe usar ese valor sin recalcular. Alternativamente, agregar campo `ha_trabajado` o `etapas_trabajadas` en la respuesta.

---

### MEDIUM-02: `EnviarEtapaChunkJob` NO tiene `BatchCompletedCallback`

**Severidad**: MEDIUM
**Archivos afectados**: `app/Jobs/EnviarEtapaChunkJob.php` (líneas 112-117)

**Causa raíz**: Los chunks se despachan con `Bus::batch($jobs)->dispatch()` pero sin callbacks:
```php
$batch = Bus::batch($jobs)
    ->name($batchName)
    ->onConnection('database')
    ->onQueue('envios')
    ->allowFailures()
    ->dispatch();  // ← Sin then(), catch(), finally()
```

**Impacto**: 
1. No hay transición automática de estado cuando los chunks terminan
2. El estado de la etapa queda en `executing` indefinidamente
3. El último chunk debería marcar la etapa como `completed`, pero no lo hace

**Fix propuesto**: Agregar lógica para que el último chunk (cuando `chunkIndex === totalChunks - 1`) dispare la misma lógica que `BatchCompletedCallback`, o usar un mecanismo de coordinación entre chunks.

---

### LOW-01: Duplicación de Prospectos en `prospectos_ids`

**Severidad**: LOW
**Archivos afectados**: Múltiples (todos usan `array_unique(array_merge(...))`)

**Causa raíz**: Aunque se usa `array_unique()`, la operación de merge se repite en cada ejecución de `CatchUpProspectosJob`, consumiendo memoria y CPU innecesariamente.

**Impacto**: Performance en flujos con muchos prospectos.

**Fix propuesto**: Usar un índice único en BD o `firstOrCreate` pattern más eficiente.

---

### LOW-02: No hay Índice en `primer_envio_at`

**Severidad**: LOW
**Archivos afectados**: Migración `2026_05_13_190000_add_primer_envio_at_to_flujo_ejecucion_etapas.php`

**Causa raíz**: El campo existe pero no tiene índice. Si se usa para filtrar (ej: `whereNotNull('primer_envio_at')`), será lento.

**Fix propuesto**: 
```php
$table->index('primer_envio_at');
```

---

## 3. Consistencia de Datos Actual

### Queries de Verificación

```sql
-- Etapas con envíos pero primer_envio_at = NULL
SELECT fee.id, fee.node_id, fee.estado, COUNT(e.id) as envios_count
FROM flujo_ejecucion_etapas fee
LEFT JOIN envios e ON e.flujo_ejecucion_etapa_id = fee.id
WHERE fee.primer_envio_at IS NULL
GROUP BY fee.id
HAVING COUNT(e.id) > 0;

-- Etapas en pending pero con envíos (inconsistente)
SELECT fee.id, fee.node_id, fee.estado, COUNT(e.id) as envios_count
FROM flujo_ejecucion_etapas fee
LEFT JOIN envios e ON e.flujo_ejecucion_etapa_id = fee.id
WHERE fee.estado = 'pending'
GROUP BY fee.id
HAVING COUNT(e.id) > 0;

-- Etapas en executing con 0 envíos pendientes (deberían ser completed)
SELECT fee.id, fee.node_id, fee.estado
FROM flujo_ejecucion_etapas fee
WHERE fee.estado = 'executing'
AND NOT EXISTS (
    SELECT 1 FROM envios e 
    WHERE e.flujo_ejecucion_etapa_id = fee.id 
    AND e.estado = 'pendiente'
);
```

---

## 4. Dependencias Entre Problemas

```
CRITICAL-01 ──────────────────────┐
(primer_envio_at no poblado)      │
                                  ▼
                            HIGH-02 (progreso 0/X)
                                  │
                                  ▼
                            MEDIUM-01 (frontend recalcula)

MEDIUM-02 ────────────────────────┐
(chunks sin callback)            │
                                  ▼
                            HIGH-01 (estados inconsistentes)
```

---

## 5. Orden de Fixes Recomendado

| Prioridad | Problema | Esfuerzo | Descripción |
|-----------|----------|----------|-------------|
| 1 | CRITICAL-01 | Low | Agregar `primer_envio_at` en `handleLargeVolume()` |
| 2 | MEDIUM-02 | Medium | Agregar coordinación de callbacks en chunks |
| 3 | HIGH-01 | Low | Asegurar `executing` antes de despachar siempre |
| 4 | HIGH-02 | Low | Usar `primer_envio_at` en cálculo de progreso |
| 5 | MEDIUM-01 | Low | Frontend usa valor del API, no recalcula |
| 6 | LOW-02 | Trivial | Agregar índice a `primer_envio_at` |
| 7 | LOW-01 | Low | Optimizar merge de prospectos_ids |

---

## 6. Frontend Expectations

El frontend usa estos campos del API para mostrar progreso:

| Campo | Usado En | Descripción |
|-------|----------|-------------|
| `progreso.completadas` | ProgressDisplay.tsx, FlujoTableRow.tsx | Número de etapas completadas |
| `progreso.total` | ProgressDisplay.tsx | Total de etapas |
| `progreso.porcentaje` | ProgressDisplay.tsx | % calculado |
| `etapa.estado` | FlujoDetailDialog.tsx | Color del badge por etapa |
| `etapas_completadas` | ExecutionHistoryPanel.tsx | Para cohortes |

**Recomendación**: El backend debe devolver `progreso.completadas` ya calculado correctamente para flujos perpetuos. El frontend NO debe recalcular, solo mostrar.

---

## 7. Mecanismos Anti-Duplicados Existentes

| Mecanismo | Ubicación | Alcance |
|-----------|-----------|---------|
| `ShouldBeUnique` + `uniqueFor` | EnviarEmailEtapaProspectoJob | Previene jobs duplicados en cola (5 min) |
| Distributed lock | EnviarEmailEtapaProspectoJob::handle() | Previene procesamiento paralelo |
| Check envío existente | EnvioService líneas 304-326 | Previene duplicados en BD |
| `array_unique()` en merges | CatchUpProspectosJob, BatchCompletedCallback | Previene duplicados en arrays |

**Conclusión**: Hay protección suficiente contra duplicados de envío. El problema es de **estados** y **conteo de progreso**, no de duplicación de mensajes.

---

## Ready for Proposal

**Yes** — La exploración extendida está completa. El siguiente paso es crear un proposal que:

1. Defina el fix para CRITICAL-01 (`primer_envio_at` en chunks)
2. Defina el fix para MEDIUM-02 (coordinación de chunks)
3. Actualice los 4 endpoints que calculan progreso (3 en FlujoEjecucionController + 1 en FlujoController)
4. Documente el cambio en el API response (si se renombra a `etapas_trabajadas`)
5. Incluya script de migración de datos para corregir etapas existentes con `primer_envio_at = NULL`
