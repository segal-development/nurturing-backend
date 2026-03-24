# Runbook: Stuck Import Recovery

## Symptoms

- Import shows "Importando..." for > 10 minutes in the UI
- Alert: "Nurturing: Stuck Imports" triggered
- User reports import not completing
- Health endpoint shows `status: critical`

## Quick Check

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://api-nurturing-qa.segal.cl/api/importaciones/health
```

Response when stuck:
```json
{
  "status": "critical",
  "stuck_imports": 2,
  "oldest_processing_minutes": 45,
  "queued_jobs": 10,
  "last_recovery_run": "2026-03-24T15:30:00Z"
}
```

## Diagnosis

### 1. Check specific stuck imports

```bash
# SSH to VM and run tinker
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a

cd /home/mtoro6/nurturing-backend
php artisan tinker
```

```php
// Find stuck imports
Importacion::where('estado', 'procesando')
  ->where('updated_at', '<', now()->subMinutes(10))
  ->get(['id', 'nombre_archivo', 'estado', 'updated_at', 'registros_totales', 'registros_procesados']);
```

### 2. Check VM worker status

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  sudo supervisorctl status
  echo '---'
  tail -50 /home/mtoro6/nurturing-backend/storage/logs/laravel.log
"
```

Expected supervisor output:
```
nurturing-worker:nurturing-worker_00   RUNNING   pid 12345, uptime 2:30:00
nurturing-worker:nurturing-worker_01   RUNNING   pid 12346, uptime 2:30:00
```

### 3. Check for error patterns in logs

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  grep -i 'error\|exception\|failed' /home/mtoro6/nurturing-backend/storage/logs/laravel.log | tail -20
"
```

## Resolution

### Option A: Wait for Automatic Recovery (Preferred)

The Cloud Scheduler job runs every 5 minutes and auto-recovers stuck imports.

1. Check `last_recovery_run` in health endpoint
2. Wait 5-10 minutes
3. Check health endpoint again - `stuck_imports` should be 0

### Option B: Manual Recovery via Artisan

```bash
# SSH to VM
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a

# Run recovery command
cd /home/mtoro6/nurturing-backend
php artisan importaciones:recover --stats
```

Output:
```
Importaciones stuck encontradas: 2
  - Importación #123 re-encolada
  - Importación #124 re-encolada
Recovery completado exitosamente.
```

### Option C: Manual Reset via Tinker

If automatic recovery isn't working:

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a

cd /home/mtoro6/nurturing-backend
php artisan tinker
```

```php
// Reset specific import
$import = Importacion::find(123);
$import->update(['estado' => 'pendiente']);

// Re-dispatch job
dispatch(new \App\Jobs\ProcesarImportacionJob($import));
```

### Option D: Force Complete (Last Resort)

If import is stuck but > 95% complete and data is already in the database:

```php
// In tinker
$import = Importacion::find(123);

// Verify most records were processed
$actualCount = $import->prospectos()->count();
echo "Procesados: {$actualCount} / {$import->registros_totales}";

// If > 95% complete, force complete
if ($actualCount >= $import->registros_totales * 0.95) {
    $import->update([
        'estado' => 'completado',
        'registros_procesados' => $actualCount,
        'registros_exitosos' => $actualCount,
    ]);
    
    // Update lote if exists
    if ($import->lote) {
        $import->lote->update(['estado' => 'completado']);
    }
}
```

## Common Causes

| Cause | Symptom | Fix |
|-------|---------|-----|
| Worker crashed | Supervisor shows FATAL | `sudo supervisorctl restart all` |
| GCS permission error | "Not Found" in logs | Check GCS env vars in .env |
| Memory exhaustion | "Allowed memory size" in logs | Increase PHP memory or reduce batch size |
| Database lock | Import stuck at same progress | Check for blocking queries in PostgreSQL |

## Prevention

1. **All imports go through background queue** - `DIRECT_PROCESSING_THRESHOLD = 0`
2. **Cloud Scheduler auto-recovery** - Every 5 minutes
3. **Health endpoint monitoring** - Alerts when stuck_imports > 0
4. **VM workers via Supervisor** - Auto-restart on crash

## Escalation

If issue persists after recovery attempts:

1. Check GCS bucket permissions: `gsutil ls gs://nurturing-imports-qa/`
2. Check database connectivity from VM: `php artisan tinker --execute="DB::select('SELECT 1')"`
3. Check for PHP memory errors: `grep -i 'memory' storage/logs/laravel.log`
4. Contact platform team if infrastructure issue suspected
