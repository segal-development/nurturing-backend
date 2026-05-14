# Delta for Flujo Execution Tracking

## ADDED Requirements

### Requirement: Large Volume Stage Processing MUST Initialize First Send Timestamp

The system MUST set `primer_envio_at` on an `EtapaEjecucion` before dispatching chunk jobs when processing volumes > 5000 prospects.

#### Scenario: New stage processing large volume for the first time

- GIVEN an `EtapaEjecucion` with `primer_envio_at = NULL`
- WHEN `handleLargeVolume()` processes > 5000 prospects
- THEN `primer_envio_at` MUST be set to current timestamp before dispatching chunks

#### Scenario: Stage already processed before

- GIVEN an `EtapaEjecucion` with `primer_envio_at != NULL`
- WHEN `handleLargeVolume()` processes additional prospects
- THEN `primer_envio_at` MUST NOT be overwritten

---

### Requirement: CatchUp Job MUST Ensure Consistent State Before Dispatch

The system MUST ensure `EtapaEjecucion` is in `executing` state before dispatching `EnviarEtapaJob`.

#### Scenario: Stage in pending state

- GIVEN an `EtapaEjecucion` with `estado = 'pending'`
- WHEN `CatchUpProspectosJob::scheduleStageForProspects()` dispatches
- THEN `estado` MUST be updated to `executing` before dispatch

#### Scenario: Perpetual stage in completed state

- GIVEN an `EtapaEjecucion` with `estado = 'completed'` AND `flujo.es_perpetuo = true`
- WHEN `CatchUpProspectosJob` processes new prospects
- THEN `estado` MUST remain `completed` AND dispatch with `force = true`

---

### Requirement: Progress Calculation MUST Use Conditional Logic By Flow Type

The system MUST calculate completed stages differently based on `es_perpetuo` flag.

#### Scenario: Non-perpetual flow progress calculation

- GIVEN a `FlujoEjecucion` with `es_perpetuo = false`
- WHEN calculating `progreso.completadas`
- THEN count stages WHERE `estado = 'completed'`

#### Scenario: Perpetual flow progress calculation

- GIVEN a `FlujoEjecucion` with `es_perpetuo = true`
- WHEN calculating `progreso.completadas`
- THEN count stages WHERE `primer_envio_at IS NOT NULL`

#### Scenario: Perpetual flow with 3 stages that have processed sends

- GIVEN a perpetual `FlujoEjecucion` with 5 total stages
- AND 3 stages have `primer_envio_at` set
- WHEN API returns progress
- THEN `progreso.completadas = 3`

---

### Requirement: Backfill Migration MUST Populate Historical primer_envio_at

The system MUST provide a migration that populates `primer_envio_at` for existing stages with sends.

#### Scenario: Stage has sends but no primer_envio_at

- GIVEN an `EtapaEjecucion` with associated `envios` records
- AND `primer_envio_at = NULL`
- WHEN backfill migration runs
- THEN `primer_envio_at` MUST be set to `MIN(envios.created_at)`

#### Scenario: Stage has no sends

- GIVEN an `EtapaEjecucion` with no `envios` records
- WHEN backfill migration runs
- THEN `primer_envio_at` MUST remain `NULL`

#### Scenario: Migration completion verification

- GIVEN migration has completed
- WHEN counting stages with sends vs stages with `primer_envio_at`
- THEN counts MUST be equal

## REMOVED Requirements

(None)
