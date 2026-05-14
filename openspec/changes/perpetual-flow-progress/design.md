# Design: Perpetual Flow Progress Fixes

## Technical Approach

Fix progress calculation for perpetual flows by: (1) ensuring `primer_envio_at` is set in `handleLargeVolume()`, (2) using `primer_envio_at IS NOT NULL` for progress count when `es_perpetuo=true`, (3) backfill existing data.

## Architecture Decisions

| Decision | Alternatives | Rationale |
|----------|-------------|-----------|
| Use `primer_envio_at` for perpetual progress | Add new column, use envios count | Column exists, idempotent, no schema change |
| Conditional logic in controller | Separate endpoints | Minimal change, same API contract |
| Backfill via migration | Manual script | Atomic, reversible, auditable |
| No BatchCompletedCallback changes | Coordinate all chunks | EnviarEtapaChunkJob sets per-chunk, simpler |

## Data Flow

```
Prospecto enters perpetual flow
           │
           ▼
CatchUpProspectosJob detects needs stage X
           │
           ├─► Sets etapa.estado = 'executing'
           │
           ▼
EnviarEtapaJob.handle()
           │
           ├─► Volume > 5000? ──► handleLargeVolume()
           │                            │
           │                            ├─► [FIX] Set primer_envio_at = now()
           │                            │
           │                            ▼
           │                      EnviarEtapaChunkJob (per chunk)
           │                            │
           │                            ▼
           │                      Batch completes
           │
           └─► Volume <= 5000 ──► dispatchBatch()
                                       │
                                       ├─► [OK] Already sets primer_envio_at
                                       │
                                       ▼
                                 BatchCompletedCallback
                                       │
                                       ▼
                                 Sets estado = 'completed'
                                       │
                                       ▼
API calculates progress
           │
           └─► [FIX] if (es_perpetuo) count WHERE primer_envio_at IS NOT NULL
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Jobs/EnviarEtapaJob.php` | Modify | Add `primer_envio_at` in `handleLargeVolume()` ~line 118 |
| `app/Jobs/CatchUpProspectosJob.php` | Verify | Lines 389-394 already fixed (verified) |
| `app/Http/Controllers/FlujoEjecucionController.php` | Modify | 4 locations: show(), getActiveExecution(), batchExecutionState(), calcularMetricasSync() |
| `app/Http/Controllers/FlujoController.php` | Modify | cohortesActivas() method |
| `database/migrations/` | Create | Backfill migration for existing data |

## Interfaces / Contracts

No new interfaces. Existing API response unchanged:

```php
// Progress response structure (unchanged)
'progreso' => [
    'total_etapas' => int,
    'completadas' => int,  // ← calculation changes for perpetual
    // ...
]
```

## Specific Code Changes

### 1. EnviarEtapaJob.php - handleLargeVolume() (~line 105)

```php
private function handleLargeVolume(FlujoEjecucion $ejecucion, FlujoEjecucionEtapa $etapaEjecucion): void
{
    // [FIX] Set primer_envio_at BEFORE dispatching chunks
    if ($etapaEjecucion->primer_envio_at === null) {
        $etapaEjecucion->update(['primer_envio_at' => now()]);
    }

    $totalProspectos = count($this->prospectoIds);
    // ... rest of method unchanged
```

### 2. FlujoEjecucionController.php - 4 Locations

Pattern to apply (search for `where('estado', 'completed')->count()`):

```php
// BEFORE (all 4 locations)
$etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();

// AFTER
if ($ejecucion->es_perpetuo) {
    $etapasCompletadas = $ejecucion->etapas->whereNotNull('primer_envio_at')->count();
} else {
    $etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();
}
```

**Locations in FlujoEjecucionController.php:**
- Line 548 (show method)
- Line 754 (getActiveExecution method) 
- Line 1117 (batchExecutionState method)
- Lines 1957-1959 (inside calcularMetricasSync / cohortesActivas callback)

### 3. FlujoController.php - cohortesActivas() (~line 1957)

```php
// BEFORE
$etapasCompletadas = $ejecucion->etapas->where('estado', 'completed')->count();

// AFTER
$etapasCompletadas = $ejecucion->es_perpetuo 
    ? $ejecucion->etapas->whereNotNull('primer_envio_at')->count()
    : $ejecucion->etapas->where('estado', 'completed')->count();
```

### 4. Backfill Migration

```php
// database/migrations/2026_05_14_000000_backfill_primer_envio_at.php
public function up(): void
{
    // Update etapas that have envios but no primer_envio_at
    DB::statement("
        UPDATE flujo_ejecucion_etapas 
        SET primer_envio_at = (
            SELECT MIN(created_at) 
            FROM envios 
            WHERE envios.flujo_ejecucion_etapa_id = flujo_ejecucion_etapas.id
              AND envios.estado IN ('enviado', 'abierto', 'clickeado')
        )
        WHERE primer_envio_at IS NULL 
          AND EXISTS (
            SELECT 1 FROM envios 
            WHERE envios.flujo_ejecucion_etapa_id = flujo_ejecucion_etapas.id
              AND envios.estado IN ('enviado', 'abierto', 'clickeado')
          )
    ");
}

public function down(): void
{
    // No-op: we don't want to null out legitimate data
}
```

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `primer_envio_at` set in handleLargeVolume | Mock etapaEjecucion, assert update called |
| Unit | Progress calc conditional | Test both perpetuo=true and false |
| Integration | Full flow >5000 prospects | Seed data, run job, verify API response |
| Manual | Existing perpetual flows | Check dashboard shows X/Y instead of 0/Y |

## Edge Cases

| Edge Case | Behavior |
|-----------|----------|
| Job fails after `primer_envio_at` set | Safe: retries won't re-set (null check) |
| Parallel CatchUpJobs | Safe: etapa lookup uses `firstOrCreate`, `primer_envio_at` idempotent |
| Migration fails mid-way | Safe: UPDATE is per-row, can re-run |
| Etapa with no envios | `primer_envio_at` stays NULL, not counted |
| Perpetual with mixed chunks | First chunk sets timestamp, others skip |

## Migration / Rollout

1. Deploy code changes (no behavior change without migration)
2. Run backfill migration during low-traffic (estimated: <5 min for 10K etapas)
3. Verify dashboard shows correct progress
4. Monitor logs for any edge cases

## Open Questions

- [x] CatchUpProspectosJob fix verified at lines 389-394 (sets executing before dispatch)
- [ ] None - design is complete
