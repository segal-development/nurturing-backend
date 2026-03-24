# Runbook: VM Worker Not Processing Jobs

## Symptoms

- Jobs accumulating in database queue
- Imports stuck in "pendiente" state (not progressing)
- Alert: "Nurturing: Queue Backlog" triggered
- Health endpoint shows high `queued_jobs` count

## Quick Check

```bash
curl -H "Authorization: Bearer $TOKEN" \
  https://api-nurturing-qa.segal.cl/api/importaciones/health
```

If `queued_jobs` is high (> 50) and not decreasing, workers may be down.

## Diagnosis

### 1. Check Supervisor Status

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  sudo supervisorctl status
"
```

**Expected (healthy):**
```
nurturing-worker:nurturing-worker_00   RUNNING   pid 12345, uptime 2:30:00
nurturing-worker:nurturing-worker_01   RUNNING   pid 12346, uptime 2:30:00
```

**Problem states:**
- `STOPPED` - Worker was manually stopped
- `FATAL` - Worker crashed and couldn't restart
- `STARTING` - Worker is stuck starting (check logs)

### 2. Check Worker Logs

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  tail -100 /home/mtoro6/nurturing-backend/storage/logs/laravel.log
"
```

Look for:
- `ERROR` or `Exception` messages
- `Allowed memory size exhausted`
- `Connection refused` (database issues)
- `Not Found` (GCS permission issues)

### 3. Check Environment Variables

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  cd /home/mtoro6/nurturing-backend
  php artisan envio:verify-config
"
```

### 4. Check Queue Table

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  cd /home/mtoro6/nurturing-backend
  php artisan tinker --execute=\"DB::table('jobs')->count()\"
"
```

## Resolution

### Restart Workers

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  sudo supervisorctl restart all
  sleep 3
  sudo supervisorctl status
"
```

### If Workers Keep Crashing (FATAL)

1. Check the supervisor logs:
```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  tail -50 /var/log/supervisor/supervisord.log
  tail -50 /home/mtoro6/nurturing-backend/storage/logs/worker*.log
"
```

2. Check PHP memory limit:
```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  php -i | grep memory_limit
"
```

3. Increase if needed in `/etc/php/8.2/cli/php.ini`

### If Missing Environment Variables

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a

cd /home/mtoro6/nurturing-backend

# Check current .env
grep -E 'GOOGLE_CLOUD|FILESYSTEM_DISK|QUEUE_CONNECTION' .env

# Add missing variables
nano .env
```

Required variables:
```env
GOOGLE_CLOUD_PROJECT_ID=grupo-segal
GOOGLE_CLOUD_STORAGE_BUCKET=nurturing-imports-qa
FILESYSTEM_DISK=gcs
QUEUE_CONNECTION=database
```

Then:
```bash
php artisan config:cache
sudo supervisorctl restart all
```

### If Disk Full

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  df -h
"
```

If < 10% free:
```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  # Clean old logs
  find /home/mtoro6/nurturing-backend/storage/logs -name '*.log' -mtime +7 -delete
  
  # Clean old backups
  find /home/mtoro6 -name '*.backup.*' -mtime +30 -delete
  
  # Check disk again
  df -h
"
```

### If VM is Unresponsive

```bash
# Check VM status
gcloud compute instances describe nurturing-worker --zone us-central1-a --format='value(status)'

# If RUNNING but unresponsive, reset
gcloud compute instances reset nurturing-worker --zone us-central1-a

# Wait 2-3 minutes for VM to boot, then verify
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  sudo supervisorctl status
"
```

### If Database Connection Issues

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  cd /home/mtoro6/nurturing-backend
  php artisan tinker --execute=\"DB::select('SELECT 1')\"
"
```

If connection fails:
1. Check `DB_HOST` in .env matches Cloud SQL private IP
2. Check VPC firewall rules allow VM to reach Cloud SQL
3. Check Cloud SQL is running in GCP Console

## Clear Failed Jobs (If Needed)

If there are many failed jobs blocking the queue:

```bash
gcloud compute ssh mtoro6@nurturing-worker --zone us-central1-a --command "
  cd /home/mtoro6/nurturing-backend
  
  # Check failed jobs count
  php artisan tinker --execute=\"DB::table('failed_jobs')->count()\"
  
  # Retry all failed jobs
  php artisan queue:retry all
  
  # Or flush failed jobs (data loss!)
  # php artisan queue:flush
"
```

## Prevention

1. **Deploy script validates env vars** before restarting workers
2. **Cloud Scheduler recovery** catches up if workers were temporarily down
3. **Monitoring alerts** on queue depth > 1000
4. **Supervisor auto-restart** on worker crash

## Supervisor Configuration

Located at `/etc/supervisor/conf.d/nurturing-worker.conf`:

```ini
[program:nurturing-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /home/mtoro6/nurturing-backend/artisan queue:work database --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=mtoro6
numprocs=2
redirect_stderr=true
stdout_logfile=/home/mtoro6/nurturing-backend/storage/logs/worker.log
stopwaitsecs=3600
```

## Related

- [Architecture Overview](../architecture.md)
- [Runbook: Stuck Imports](stuck-imports.md)
