# Health Model

## Scope

`apntalk/laravel-freeswitch-esl` exposes a bounded health model for Laravel operator
surfaces. It does not own upstream ESL runtime supervision.

The health model in this package is built from two sources:

1. DB-backed node health fields in `pbx_nodes`
2. additive runtime-linked facts projected from upstream `apntalk/esl-react`
   status snapshots when a real worker run records them through `HealthReporter`

It is intentionally conservative.

## Sources of truth

### DB-backed snapshot state

The baseline persisted health source is the `pbx_nodes` table:

- `health_status`
- `last_heartbeat_at`
- `settings_json`

These fields support:

- `freeswitch:health`
- `freeswitch:health --summary`
- optional HTTP health routes

### Runtime-linked additive facts

When `freeswitch:worker` runs a real worker scope and upstream runtime status
truth is available, Laravel projects a bounded `HealthSnapshot` from
`WorkerStatus` and records additive facts into the existing DB-backed health
path.

Persisted runtime-linked fields are limited to latest-known facts such as:

- runtime phase
- runtime active / recovery posture
- last successful connect time
- last disconnect time and reason
- last failure time and summary
- linkage basis/source

This package does not create a full live-status history store.

## Surfaces

### CLI

`freeswitch:health`
- human-readable DB-backed health table
- additive runtime-linked fact lines when present
- additive runtime-linked age/staleness hint derived from the stored snapshot time

`freeswitch:health --summary --json`
- bounded aggregate DB-backed summary
- conservative `readiness_posture`
- conservative `liveness_posture`
- `live_runtime_linked` flag indicating whether any supplied snapshots carry persisted runtime-linked facts

### HTTP

Optional HTTP routes expose the same bounded DB-backed model:

- `GET /freeswitch-esl/health`
- `GET /freeswitch-esl/health/live`
- `GET /freeswitch-esl/health/ready`

These routes return JSON only.

They do not claim:

- live socket ownership
- reconnect completion
- session continuity restoration
- process/event-loop liveness beyond the latest persisted snapshot facts

## Posture semantics

`healthy`
- DB-backed health is healthy, or a runtime-linked snapshot reports an active authenticated posture

`degraded`
- stale heartbeat
- runtime recovery in progress
- reconnecting/connecting/authenticating/disconnected/closed runtime-linked posture
- partially prepared but not live worker/runtime posture

`unhealthy`
- explicitly unhealthy DB-backed state
- runtime-linked failed phase

`unknown`
- insufficient persisted evidence

## Boundary notes

This package owns:

- health snapshot persistence
- Laravel-facing health contracts
- health CLI and HTTP operator surfaces
- bounded projection of upstream runtime status truth into persisted health facts

This package does not own:

- reconnect/backoff mechanics
- heartbeat/session lifecycle mechanics
- raw protocol/parser health semantics
- replay execution or incident-history replay
