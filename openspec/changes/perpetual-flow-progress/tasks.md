# Tasks: Perpetual Flow Progress Fixes

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 150-180 |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR |
| Delivery strategy | auto-chain |
| Chain strategy | pending |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

## Phase 1: Foundation (CRITICAL Fix)

- [ ] 1.1 **Fix handleLargeVolume()** in `app/Jobs/EnviarEtapaJob.php` ~line 105: Add `primer_envio_at` set before chunk dispatch
  ```php
  if ($etapaEjecucion->primer_envio_at === null) {
      $etapaEjecucion->update(['primer_envio_at' => now()]);
  }
  ```

## Phase 2: Data Migration

- [ ] 2.1 **Create backfill migration** `database/migrations/YYYY_MM_DD_HHMMSS_backfill_primer_envio_at.php`
  - UPDATE `flujo_ejecucion_etapas` SET `primer_envio_at` = MIN(envios.created_at) WHERE has envios AND primer_envio_at IS NULL
  - Execute in low-traffic window

## Phase 3: Progress Calculation

- [ ] 3.1 **FlujoEjecucionController::show()** line 548: Conditional progress count
- [ ] 3.2 **FlujoEjecucionController::getActiveExecution()** line 754: Same pattern
- [ ] 3.3 **FlujoEjecucionController::batchExecutionState()** line 1117: Same pattern
- [ ] 3.4 **FlujoEjecucionController::cohortesActivas()** line 1957: Same pattern
- [ ] 3.5 **FlujoController::cohortesActivas()**: Same conditional pattern

Pattern for all:
```php
if ($ejecucion->es_perpetuo) {
    $etapasCompletadas = $ejecucion->etapas->whereNotNull('primer_envio_at')->count();
} else {
    $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
}
```

## Phase 4: Testing

- [ ] 4.1 **Unit test** `handleLargeVolume()` sets `primer_envio_at` for >5000 prospects
- [ ] 4.2 **Unit test** perpetual progress counts `primer_envio_at`, non-perpetual counts `estado=completed`
- [ ] 4.3 **Feature test** API returns correct `progreso.completadas` for perpetual flow with 3/5 stages processed
- [ ] 4.4 **Verify** backfill migration: no etapas with envios AND primer_envio_at = NULL post-migration

## Implementation Order

1 → 2 → 3 → 4 (strict dependency: code fix → data backfill → calculation change → verification)
