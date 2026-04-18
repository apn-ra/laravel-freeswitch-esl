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

Worker-derived health snapshots created from `WorkerStatus` now also carry
bounded flow-control fields in `meta`, including:

- `max_inflight`
- `backpressure_active`
- `backpressure_limit_reached`
- `backpressure_reason`
- `backpressure_rejected_total`

Those fields help operator tooling explain why a worker is refusing new work.
They do not imply a durable queue, global scheduler, or dead-letter system.

## Surfaces

## Metrics emission

Health recording is now load-bearing for observability by default. When
`HealthReporter::record()` persists a snapshot, the package also emits:

- `freeswitch_esl.health.snapshot_recorded` as a counter
- `freeswitch_esl.health.inflight_count` as a gauge

Those metrics flow through the configured `MetricsRecorderInterface` driver:

- `log` by default for structured log output
- `event` for Laravel event dispatch through `MetricsRecorded`
- `null` for explicit silence

This package still does not ship a Prometheus or OpenTelemetry exporter. Those
remain application-level integrations via the same interface.

The human-readable `freeswitch:health` output now also surfaces the configured
metrics driver directly so operators do not need to infer observability posture
from config files alone.

When a stored snapshot carries bounded backpressure metadata, the command
renders a concise operator-action hint such as:
- let drain complete before adding work
- reduce inflight load or raise `max_inflight` deliberately

These hints are bounded interpretations of stored Laravel-owned snapshot facts.
They do not imply live queue ownership, reconnect ownership, or scheduler
ownership.

### CLI

`freeswitch:health`
- human-readable DB-backed health table
- configured metrics-driver posture line
- bounded human-readable backpressure snapshot facts when present
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
