# Design: Fix Duplicate Envios

## Technical Approach

**Defense in Depth**: Corregir duplicados de SMS en flujos perpetuos con validación en dos capas — aplicación primero (inmediato), luego DB (constraint). El fix de SMS replica exactamente el patrón existente de email (líneas 304-326 de EnvioService.php).

## Architecture Decisions

| Decision | Alternatives | Rationale |
|----------|-------------|-----------|
| **Check en app primero, constraint después** | Constraint first (rollback complejo), App only (sin garantía DB) | Permite deploy sin downtime, limpieza de datos entre fases |
| **Unique partial constraint (WHERE flujo_ejecucion_etapa_id IS NOT NULL)** | Constraint simple (bloquea envíos sin etapa), Constraint con COALESCE | Envíos sin etapa (Jobs simples) no deben bloquearse, solo flujos perpetuos |
| **Replicar patrón email exacto** | Extraer método compartido, Crear trait | Minimiza diff y riesgo; refactor puede hacerse post-fix |
| **Query DELETE con subquery MIN(id)** | Soft delete, Marcar duplicados sin borrar | Duplicados no tienen valor, cleanup limpio evita confusión en reportes |

## Data Flow

```
EnviarEtapaJob → EnvioService::enviarSmsAProspecto()
                           │
                           ▼
                 [NEW] Check duplicado
                           │
              ┌────────────┴────────────┐
              │                         │
          Existe                   No existe
              │                         │
      Return existente          Crear Envio
       (skipped=true)                  │
                                       ▼
                              Enviar via Athena
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `app/Services/EnvioService.php` | Modify | Agregar check duplicados en `enviarSmsAProspecto()` (líneas ~597-620) |
| `database/migrations/2026_05_14_*_cleanup_duplicate_envios.php` | Create | Artisan command o migration para eliminar duplicados existentes |
| `database/migrations/2026_05_14_*_add_unique_envio_constraint.php` | Create | Unique constraint parcial en (prospecto_id, flujo_ejecucion_etapa_id, canal) |

## Interfaces / Contracts

### EnvioService::enviarSmsAProspecto() — Cambio

```php
// Agregar después de línea 605 (después de validar teléfono)
// ANTES de $envio = null;

// Check duplicado (paridad con email)
if ($etapaEjecucionId) {
    $envioExistente = Envio::where('prospecto_id', $prospecto->id)
        ->where('flujo_ejecucion_etapa_id', $etapaEjecucionId)
        ->where('canal', 'sms')
        ->whereIn('estado', ['enviado', 'abierto', 'clickeado', 'pendiente'])
        ->first();

    if ($envioExistente) {
        Log::debug('EnvioService: SMS ya enviado para este prospecto en esta etapa, omitiendo', [
            'prospecto_id' => $prospecto->id,
            'etapa_ejecucion_id' => $etapaEjecucionId,
            'envio_existente_id' => $envioExistente->id,
            'estado' => $envioExistente->estado,
        ]);

        return [
            'success' => true,
            'envio_id' => $envioExistente->id,
            'error' => null,
            'skipped' => true,
        ];
    }
}
```

### Cleanup Migration

```php
// Ejecutar ANTES del unique constraint
DB::statement("
    DELETE FROM envios 
    WHERE id NOT IN (
        SELECT * FROM (
            SELECT MIN(id) 
            FROM envios 
            WHERE flujo_ejecucion_etapa_id IS NOT NULL
            GROUP BY prospecto_id, flujo_ejecucion_etapa_id, canal
        ) AS keep_ids
    )
    AND flujo_ejecucion_etapa_id IS NOT NULL
");
```

### Unique Constraint Migration

```php
Schema::table('envios', function (Blueprint $table) {
    // Partial unique: solo para envíos con etapa de ejecución
    $table->unique(
        ['prospecto_id', 'flujo_ejecucion_etapa_id', 'canal'],
        'envios_prospecto_etapa_canal_unique'
    );
});
```

**Nota MySQL**: MySQL no soporta partial indexes. El unique constraint aplicará a todas las filas, pero `flujo_ejecucion_etapa_id` nullable permite múltiples NULLs (MySQL trata NULL como valor único).

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | Check duplicado retorna envío existente | Mock Envio::where, verificar skipped=true |
| Unit | Envío nuevo cuando no hay duplicado | Verificar Envio::create se llama |
| Integration | Migration no elimina envíos únicos | Seed datos, run migration, assert count |
| Integration | Constraint previene duplicados | Intentar insert duplicado, catch exception |

## Migration / Rollout

### Orden de Ejecución

1. **Fase 1 — Aplicación** (deploy 1):
   - Deploy código con check en `enviarSmsAProspecto()`
   - Nuevos duplicados prevenidos inmediatamente
   - Sin cambios DB, rollback simple (git revert)

2. **Fase 2 — Cleanup** (maintenance window):
   - Ejecutar cleanup migration manualmente o automático
   - Verificar: `SELECT COUNT(*) FROM envios GROUP BY prospecto_id, flujo_ejecucion_etapa_id, canal HAVING COUNT(*) > 1`
   - Resultado esperado: 0 filas

3. **Fase 3 — Constraint** (deploy 2):
   - Deploy migration con unique constraint
   - Si falla: duplicados aún existen → re-run cleanup

### Rollback Plan

| Fase | Rollback |
|------|----------|
| 1 | Revert commit (sin impacto DB) |
| 2 | N/A — cleanup ya ejecutado, datos perdidos (aceptable: eran duplicados) |
| 3 | `ALTER TABLE envios DROP INDEX envios_prospecto_etapa_canal_unique` |

## Open Questions

- [x] ¿Los Jobs `EnviarSmsProspectoJob` y `EnviarEmailProspectoJob` necesitan check? **No** — no pasan `flujo_ejecucion_etapa_id`, son para flujos simples sin etapas de ejecución
- [x] ¿El constraint debe ser partial? **No** — MySQL no soporta partial indexes, pero nullable column permite múltiples NULLs
