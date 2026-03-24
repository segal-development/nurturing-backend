# Runbook: 504 Gateway Timeout on Cloud Run

## Symptoms

- Users see 504 errors on API requests
- Alert: "Nurturing: 504 Gateway Timeouts" triggered
- Slow response times across all endpoints
- Import uploads timing out

## Quick Diagnosis

### 1. Check Cloud Run logs

```bash
gcloud logging read 'resource.type="cloud_run_revision" AND resource.labels.service_name="nurturing-api-qa" AND severity>=ERROR' \
  --limit=20 \
  --format='table(timestamp,textPayload)'
```

### 2. Check memory utilization

Via GCP Console:
1. Go to **Monitoring > Dashboards > Nurturing Backend Health**
2. Check "Memory Utilization" widget
3. If consistently > 80%, memory pressure is likely the cause

Via gcloud:
```bash
gcloud monitoring time-series list \
  --filter='resource.type="cloud_run_revision" AND resource.labels.service_name="nurturing-api-qa" AND metric.type="run.googleapis.com/container/memory/utilizations"' \
  --start-time=$(date -u -d '1 hour ago' +%Y-%m-%dT%H:%M:%SZ) \
  --end-time=$(date -u +%Y-%m-%dT%H:%M:%SZ)
```

### 3. Check if imports are being processed inline

```bash
# Check the threshold value
grep -n "DIRECT_PROCESSING_THRESHOLD" app/Http/Controllers/ImportacionController.php
```

Should be:
```php
private const DIRECT_PROCESSING_THRESHOLD_BYTES = 0;
```

If it's > 0, inline processing may be causing timeouts.

## Resolution

### Immediate: Increase Memory

```bash
gcloud run services update nurturing-api-qa \
  --region us-central1 \
  --memory 2Gi
```

Verify:
```bash
gcloud run services describe nurturing-api-qa \
  --region us-central1 \
  --format='value(spec.template.spec.containers[0].resources.limits.memory)'
```

### If Caused by Inline Import Processing

1. Verify threshold is 0:
```php
// In ImportacionController.php
private const DIRECT_PROCESSING_THRESHOLD_BYTES = 0;
```

2. If not 0, update and deploy:
```bash
git pull origin staging
# Edit file
git add -A && git commit -m "fix: set import threshold to 0"
git push origin staging
```

### If Caused by Slow Database Queries

1. Check Cloud SQL CPU in GCP Console
2. Enable slow query log:
```sql
-- In Cloud SQL
ALTER DATABASE nurturing SET log_min_duration_statement = 1000;
```

3. Check for N+1 queries in logs
4. Add indexes if needed

### If Caused by Traffic Spike

```bash
# Increase max instances temporarily
gcloud run services update nurturing-api-qa \
  --region us-central1 \
  --max-instances 20

# Also consider increasing concurrency
gcloud run services update nurturing-api-qa \
  --region us-central1 \
  --concurrency 100
```

## Rollback

If memory increase causes issues (cost, instability):

```bash
gcloud run services update nurturing-api-qa \
  --region us-central1 \
  --memory 1Gi
```

## Common Causes

| Cause | Symptom | Fix |
|-------|---------|-----|
| Memory exhaustion | OOMKilled in logs | Increase memory to 2Gi |
| Inline import processing | 504 on file upload | Set threshold to 0 |
| Slow DB queries | High latency on list endpoints | Add indexes, optimize queries |
| Cold starts | First request slow | Increase min-instances to 1 |
| Large response payloads | 504 on specific endpoints | Add pagination, reduce payload |

## Prevention

1. **Memory**: Set to 1GB minimum, 2GB for safety margin
2. **Processing**: All file processing goes through background queue
3. **Monitoring**: Dashboard and alerts for memory > 80%
4. **Database**: Regular index maintenance, query optimization

## Metrics to Monitor

| Metric | Threshold | Action |
|--------|-----------|--------|
| Memory utilization | > 80% for 10 min | Increase memory |
| Request latency p95 | > 10s for 5 min | Investigate slow endpoints |
| Error rate | > 1% | Check logs for patterns |
| Instance count | At max | Increase max-instances |

## Related

- [Architecture Overview](../architecture.md)
- [Runbook: Stuck Imports](stuck-imports.md)
