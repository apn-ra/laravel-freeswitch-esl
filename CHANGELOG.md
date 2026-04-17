# Changelog

All notable changes to `apntalk/laravel-freeswitch-esl` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
