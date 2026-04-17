# Changelog

All notable changes to `apntalk/laravel-freeswitch-esl` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

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
