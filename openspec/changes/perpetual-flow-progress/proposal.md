# Proposal: Perpetual Flow Progress Fixes

## Intent

Flujos perpetuos muestran **0/X etapas completadas** en la UI aunque miles de envíos ya fueron procesados. La causa raíz es que `EnviarEtapaChunkJob` (volúmenes >5000) **no popula `primer_envio_at`**, y el cálculo de progreso usa `estado = 'completed'` que oscila infinitamente en flujos perpetuos.

**Problema de negocio**: Gerencia no puede ver el progreso real de campañas grandes.

## Scope

### In Scope
- Fix `EnviarEtapaChunkJob` para poblar `primer_envio_at`
- Coordinación de chunks via `BatchCompletedCallback`
- Cálculo condicional de progreso para flujos perpetuos
- Migración de datos existentes (backfill `primer_envio_at`)
- Fix estado inconsistente en `CatchUpProspectosJob`

### Out of Scope
- Cambios en UI/UX del frontend (ya consume `progreso.completadas` del API)
- Optimización de duplicación en `prospectos_ids` (LOW priority)
- Índice en `primer_envio_at` (trivial, separado)
- Refactor de `BatchCompletedCallback` para otros propósitos

## Capabilities

### New Capabilities
- None

### Modified Capabilities
- `flujo-execution-tracking`: Cambio en cálculo de progreso para flujos perpetuos

## Approach

| Fix | Archivo | Cambio |
|-----|---------|--------|
| CRITICAL-01 | `EnviarEtapaJob::handleLargeVolume()` | Setear `primer_envio_at` antes de despachar chunks |
| MEDIUM-02 | `EnviarEtapaChunkJob` | Agregar tracking de chunks completados, callback en último |
| HIGH-01 | `CatchUpProspectosJob::scheduleStageForProspects()` | Ya fixed - marcar `executing` antes de despachar |
| HIGH-02 | `FlujoEjecucionController` | Usar `primer_envio_at IS NOT NULL` cuando `es_perpetuo` |
| MEDIUM-01 | Frontend | No cambia - ya usa `progreso.completadas` del API |

### Detalle CRITICAL-01
```php
// EnviarEtapaJob::handleLargeVolume() - agregar al inicio
if ($etapaEjecucion->primer_envio_at === null) {
    $etapaEjecucion->update(['primer_envio_at' => now()]);
}
```

### Detalle HIGH-02
```php
// FlujoEjecucionController - métodos show(), getActiveExecution(), batchExecutionState()
if ($ejecucion->es_perpetuo) {
    $etapasCompletadas = $ejecucion->etapas->whereNotNull('primer_envio_at')->count();
} else {
    $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
}
```

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `app/Jobs/EnviarEtapaJob.php` | Modified | Agregar seteo de `primer_envio_at` en `handleLargeVolume()` |
| `app/Jobs/EnviarEtapaChunkJob.php` | Modified | Agregar coordinación de chunks completados |
| `app/Http/Controllers/FlujoEjecucionController.php` | Modified | Cálculo condicional en 3 métodos |
| `database/migrations/` | New | Backfill migration para `primer_envio_at` |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Race condition en `primer_envio_at` | Low | Campo es idempotente, solo se setea si es null |
| Performance del backfill migration | Medium | Usar chunked updates, ejecutar en off-hours |
| Chunks sin coordinación pierden estado | Medium | Agregar flag de último chunk, callback condicional |

## Rollback Plan

1. Revertir cambio en `FlujoEjecucionController` - progreso vuelve a usar `estado = completed`
2. Chunks siguen funcionando, solo pierden tracking
3. `primer_envio_at` se mantiene para futuro uso
4. No hay breaking changes en API - `progreso.completadas` sigue existiendo

## Dependencies

- `primer_envio_at` column ya existe (migration 2026_05_13_190000)
- `FlujoEjecucionEtapa::scopeHanProcesadoEnvios()` ya existe

## Success Criteria

- [ ] Flujos perpetuos muestran X/Y donde X = etapas con `primer_envio_at`
- [ ] Flujos normales siguen usando `estado = completed`
- [ ] `EnviarEtapaChunkJob` (>5000 prospectos) popula `primer_envio_at`
- [ ] Backfill migration actualiza etapas existentes con envíos
- [ ] No regression en flujos no-perpetuos
