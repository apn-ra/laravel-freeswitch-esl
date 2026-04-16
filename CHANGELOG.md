# Changelog

All notable changes to `apntalk/laravel-freeswitch-esl` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

---

## [Unreleased] — 0.3.0 runtime-prep checkpoint

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

## [Unreleased] — 0.3.x adapter-boundary normalization pass

### Changed

- `src/Contracts/ConnectionFactoryInterface.php` — now returns the Laravel-owned `RuntimeHandoffInterface` boundary instead of the concrete `EslCoreConnectionHandle`
- `src/Integration/EslCoreConnectionFactory.php`, worker call-site tests, and binding/integration docs — now prefer interface-first runtime handoff usage while still documenting `EslCoreConnectionHandle` as the current implementation

### Added

- focused boundary-normalization assertions around interface-first factory usage

## [Unreleased] — 0.3.x runtime-adapter seam pass

### Changed

- `src/Contracts/RuntimeRunnerInterface.php` and `src/Integration/NonLiveRuntimeRunner.php` — added a Laravel-owned runtime runner seam plus a truthful default non-live implementation
- `src/Worker/WorkerRuntime.php`, `src/Worker/WorkerSupervisor.php`, and `src/Console/Commands/FreeSwitchWorkerCommand.php` — now invoke and report the runtime runner seam while keeping `runtime_loop_active = false` in the current checkpoint
- `src/ControlPlane/ValueObjects/WorkerStatus.php` — now exposes runner-invoked state separately from handoff-prepared and runtime-active state, with explicit runner contract vs bound implementation metadata
- `src/Providers/FreeSwitchEslServiceProvider.php` — now binds the default `RuntimeRunnerInterface` implementation in the container
- runtime-prep docs updated to describe prepared vs adapter-ready vs runner-invoked vs runtime-active states explicitly

### Added

- focused provider, worker, integration, and command tests for the new runtime runner seam, plus constructor/doc truth fixes for the new injection path

## [Unreleased] — 0.3.x runtime-prep seam pass

### Changed

- `src/Contracts/RuntimeHandoffInterface.php` — added an explicit Laravel-owned adapter-facing contract for prepared runtime bundles
- `src/Contracts/ConnectionFactoryInterface.php` and `src/Contracts/RuntimeHandoffInterface.php` — added a Laravel-owned adapter-facing handoff contract while keeping the existing concrete factory seam intact for this step
- `src/Integration/EslCoreConnectionHandle.php` — now implements `RuntimeHandoffInterface` and exposes the resolved context through a method for adapter-facing consumption
- `src/Worker/WorkerRuntime.php`, `src/Worker/WorkerSupervisor.php`, and `src/ControlPlane/ValueObjects/WorkerStatus.php` — now distinguish handoff-prepared, adapter-ready, and runtime-active state more explicitly without adding live runtime behavior
- `README.md`, `docs/architecture.md`, `docs/public-api.md`, `docs/worker-runtime.md`, and `docs/package-boundaries.md` — tightened the runtime-prep story for the next later `apntalk/esl-react` integration phase

### Added

- focused unit coverage for `RuntimeHandoffInterface` exposure on the current `EslCoreConnectionHandle`, `WorkerRuntime`, and `WorkerSupervisor` seams

## [Unreleased] — 0.1.x closure pass

## [Unreleased] — 0.2.x release-readiness hardening pass

### Changed

- `composer.json`, `README.md`, and `docs/compatibility-policy.md` — aligned PHP support truth to `8.3+` so published requirements match the resolved `apntalk/esl-core` dependency
- `src/Console/Commands/FreeSwitchHealthCommand.php` — now injects `PbxRegistryInterface` instead of resolving it through global `app()`, keeping the command on the normal binding seam
- `src/Console/Commands/FreeSwitchReplayInspectCommand.php` — removed an unused registry dependency from the command signature
- `README.md`, `docs/architecture.md`, and `docs/replay-integration.md` — tightened replay and health wording so current scaffolding is not described as shipped live wiring

### Added

- `tests/Integration/Console/FreeSwitchPingCommandTest.php` — covers registration and successful resolved-context output for `freeswitch:ping`
- `tests/Integration/Console/FreeSwitchHealthCommandTest.php` — covers registration and rendered health output for `freeswitch:health`
- `tests/Integration/Console/FreeSwitchReplayInspectCommandTest.php` — covers registration and disabled-path behavior for `freeswitch:replay:inspect`

## [Unreleased] — esl-core stable seam alignment pass

### Changed

- `src/Integration/EslCoreConnectionFactory.php` — replaced manual socket construction and internal `StreamSocketTransport` usage with upstream public `TransportFactoryInterface` / `SocketTransportFactory` and `SocketEndpoint`
- `src/Integration/EslCorePipelineFactory.php` — now uses `InboundPipeline::withDefaults()` as the preferred public ingress construction path
- `src/Contracts/ConnectionFactoryInterface.php` — return type now reflects the actual current 0.2.x handoff object (`EslCoreConnectionHandle`) instead of an opaque `mixed` placeholder
- `src/Providers/FreeSwitchEslServiceProvider.php` — now binds upstream `TransportFactoryInterface` and `InboundConnectionFactoryInterface` so future runtime adapters can consume stable public `esl-core` seams through Laravel
- `README.md`, `docs/architecture.md`, `docs/public-api.md`, and `docs/worker-runtime.md` — updated to describe the stable public upstream transport/bootstrap seams now used by this package

### Added

- `tests/Integration/EslCoreBindingsTest.php` — now verifies container resolution for upstream `TransportFactoryInterface` and `InboundConnectionFactoryInterface`, including accepted-stream preparation
- `tests/Unit/Integration/EslCoreConnectionFactoryTest.php` — now verifies `SocketEndpoint` construction, supported transport handling, and use of the public transport factory seam
- `tests/Unit/Integration/EslCorePipelineFactoryTest.php` and `tests/Unit/Integration/EslCoreEventBridgeTest.php` — updated to use the preferred default ingress seam

## [Unreleased] — 0.2.x checkpoint hardening and broader verification pass

### Changed

- `README.md`, `docs/public-api.md`, `docs/package-boundaries.md`, and `docs/compatibility-policy.md` — tightened public truth around the current `0.2.x` checkpoint, runtime handoff flow, and replay/runtime ownership boundaries
- `tests/Integration/Providers/FreeSwitchEslServiceProviderTest.php` — now proves `ConnectionFactoryInterface` resolves to the current `EslCoreConnectionFactory` adapter and remains a singleton binding

## [Unreleased] — repo/plan alignment pass

## [Unreleased] — connection-factory seam pass

## [Unreleased] — worker runtime handoff seam pass

### Changed

- `src/Worker/WorkerRuntime.php` — `boot()` now advances past `ConnectionContext` and creates a retained package-owned connection handle through `ConnectionFactoryInterface`; `run()` now guards on full runtime handoff state and logs the prepared endpoint while remaining a stub
- `src/Worker/WorkerRuntime.php` — `status()` now reports handoff scaffolding truthfully via `WorkerStatus::meta` (`context_resolved`, `connection_handoff_prepared`, `handoff_endpoint`)
- `src/Worker/WorkerSupervisor.php` — now threads `ConnectionFactoryInterface` into each `WorkerRuntime` and exposes per-node runtime snapshots through `runtimeStatuses()`
- `src/Console/Commands/FreeSwitchWorkerCommand.php` — now injects `ConnectionFactoryInterface`, passes it into `WorkerSupervisor`, and reports prepared runtime handoff counts without implying a live `apntalk/esl-react` loop
- `docs/worker-runtime.md`, `docs/architecture.md`, and `docs/public-api.md` — updated to describe retained worker handoff state and the new status/meta surfacing

### Added

- `tests/Unit/Worker/WorkerRuntimeTest.php` — focused coverage for retained `resolvedContext`, retained connection handle, and run-before-boot guard semantics
- `tests/Unit/Worker/WorkerSupervisorTest.php` — focused coverage for per-node runtime status snapshots exposing prepared handoff state
- `tests/Integration/Console/FreeSwitchWorkerCommandTest.php` — command-level coverage for registration, ephemeral `--pbx` startup, and DB-backed `--db` startup through the runtime handoff seam

## [Unreleased] — worker/runtime truth hardening pass

### Changed

- `src/Contracts/WorkerInterface.php` — contract docblocks now describe the current scaffolding posture truthfully: `boot()` prepares handoff state, `run()` may return immediately, and `drain()` records drain intent rather than promising live async drain semantics
- `src/ControlPlane/ValueObjects/WorkerStatus.php` — clarified that `running` currently means handoff prepared and that `reconnecting` / `failed` remain reserved future states
- `src/Worker/WorkerRuntime.php` — `status()->meta` now includes `runtime_loop_active = false` so the current non-live posture is explicit in code
- `config/freeswitch-esl.php` — removed unused `table_prefix` config drift; the key had no implementation anywhere in the package
- `README.md`, `docs/worker-runtime.md`, `docs/architecture.md`, `docs/public-api.md`, and `docs/compatibility-policy.md` — aligned worker/runtime wording with the current non-live scaffolding posture

### Added

- focused assertions in worker contract and unit tests for `meta.runtime_loop_active = false`

### Added

- `src/Integration/EslCoreConnectionFactory.php` — concrete `ConnectionFactoryInterface` implementation for the current `apntalk/esl-core` seam; assembles a connection handle from `ConnectionContext`, opening/closing command sequences, and an inbound pipeline without implementing `esl-react` runtime behavior
- `src/Integration/EslCoreConnectionHandle.php` — package-owned opaque handle carrying resolved context, esl-core pipeline, command sequences, and lazy raw transport opening
- `tests/Unit/Integration/EslCoreConnectionFactoryTest.php` — focused tests for handle creation, subscription-profile usage, lazy transport opening, and endpoint derivation

### Changed

- `src/Providers/FreeSwitchEslServiceProvider.php` — now binds `ConnectionFactoryInterface` cleanly in the Laravel container
- `tests/Integration/EslCoreBindingsTest.php` — now verifies the connection factory resolves from the container and creates a connection handle
- `tests/Integration/Providers/FreeSwitchEslServiceProviderTest.php` — now verifies `ConnectionFactoryInterface` is bound

### Changed

- `README.md` — corrected repository posture to reflect that `0.1.x` control-plane work is complete and partial `0.2.x` `apntalk/esl-core` adapter work is already present
- `src/Console/Commands/FreeSwitchStatusCommand.php` — removed unsupported `--all` option so the CLI surface matches implemented behavior (`allActive()` by default, filtered by `--pbx`, `--cluster`, or `--provider`)
- `docs/architecture.md` — removed stale references to deleted `Contracts/Upstream` esl-core stubs and documented current `src/Integration/*` and `src/Events/*` surfaces
- `docs/public-api.md` — aligned public API documentation with shipped Laravel event classes and `apntalk/esl-core` integration adapters
- `docs/package-boundaries.md` — updated upstream-stub guidance to reflect that only the replay stub remains local; esl-core is now consumed directly
- `docs/event-model.md` — changed status from “planned only” to “partially implemented”; documents current command/pipeline/event-bridge layer and deferred higher-level normalized projections
- `docs/compatibility-policy.md` — clarified current release posture: partial `0.2.x` esl-core integration is already in-tree while `esl-react` and replay remain deferred

### Notes

- No runtime behavior changed in this pass. This is an architectural truthfulness update so public docs match the code already present in the repository.

### Added

- `tests/Integration/Console/FreeSwitchStatusCommandTest.php` — verifies default active-node listing and cluster filtering for `freeswitch:status`

### Changed

- `WorkerRuntime::boot()` — resolved `ConnectionContext` (with worker session identity attached via `withWorkerSession()`) is now **persisted** as `$resolvedContext` rather than discarded; available via `resolvedContext(): ?ConnectionContext` for the future `apntalk/esl-react` integration
- `WorkerRuntime::run()` — added boot-order guard: throws `WorkerException::bootFailed()` if called before `boot()` (i.e. if `resolvedContext` is null); stub log message updated to reference `apntalk/esl-react`
- `WorkerSupervisor` — split into two explicit entry points: `run(WorkerAssignment)` for ephemeral CLI-flag targeting (resolves nodes internally) and `runForNodes(string, string, array)` for DB-backed paths (nodes pre-resolved by caller); private `bootRuntimes()` method extracted; log key renamed from `mode` to `assignment_scope` for clarity
- `FreeSwitchWorkerCommand` — added `--db` flag; `--db` uses `WorkerAssignmentResolver::resolveForWorkerName()` and calls `supervisor->runForNodes()`; ephemeral targeting flags (`--pbx`, `--cluster`, `--tag`, `--provider`, `--all-active`) use `supervisor->run()`; `--db` combined with any ephemeral flag is a command error
- `docs/worker-runtime.md` — documents persisted `resolvedContext`, two supervisor entry points, ephemeral vs DB-backed assignment modes, corrected `esl-react` integration snippet
- `phpunit.xml` — restored Contract and Replay test suites (directories created)

### Added

- `tests/Contract/ProviderDriverContractTest.php` — 9 contract tests for `ProviderDriverInterface`; covers `providerCode()` invariants, `buildConnectionContext()` output shape, credential isolation, and `toLogContext()` safety
- `tests/Contract/WorkerInterfaceContractTest.php` — 9 contract tests for `WorkerInterface`; covers session identity stability, lifecycle state transitions, and boot-order semantics
- `tests/Replay/ReplayIntegrationTest.php` — structural skeleton for replay integration tests; all 5 tests skipped pending `apntalk/esl-replay` integration (planned 0.5.x)
- `examples/laravel-app/README.md` — scaffold placeholder documenting 0.1.x-usable steps (seeding, diagnostics, ephemeral and DB-backed worker start); full example app planned for 0.3.x

---

## [Unreleased] — docs alignment pass

### Changed

- `docs/architecture.md` — added `src/Contracts/` section documenting all public contracts and upstream stubs; corrected resolution-pipeline diagram entrypoint label; scoped runtime identity propagation claim to current state (logs and health snapshots only); added cross-references to new `event-model.md` and `public-api.md`; added note that identity propagation to events and replay metadata is deferred to 0.2.x–0.5.x
- `docs/worker-runtime.md` — checkpointing/replay section now clearly marked as future behavior (planned for 0.5.x); corrected claim that `drain()` flushes replay captures (it sets state only in 0.1.x)
- `docs/replay-integration.md` — store binding example replaced with accurate migration path guidance; removed confusing instruction to bind against the `@internal` upstream stub; documented that the stub will be removed when apntalk/esl-replay is integrated
- `docs/package-boundaries.md` — corrected stub description from "`@internal` and `@deprecated`" to "`@internal`" (stubs carry no `@deprecated` tag in their docblocks)
- `docs/compatibility-policy.md` — 0.1.x release stage description updated to acknowledge worker lifecycle scaffolding (WorkerRuntime/WorkerSupervisor with stub `run()`); 0.4.x description refined to "worker runtime hardening" since scaffolding ships in 0.1.x
- `README.md` — worker bootstrapping bullet corrected from "real worker lifecycle" to "explicit boot/drain/shutdown lifecycle...async runtime loop wired in 0.3.x"

### Added

- `docs/event-model.md` — ownership model for raw ESL events, typed events, and normalized events; documents apntalk/esl-core vs. this package's Laravel bridge responsibilities; placeholder for 0.2.x implementation details
- `docs/public-api.md` — complete list of stable public surfaces (contracts, value objects, service provider, config shape, artisan commands, DB schema); internal surfaces; extension points for custom drivers, secret resolvers, and health reporters

---

### Added

#### Repo foundation
- `composer.json` with PHP 8.3+, Laravel 11/12 support matrix
- PHPUnit 11 test configuration (`phpunit.xml`)
- PHPStan level 8 configuration (`phpstan.neon`)
- Laravel Pint code-style configuration (`.php-cs-fixer.php`)
- GitHub Actions CI workflow (PHP 8.3/8.4 × Laravel 11/12)

#### Core contracts
- `PbxRegistryInterface` — multi-PBX node inventory lookups
- `ProviderDriverRegistryInterface` — provider driver map and resolution
- `ProviderDriverInterface` — contract for PBX provider drivers
- `ConnectionResolverInterface` — full resolution pipeline contract
- `ConnectionFactoryInterface` — runtime factory contract
- `WorkerInterface` — worker lifecycle contract
- `WorkerAssignmentResolverInterface` — assignment scope resolution
- `HealthReporterInterface` — structured health snapshot contract
- `SecretResolverInterface` — credential resolution contract
- Upstream stubs in `Contracts/Upstream/` for `apntalk/esl-core` and `apntalk/esl-replay` interfaces

#### Value objects
- `PbxProvider` — immutable provider family VO
- `PbxNode` — immutable PBX endpoint VO with runtime identity
- `ConnectionProfile` — immutable operational policy VO
- `WorkerAssignment` — immutable worker targeting scope VO (5 modes: node/cluster/tag/provider/all-active)
- `ConnectionContext` — fully resolved connection parameters VO (with safe `toLogContext()`)
- `WorkerStatus` — worker operational state snapshot VO
- `HealthSnapshot` — PBX node health snapshot VO

#### Database
- Migration: `pbx_providers` table
- Migration: `pbx_nodes` table (with cluster/region/tag/health indexing)
- Migration: `pbx_connection_profiles` table
- Migration: `worker_assignments` table

#### Eloquent models
- `PbxProvider` model with `toValueObject()` bridge
- `PbxNode` model with tag/cluster/provider scopes and `toValueObject()` bridge
- `PbxConnectionProfile` model with `toValueObject()` bridge
- `WorkerAssignment` model with `toValueObject()` bridge

#### Control-plane services
- `DatabasePbxRegistry` — DB-backed `PbxRegistryInterface` implementation
- `ProviderDriverRegistry` — container-resolved driver registry
- `SecretResolver` — plaintext/env/custom secret resolution modes
- `ConnectionProfileResolver` — DB + config-defaults profile resolution
- `ConnectionResolver` — full pipeline: registry → profile → secret → driver → context
- `WorkerAssignmentResolver` — all five assignment modes

#### Provider drivers
- `FreeSwitchDriver` — first `ProviderDriverInterface` implementation; builds `ConnectionContext` from `PbxNode + ConnectionProfile`

#### Laravel integration
- `FreeSwitchEslServiceProvider` — registers all bindings, publishes config/migrations, boots drivers, registers commands
- `FreeSwitchEsl` facade (optional)
- `FreeSwitchEslManager` — facade-backing manager aggregating registry/resolver/health

#### Artisan commands
- `freeswitch:ping` — resolve and display connection parameters for a node
- `freeswitch:status` — show PBX node inventory with filtering
- `freeswitch:worker` — start a multi-mode worker (node/cluster/tag/provider/all-active)
- `freeswitch:health` — show structured health snapshots
- `freeswitch:replay:inspect` — inspect replay capture store (requires replay enabled)

#### Worker runtime
- `WorkerRuntime` — single-node worker session with boot/run/drain/shutdown lifecycle and session identity
- `WorkerSupervisor` — multi-node orchestrator with per-node failure isolation

#### Health
- `HealthReporter` — DB-backed health snapshot production and recording

#### Exceptions
- `FreeSwitchEslException` — base exception
- `PbxNotFoundException`
- `ConnectionResolutionException`
- `ProviderDriverException`
- `WorkerException`

#### Tests
- focused test suites cover control-plane services, provider contracts, worker/runtime scaffolding, and Laravel integration surfaces (SQLite in-memory, no live PBX required)
- Unit tests: value objects, secret resolver, worker assignment resolver, FreeSWITCH driver
- Integration tests: service provider bindings, database PBX registry (all lookup modes)

#### Documentation
- `docs/architecture.md`
- `docs/control-plane.md`
- `docs/package-boundaries.md`
- `docs/worker-runtime.md`
- `docs/replay-integration.md`
- `docs/compatibility-policy.md`
- `README.md`

---

## Notes

### WorkerRuntime::run() stub

The `run()` method body is intentionally a stub in this release. Live ESL connections require `apntalk/esl-react` to be wired. The stub logs and returns immediately, allowing the full control-plane layer to be tested and validated before the async runtime is available.

### apntalk/esl-react and apntalk/esl-replay not yet wired

Both packages are listed as `suggest` in `composer.json`. Integration will follow in `0.2.x`-`0.5.x` releases as those packages are published.

### Upstream stubs

`src/Contracts/Upstream/` contains development-phase stub interfaces. These are `@internal` and will be replaced by canonical types from the upstream packages. Do not use them as stable public API surfaces.
