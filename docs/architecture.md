# Nurturing Backend Architecture

## System Overview

```mermaid
flowchart TB
    subgraph "Users"
        FE[Frontend App]
    end

    subgraph "Google Cloud Platform"
        subgraph "Cloud Run"
            API[nurturing-api-qa/prod<br/>Laravel API<br/>1GB Memory]
        end

        subgraph "Cloud Storage"
            GCS[(nurturing-imports-qa<br/>Import Files)]
        end

        subgraph "Cloud SQL"
            DB[(PostgreSQL<br/>nurturing-db)]
        end

        subgraph "VM Worker"
            VM[nurturing-worker<br/>Queue Workers<br/>Supervisor]
        end

        subgraph "Cloud Scheduler"
            CRON[Import Recovery<br/>Every 5 min]
        end

        subgraph "Cloud Monitoring"
            ALERTS[Alert Policies]
            DASH[Dashboard]
        end
    end

    subgraph "External Services"
        SMS[SMS Provider]
        EMAIL[AthenaCampaign]
    end

    FE -->|HTTPS| API
    API -->|Upload| GCS
    API -->|Queue Jobs| DB
    API -->|CRUD| DB
    VM -->|Download| GCS
    VM -->|Process Jobs| DB
    VM -->|Send| SMS
    VM -->|Send| EMAIL
    CRON -->|POST /recovery| API
    API -.->|Metrics| DASH
    DASH -.->|Trigger| ALERTS
```

## Deployment Targets

| Component | Platform | Memory | Scaling |
|-----------|----------|--------|---------|
| API | Cloud Run | 1GB | 0-10 instances |
| Workers | VM (e2-medium) | 4GB | 2 workers via Supervisor |
| Database | Cloud SQL | - | PostgreSQL 15 |
| Storage | Cloud Storage | - | nurturing-imports-{env} |

## Request Flow: Import Processing

```mermaid
sequenceDiagram
    participant U as User
    participant API as Cloud Run API
    participant GCS as Cloud Storage
    participant DB as PostgreSQL
    participant VM as VM Worker

    U->>API: POST /api/importaciones (file)
    API->>GCS: Upload file
    API->>DB: Create importacion (estado=pendiente)
    API->>DB: Dispatch ProcesarImportacionJob
    API-->>U: 202 Accepted {id, estado: pendiente}

    loop Every 5 seconds (queue:work)
        VM->>DB: Poll for jobs
        VM->>GCS: Download file
        VM->>DB: Update estado=procesando
        VM->>DB: Insert prospectos (batch)
        VM->>DB: Update estado=completado
    end

    U->>API: GET /api/importaciones/{id}/progreso
    API->>DB: Query progress
    API-->>U: {procesados: 1000, total: 5000}
```

## Recovery Flow

```mermaid
sequenceDiagram
    participant CS as Cloud Scheduler
    participant API as Cloud Run API
    participant DB as PostgreSQL

    CS->>API: POST /api/cron/import-recovery
    API->>DB: Find stuck imports (procesando > 10 min)
    API->>DB: Reset estado to pendiente
    API->>DB: Re-dispatch jobs
    API-->>CS: 200 OK {recovered: 2}
```

## Key Design Decisions

### Why Hybrid Cloud Run + VM?

1. **Cloud Run for API**: Scales to zero, pay-per-request, handles traffic spikes
2. **VM for Workers**: Long-running processes, no cold starts, consistent memory

### Why Background Processing for ALL Imports?

- `DIRECT_PROCESSING_THRESHOLD_BYTES = 0` forces all imports to queue
- Prevents Cloud Run 504 timeouts (60s limit)
- Allows progress tracking and recovery

### Why Database Queue (not Redis)?

- Simpler infrastructure (no Redis to manage)
- Transactional safety with PostgreSQL
- Good enough for current scale (~100 jobs/day)

## Environment Variables

See `CLAUDE.md` for the complete list of required environment variables per target.

## Monitoring Endpoints

| Endpoint | Purpose |
|----------|---------|
| `GET /api/importaciones/health` | Import system health check |
| `POST /api/cron/import-recovery` | Trigger stuck import recovery |

## Related Documentation

- [Runbook: Stuck Imports](runbooks/stuck-imports.md)
- [Runbook: 504 Timeouts](runbooks/504-timeouts.md)
- [Runbook: VM Workers](runbooks/vm-workers.md)
