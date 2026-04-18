# Changelog

All notable changes to `apntalk/laravel-freeswitch-esl` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Changed
- aligned the default `apntalk/esl-react` integration to the runtime-owned `RuntimeRunnerHandle::statusSnapshot()` seam on `v0.2.9`
- enriched worker status/reporting surfaces with runtime-owned phase, active/recovery posture, connect/disconnect observation, and failure summary metadata without adding reconnect or resume execution
- made aggregate health JSON summaries report whether provided snapshots are actually live-runtime-linked instead of hard-coding that posture
- made real `freeswitch:worker` runs persist selected upstream runtime-status facts into DB-backed health snapshots so later `freeswitch:health` reads can surface the latest linked phase, connect/disconnect, and failure posture conservatively
- made human-readable `freeswitch:health` render a small runtime-linked facts section when the stored DB-backed health snapshot contains selected upstream runtime-status facts
- added a bounded human-readable runtime-linked snapshot age/staleness hint to `freeswitch:health`, derived from the stored snapshot timestamp rather than inferred live-runtime state

### Added

- integrated `apntalk/esl-replay` `v0.9.1` as a real runtime dependency and replaced the local replay-store stub with the upstream `ReplayArtifactStoreInterface`
- added Laravel replay store wiring, an esl-core replay sink adapter, and artifact-envelope adaptation so `apntalk/esl-react` replay hooks can be durably persisted when replay is enabled
- made `freeswitch:replay:inspect` read real upstream stored replay records and filter them by PBX node/runtime metadata
- enabled replay integration coverage for store binding, metadata propagation, bounded reads, and replay inspection behavior
- added bounded replay-backed checkpoint coordination for worker runtime using the upstream checkpoint store
- made worker drain more real by recording drain start/deadline/completion state and snapshotting conservative replay-backed checkpoints
- aligned worker checkpoints to the upstream `ReplayCheckpointRepository`, `ReplayCheckpointReference`, `ReplayCheckpointCriteria`, and expanded `ReplayReadCriteria`
- added bounded checkpoint-backed worker recovery hints keyed by persisted `replay_session_id`, `worker_session_id`, `pbx_node_slug`, and `job_uuid`
- surfaced bounded replay-backed checkpoint/recovery posture in `freeswitch:worker` output and added explicit non-live-recovery wording to the narrower `freeswitch:health` and `freeswitch:status` operator surfaces
- added `freeswitch:worker --json` as a stable machine-readable reporting surface for bounded replay-backed checkpoint/recovery posture and drain state
- added `freeswitch:worker:status` as a dedicated machine-readable reporting command that reuses `WorkerStatus` metadata, prepares runtimes without invoking the runtime runner, and can report multiple DB-backed worker scopes in one call
- added `freeswitch:worker:checkpoint-status` as a dedicated machine-readable historical checkpoint posture summary command with bounded optional history entries driven by upstream replay checkpoint queries
- added additive DB-backed filters plus stable `limit`/`offset` pagination to `freeswitch:worker:checkpoint-status` for larger worker sets without changing historical posture field semantics
- added bounded historical pruning-posture reporting to `freeswitch:worker:checkpoint-status`, including additive oldest/newest window timestamps and conservative candidate counts when the upstream filesystem retention planner can derive them safely
- added additive top-level retention-policy metadata to `freeswitch:worker:checkpoint-status`, including configured store driver, retention days, storage-path presence, support basis, and reporting window hours
- added additive retention-support basis metadata to `freeswitch:worker:checkpoint-status`, including the active upstream support path when present and the current upstream support source
- made `worker_defaults.checkpoint_interval_seconds` real by adding bounded runtime-triggered `periodic` checkpoint saves after runner invocation while preserving existing drain-requested, drain-completed, drain-timeout, and shutdown checkpoint semantics
- added additive machine-readable resume-posture fields to `freeswitch:worker --json` and `freeswitch:worker:status`, derived from the existing replay-backed checkpoint/recovery facts while keeping `resume_execution_supported = false`
- added explicit `schemaVersion = "1.0"` to the shipped Laravel bridge events (`EslEventReceived`, `EslReplyReceived`, `EslDisconnected`) so wrapper-schema changes can be tracked separately from package SemVer
- added `freeswitch:health --summary` as a bounded aggregate DB-backed health surface with conservative readiness/liveness posture and unchanged default `freeswitch:health` output semantics

## [0.4.6] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side dependency-alignment checkpoint.
It aligns the Laravel package to the released `apntalk/esl-react` `^0.2.7` runtime/lifecycle line.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.7` so Laravel consumes the latest released runner/lifecycle line on the same public observation seam
- tightened docs to keep Laravel’s release-facing truth aligned with the latest upstream validation/stability posture while reconnect, heartbeat, session lifecycle, bgapi/event runtime behavior, and supervision remain upstream-owned

### Verification

- focused downstream verification should cover the runner adapter, runtime feedback translation, worker runtime, worker command output, provider/binding paths, static analysis, and Composer metadata validation for the dependency bump

### Deferred

- Laravel-owned reconnect, heartbeat, session lifecycle, bgapi/event runtime semantics, and runtime supervision remain out of scope
- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred
- live runtime validation from this Laravel repository was not run in this checkpoint

## [0.4.5] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side dependency-alignment checkpoint.
It aligns the Laravel package to the released `apntalk/esl-react` `^0.2.6` runtime/lifecycle line.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.6` so Laravel consumes the released runner/lifecycle line with stronger live combined-condition reconnect plus bgapi/event validation on the public runner seam
- tightened docs to clarify that Laravel keeps consuming the existing upstream lifecycle/push observation surfaces while live-vs-deterministic validation posture and bgapi/event runtime behavior remain documented and owned upstream in `apntalk/esl-react`

### Verification

- focused downstream verification should cover the runner adapter, runtime feedback translation, worker runtime, worker command output, provider/binding paths, static analysis, and Composer metadata validation for the dependency bump

### Deferred

- Laravel-owned reconnect, heartbeat, session lifecycle, bgapi/event runtime semantics, and runtime supervision remain out of scope
- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred
- live runtime validation from this Laravel repository was not run in this checkpoint

## [0.4.4] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side dependency-alignment checkpoint.
It aligns the Laravel package to the released `apntalk/esl-react` `^0.2.5` runtime/lifecycle line.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.5` so Laravel consumes the released runner/lifecycle line that adds deterministic combined-condition validation for bgapi/event activity intersecting with degraded liveness and reconnecting states on the public runner seam
- tightened runtime observation docs to clarify that Laravel reports the existing upstream lifecycle truth while combined bgapi/event activity, degraded liveness, reconnecting behavior, heartbeat/session lifecycle, and runtime supervision remain owned and validated by `apntalk/esl-react`

### Verification

- focused downstream verification should cover the runner adapter, runtime feedback translation, worker runtime, worker command output, provider/binding paths, static analysis, and Composer metadata validation for the dependency bump

### Deferred

- Laravel-owned reconnect, heartbeat, session lifecycle, bgapi/event runtime semantics, and runtime supervision remain out of scope
- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred
- live runtime validation from this Laravel repository was not run in this checkpoint

## [0.4.3] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side dependency-alignment checkpoint.
It aligns the Laravel package to the released `apntalk/esl-react` `^0.2.4` runtime/lifecycle line.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.4` so Laravel consumes the released runner/lifecycle line that includes live-verified reconnect recovery, heartbeat liveness degradation, and second-miss dead/reconnect observation on the public runner seam
- tightened runtime observation docs to clarify that Laravel reports those upstream lifecycle signals through its existing worker/operator surfaces without owning reconnect, heartbeat, or session lifecycle behavior

### Verification

- focused PHPUnit coverage passed for the runner adapter, runtime feedback translation, worker runtime, worker command output, and provider/binding paths
- PHPStan passed at the current configured level
- Composer metadata validation passed after the dependency floor change

### Deferred

- Laravel-owned reconnect, heartbeat, session lifecycle, and runtime supervision remain out of scope
- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred
- live runtime validation from this Laravel repository was not run in this checkpoint

## [0.4.2] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side runtime observation checkpoint.
It extends the current `apntalk/esl-react` runner binding with push-based lifecycle observation through the upstream runner handle.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.2` so Laravel can rely on upstream push-based lifecycle observation through `RuntimeRunnerHandle::onLifecycleChange()`
- extended `EslReactRuntimeRunnerAdapter` to subscribe to upstream lifecycle change callbacks and cache translated feedback for worker/operator status surfaces
- added additive worker status metadata for feedback delivery mode and push-observed lifecycle updates, and updated `freeswitch:worker` output to report push-based lifecycle observation

### Verification

- focused PHPUnit coverage passed for the runner adapter, worker runtime, runtime feedback translation, and worker command output
- PHPStan passed at the current configured level
- Composer metadata validation passed after the dependency floor change

### Deferred

- reconnect, heartbeat, and session lifecycle ownership remain in `apntalk/esl-react`
- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred to a future `apntalk/esl-react` public seam

## [0.4.1] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side runtime observation checkpoint.
It extends the existing `apntalk/esl-react` runner binding with richer prepared dial-target handoff support.
It is not a Laravel-owned live runtime milestone.

### Changed

- bumped the minimum supported `apntalk/esl-react` line to `^0.2.1` so Laravel can rely on richer prepared dial-target input support in the installed upstream package
- extended `EslReactRuntimeBootstrapInputFactory` to pass explicit dial URIs for non-default resolved transports, including `tls://host:port`, while preserving the default TCP path

### Verification

- focused PHPUnit coverage passed for the richer dial-target mapping and existing runner binding paths
- PHPStan passed at the current configured level
- Composer metadata validation passed after the dependency floor change

### Deferred

- direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred to a future `apntalk/esl-react` public seam
- per-node TLS connector policy and live TLS verification remain outside this package's current responsibility; Laravel only maps the dial target into the prepared runner input

## [0.4.0] - 2026-04-17

### Summary

This release is a truthful `0.4.x` Laravel-side lifecycle observation checkpoint.
It is not a Laravel-owned live runtime milestone.

### Highlights

- bumped the supported `apntalk/esl-react` line to `^0.2`
- kept the real `apntalk/esl-react` runner binding as the default runtime runner path
- switched Laravel lifecycle observation from compatibility-only fallback logic to real end-to-end `RuntimeRunnerHandle::lifecycleSnapshot()` consumption on the supported upstream line
- hardened worker, supervisor, and `freeswitch:worker` status surfaces around real upstream connection/session/liveness/reconnect/drain truth
- kept reconnect, heartbeat, session lifecycle, and runtime supervision ownership in `apntalk/esl-react`

### Verification

- focused unit and integration PHPUnit coverage passed for the dependency bump and lifecycle snapshot path
- full local PHPUnit suite passed for the checkpoint work
- PHPStan passed at the current configured level

### Deferred

- Laravel-owned runtime supervision
- Laravel-owned reconnect/backoff behavior
- Laravel-owned heartbeat/session lifecycle ownership
- TLS prepared handoff and direct `apntalk/esl-core` transport polling through this path
- `apntalk/esl-replay` runtime orchestration

### Added

- Added the first Laravel-to-`apntalk/esl-react` runner binding:
  - `EslReactRuntimeBootstrapInputFactory` maps `RuntimeHandoffInterface` into `PreparedRuntimeBootstrapInput`
  - `EslReactRuntimeRunnerAdapter` invokes the upstream `apntalk/esl-react` runner behind Laravel's `RuntimeRunnerInterface`
  - `freeswitch-esl.runtime.runner` now defaults to `esl-react`, with `non-live` retained as a fallback/dry-run option
- Added `RuntimeRunnerFeedbackProviderInterface` and `RuntimeRunnerFeedback` so Laravel worker status can consume runner handle state, including upstream lifecycle snapshots, without owning runtime lifecycle.
- Added focused unit and provider tests for the prepared bootstrap input mapping, runner adapter, and container bindings.

### Changed

- Promoted `apntalk/esl-react` from a suggested package to a runtime dependency.
- Bumped the supported `apntalk/esl-react` line to `^0.2`, which ships `RuntimeRunnerHandle::lifecycleSnapshot()` as a stable upstream lifecycle observation seam.
- Updated worker/runtime docs and command output to distinguish runner invocation from Laravel-observed live runtime state.
- `EslReactRuntimeRunnerAdapter` now consumes `RuntimeRunnerHandle::lifecycleSnapshot()` directly on the supported upstream line, and `WorkerStatus::meta` includes the resulting feedback fields end to end: feedback source, runner state, endpoint, session id, startup/runtime errors, connection/session state, liveness, reconnecting, draining, stopped, reconnect attempts, heartbeat timestamp, and feedback-derived `runtime_loop_active`.

### Deferred

- TLS handoff and direct `apntalk/esl-core` `TransportInterface` polling handoff remain deferred until `apntalk/esl-react` exposes those public paths.
- Reconnect, heartbeat, and runtime session lifecycle ownership remain in `apntalk/esl-react`.

---

## [0.3.0] — 2026-04-17 runtime-prep checkpoint

### Summary

The repository is being advanced toward a truthful `0.3.0` runtime-preparation milestone.
This is not a live runtime release.

### Highlights

- normalizes Laravel-side runtime preparation around `RuntimeHandoffInterface` and `RuntimeRunnerInterface`
- keeps `WorkerRuntime`, `WorkerSupervisor`, and `freeswitch:worker` truthful about prepared, adapter-ready, and non-live runner-invoked state
- keeps the default runtime runner explicitly non-live while leaving real runtime ownership to a later `apntalk/esl-react` phase

### Deferred

- live async runtime loop ownership
- reconnect/backoff supervision
- heartbeat/session lifecycle ownership
- listener/runtime ownership
- replay runtime orchestration

---

## [0.2.0] - 2026-04-17

### Summary

This release is a truthful partial `0.2.x` Laravel-side integration checkpoint.
It is not a live runtime milestone.

### Highlights

- aligned Laravel-side integration to stable public `apntalk/esl-core` seams, including `TransportFactoryInterface`, `SocketTransportFactory`, `SocketEndpoint`, `InboundPipeline::withDefaults()`, and `InboundConnectionFactoryInterface`
- added a concrete `ConnectionFactoryInterface` seam and package-owned `EslCoreConnectionHandle` for runtime handoff scaffolding
- advanced `WorkerRuntime`, `WorkerSupervisor`, and `freeswitch:worker` to retain and surface handoff-prepared state truthfully without claiming a live async runtime
- hardened public docs, command surfaces, provider/container proof, and static-analysis posture for the current non-live checkpoint

### Verification

- contract, unit, and integration PHPUnit suites passed for the checkpoint work
- PHPStan passed at the current configured level

### Deferred

- `apntalk/esl-react` live async runtime wiring
- reconnect/backoff supervision
- heartbeat/session lifecycle ownership
- listener/runtime ownership
- `apntalk/esl-replay` runtime orchestration
