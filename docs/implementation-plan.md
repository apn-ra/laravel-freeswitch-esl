# Revised Implementation Plan for `laravel-freeswitch-esl`

## Product goal

Build a production-grade Laravel package that supports:

- multiple PBX nodes
- database-backed PBX inventory
- provider-driver abstraction
- long-lived ESL workers
- typed event ingestion
- normalization
- replay-safe capture hooks
- health and observability
- future expansion beyond FreeSWITCH without rewriting the package core

The package should not be a single `.env` connection plus a facade.

It should be:

**Laravel app → PBX registry → provider driver → resolved runtime connection → worker/event pipeline**

And it should be built on APNTalk’s own ESL package family:

- `apntalk/esl-core` for protocol/core contracts and parsing
- `apntalk/esl-react` for async runtime and long-lived worker behavior
- `apntalk/esl-replay` for replay-safe capture and replay tooling

---

## 1. Core architecture principles

### 1.1 Multi-PBX first
Never assume a single PBX connection. Every runtime path must support one or many PBX nodes.

### 1.2 Provider-aware, not provider-hardcoded
FreeSWITCH is the first implemented driver, but the package core should speak in terms of:

- provider
- node
- profile
- assignment
- connection resolution

not “the FreeSWITCH server.”

### 1.3 Database is the real PBX inventory
Use config for:

- defaults
- driver classes
- operational policies

Use the database for:

- PBX nodes
- provider bindings
- connection profiles
- worker assignments
- bounded health snapshot persistence rooted in `pbx_nodes.health_status`,
  `pbx_nodes.last_heartbeat_at`, and additive runtime-linked projection stored in
  `pbx_nodes.settings_json`

Current shipped `0.6.x` posture: this repository does not add a separate
health-history table. The bounded Laravel-owned health model projects the
latest known runtime-linked facts into the existing `pbx_nodes` record and
keeps full live-status history out of scope for this release line.

### 1.4 Runtime identity everywhere
Every event, job, health snapshot, and replay artifact should carry:

- provider
- `pbx_node_id`
- `pbx_node_slug`
- connection profile
- worker session/runtime session id

### 1.5 Worker runtime is first-class
Do not bury long-lived logic inside an artisan command loop. The worker is part of the platform, not a convenience layer.

### 1.6 APNTalk package boundaries are explicit
This Laravel package should integrate APNTalk’s ESL packages, not re-own their internal responsibilities.

- `apntalk/esl-core` owns protocol/core contracts, parsing, commands, typed protocol modeling
- `apntalk/esl-react` owns ReactPHP runtime, reconnect lifecycle, and long-lived async behavior
- `apntalk/esl-replay` owns replay envelopes, capture/store abstractions, and replay tooling
- `laravel-freeswitch-esl` owns Laravel integration, multi-PBX control plane, worker assignment orchestration, and app-facing operational surfaces

---

## 2. Package scope

This package should own five major domains.

### 2.1 Control plane
- PBX registry
- provider registry
- connection profile resolution
- worker assignment resolution
- secret resolution

### 2.2 Runtime integration
- binding APNTalk ESL client/runtime into Laravel
- provider-specific connection factory wiring
- session/context propagation into Laravel-owned control-plane metadata

### 2.3 Event integration
- app-facing event bridge
- Laravel bridge-wrapper dispatch around upstream typed and normalized payloads
- higher-level Laravel-native normalized domain events only if and when that
  later projection layer is intentionally added
- schema-aware app integration

### 2.4 Worker orchestration
- long-lived ingestion bootstrapping from Laravel
- retry/drain coordination at the package integration level
- graceful shutdown wiring
- assignment-aware startup
- replay-safe capture hooks via `apntalk/esl-replay`

### 2.5 Operations
- health reporting
- readiness/liveness
- structured logs
- metrics hooks
- diagnostics
- replay inspection

---

## 3. Repo foundation phase

### Objective
Set up the repository as a serious long-term package, not a proof of concept.

### Deliverables
- `composer.json`
- CI workflow
- coding standards
- static analysis
- Laravel Testbench setup
- docs skeleton
- examples app
- semantic versioning policy
- public API policy
- package boundary documentation

### Support target
For the package itself:

- PHP 8.3+
- Laravel 11 and 12

This floor is set by the currently supported `apntalk/esl-react` line used by
this repository. The package should not claim PHP 8.2 compatibility while the
shipped upstream runtime dependency requires PHP 8.3.

### Exit criteria
- package installs cleanly
- testbench boots
- CI green on base matrix
- package boundaries between Laravel package and APNTalk ESL packages are documented

---

## 4. Core domain and contracts phase

### Objective
Define stable Laravel-facing contracts before implementing provider wiring and runtime integration.

### Public contracts to define in this package
- `PbxRegistryInterface`
- `ProviderDriverRegistryInterface`
- `ConnectionResolverInterface`
- `ConnectionFactoryInterface`
- `WorkerInterface`
- `WorkerAssignmentResolverInterface`
- `HealthReporterInterface`
- `SecretResolverInterface`

### APNTalk ESL contracts consumed by this package

From `apntalk/esl-core`:
- `EslClientInterface`
- `CommandDispatcherInterface`
- `EventStreamInterface`
- `EventNormalizerInterface`

From `apntalk/esl-replay`:
- `ReplayArtifactStoreInterface`
- `ReplayCheckpointStoreInterface`

### Core value objects owned by this package
- `PbxProvider`
- `PbxNode`
- `ConnectionProfile`
- `WorkerAssignment`
- `ConnectionContext`
- `WorkerStatus`
- `HealthSnapshot`

### Core value objects consumed from APNTalk ESL packages
- `RawEslFrame`
- `RawEslEvent`
- `CommandRequest`
- `CommandResult`
- `BgapiJobHandle`
- `NormalizedEvent`
- `ReplayEnvelope`

### Rule
The public API should not leak third-party RTCKit types directly.

The public contract should be centered on APNTalk-owned contracts and DTOs so the ecosystem remains independently evolvable.

### Exit criteria
- stable contracts documented
- DTOs/value objects fully unit tested
- public namespace boundaries defined
- Laravel package contracts clearly separated from `apntalk/esl-core`, `apntalk/esl-react`, and `apntalk/esl-replay`

---

## 5. Multi-PBX control-plane phase

### Objective
Make the package database-driven for PBX inventory and runtime targeting.

### Database model set

#### 5.1 `pbx_providers`
Purpose: store provider families and driver metadata

Suggested fields:
- `id`
- `code`
- `name`
- `driver_class`
- `is_active`
- `capabilities_json`
- `settings_json`

#### 5.2 `pbx_nodes`
Purpose: store actual PBX endpoints

Suggested fields:
- `id`
- `provider_id`
- `name`
- `slug`
- `host`
- `port`
- `username`
- `password_secret_ref`
- `transport`
- `is_active`
- `region`
- `cluster`
- `tags_json`
- `settings_json`
- `health_status`
- `last_heartbeat_at`

Current shipped posture:
- `settings_json` also carries the latest bounded runtime-linked health facts
  recorded by Laravel when a real worker run has upstream status truth
- no separate dedicated health-snapshot table is being added in the current
  `0.6.x` release scope

#### 5.3 `pbx_connection_profiles`
Purpose: store reusable operational policies

Suggested fields:
- `id`
- `provider_id`
- `name`
- `retry_policy_json`
- `drain_policy_json`
- `subscription_profile_json`
- `replay_policy_json`
- `normalization_profile_json`
- `worker_profile_json`
- `settings_json`

#### 5.4 `worker_assignments`
Purpose: control which workers own which nodes/scopes

Suggested fields:
- `id`
- `worker_name`
- `assignment_mode`
- `pbx_node_id`
- `provider_code`
- `cluster`
- `tag`
- `is_active`

### Core services to build
- `DatabasePbxRegistry`
- `ProviderDriverRegistry`
- `ConnectionResolver`
- `WorkerAssignmentResolver`
- `SecretResolver`
- `ConnectionProfileResolver`

### Config model
Config should define the framework, not the live PBX inventory.

Config should contain:
- provider driver map
- default retry/drain values
- health thresholds
- secret resolution mode

### Exit criteria
- PBX nodes can be loaded dynamically from DB
- worker assignment can target one, many, or grouped PBXs
- FreeSWITCH driver can be resolved from provider registry

---

## 6. Transport and protocol integration phase

### Objective
Integrate FreeSWITCH ESL transport and runtime using APNTalk’s own libraries.

### Foundation
- `apntalk/esl-core` provides protocol/client contracts, typed protocol objects, parsing, command abstractions, and FreeSWITCH-facing core behavior
- `apntalk/esl-react` provides async connection runtime, reconnect lifecycle, and long-lived worker-safe runtime behavior

### Current shipped shape
- `EslCoreConnectionFactory` and `EslCoreConnectionHandle` own the Laravel-side prepared handoff bundle
- `EslCoreCommandFactory`, `EslCorePipelineFactory`, and `EslCoreEventBridge` adapt `apntalk/esl-core` into Laravel-facing seams
- `EslReactRuntimeBootstrapInputFactory` and `EslReactRuntimeRunnerAdapter` adapt the prepared handoff bundle into the upstream `apntalk/esl-react` runner

### Delegated upstream responsibilities
- connection/session lifecycle
- subscription management
- reconnect/backoff policy
- heartbeat monitoring
- bgapi runtime tracking

Those runtime mechanics are intentionally delegated to `apntalk/esl-react`.
Old plan names such as `ConnectionManager`, `SubscriptionManager`,
`ReconnectPolicy`, `HeartbeatMonitor`, and `BgapiTracker` should be treated as
superseded plan wording rather than missing Laravel-owned classes.

### Features
- connect/authenticate
- subscribe/unsubscribe
- filter management
- command execution
- bgapi job tracking
- reconnect/backoff
- session metadata capture

### Modes
- inbound client
- outbound server mode deferred beyond the current `1.0.0` target
- async worker transport

Current shipped decision:
- `1.0.0` readiness for this Laravel package is defined around stable inbound
  client-mode control-plane, worker, replay-integration, and operator surfaces
- outbound server mode is not a required `1.0.0` deliverable for this package

### Exit criteria
- connect to FreeSWITCH
- authenticate successfully
- send commands
- receive events
- recover from disconnects under test
- Laravel package depends only on APNTalk-owned ESL abstractions, not RTCKit-specific internals

---

## 7. Laravel integration phase

### Objective
Expose the control plane and runtime cleanly inside Laravel.

### Build
- service provider
- config publishing
- container bindings
- optional facade
- artisan diagnostics
- health routes/integration
- Laravel event bridge

### Laravel services
- `FreeSwitchEslServiceProvider`
- `FreeSwitchEslManager`
- `HealthReporter`
- `HealthSummaryBuilder`
- `WorkerSupervisor`
- `WorkerRuntime`
- `WorkerStatusReportBuilder`
- `WorkerCheckpointHistoryReportBuilder`

Older manager-style names such as `FreeswitchManager`, `PbxRegistryManager`,
`FreeswitchConnectionManager`, and `WorkerRuntimeManager` are superseded by the
current service-provider bindings, control-plane services, and worker/runtime
orchestration surfaces already shipped in this repository.

### Artisan commands
- `freeswitch:ping --pbx=`
- `freeswitch:status --pbx=`
- `freeswitch:worker --pbx=`
- `freeswitch:worker --cluster=`
- `freeswitch:worker --tag=`
- `freeswitch:worker --all-active`
- `freeswitch:health`
- `freeswitch:replay:inspect`

### Exit criteria
- package installs in Laravel app
- config publishes
- DB-backed PBX resolution works
- worker can boot from a resolved PBX node or assignment scope

---

## 8. Typed event and normalization phase

### Objective
Turn raw ESL traffic into stable, versioned internal events.

### Ownership model
- `apntalk/esl-core` should own raw ESL parsing, typed FreeSWITCH event modeling, and deterministic typed conversion
- this Laravel package should own app-facing integration and optional Laravel event bridging
- normalization should stay outside Laravel-specific concerns unless there is a Laravel-only projection layer

### Raw event layer
Implement parsing for:
- channel events
- lifecycle events
- bridge events
- hangup events
- media/playback events
- custom FreeSWITCH events
- bgapi/job completion events

### Typed event layer
Classes like:
- `ChannelCreated`
- `ChannelAnswered`
- `BridgeStarted`
- `BridgeEnded`
- `HangupCompleted`
- `PlaybackStarted`
- `PlaybackStopped`
- `BgapiJobCompleted`

### Normalized event layer
Classes like:
- `NormalizedCallCreated`
- `NormalizedCallAnswered`
- `NormalizedCallBridged`
- `NormalizedCallEnded`
- `NormalizedMediaStarted`
- `NormalizedQueueRetry`
- `NormalizedDrainStateChanged`

### Design rule
Every event includes:
- provider identity
- PBX node identity
- profile/runtime context
- schema version

### Exit criteria
- raw → typed conversion is deterministic
- typed → normalized conversion is deterministic
- schema version policy is documented
- Laravel package can dispatch app-facing events without owning low-level ESL parsing internals

---

## 9. Worker runtime phase

### Objective
Build a real long-lived worker system, not a helper loop.

### Ownership model
- `apntalk/esl-react` should own the reusable async runtime primitives
- this Laravel package should own assignment-aware worker bootstrapping, Laravel command integration, and control-plane-aware runtime supervision

### Worker modes
- single PBX node
- all active nodes
- cluster
- tag
- provider scope

### Core runtime components
Laravel package-owned:
- `WorkerRuntime`
- `WorkerSupervisor`
- replay-backed checkpoint coordination via `WorkerReplayCheckpointManager`
- bounded drain coordination and inflight bookkeeping inside `WorkerRuntime`
- assignment-aware runtime startup through `WorkerAssignmentResolverInterface`

Reusable APNTalk runtime components should come from `apntalk/esl-react` where appropriate:
- connection loop/runtime session
- reconnect state handling
- subscription lifecycle
- heartbeat state

Items previously listed here such as `AssignmentRunner`, `DrainController`,
`RetryController`, `CheckpointStore`, `DeadLetterStore`, and
`BackpressureController` are not a required one-class-per-concern target for
this package. In current repo truth:
- checkpoint persistence belongs upstream to `apntalk/esl-replay`
- reconnect and heartbeat logic belong upstream to `apntalk/esl-react`
- bounded drain and backpressure enforcement may live inside `WorkerRuntime`
  when that remains the smallest coherent implementation
- dead-letter architecture remains deferred and is out of scope for the bounded
  `0.6.x` hardening pass

### Features
- graceful shutdown
- drain mode
- checkpointing
- heartbeat
- inflight control
- reconnect logic
- node-level failure isolation
- worker session metadata

### Exit criteria
- worker survives disconnect/reconnect
- worker drains cleanly
- worker can target cluster/tag/all-active modes
- worker failure in one PBX scope does not corrupt others

For the current `0.6.x` hardening target, “survives disconnect/reconnect” means
this repository proves its Laravel-facing runtime handoff, observation, drain,
and health/reporting behavior against upstream-owned runtime lifecycle changes.
It does not move reconnect ownership into this package.

---

## 10. Replay-safe capture phase

### Objective
Make the platform replay-ready by integrating with `apntalk/esl-replay`.

### Ownership model
- `apntalk/esl-replay` owns replay envelopes, capture contracts, replay stores, replay projectors, and replay scenario runners
- this Laravel package should provide Laravel-oriented store wiring, retention policy, inspection commands, and app integration

### Build
Laravel integration layer:
- replay capture wiring
- connection/session metadata enrichment
- correlation ID propagation
- replay inspection commands
- Laravel storage binding for replay stores

Provided by `apntalk/esl-replay`:
- `ReplayArtifactStoreInterface`
- `ReplayCheckpointStoreInterface`
- `ReplayProjectorInterface`
- `ReplayScenarioRunner`
- `ReplayCursor`
- `ReplayEnvelope`

### Storage options
- database first
- filesystem optional
- Redis optional
- object storage later

### Rule
Replay must be partitionable by:
- provider
- PBX node
- worker session
- time window

### Exit criteria
- recorded streams can be replayed in tests
- normalized outputs remain deterministic for supported scenarios
- replay inspection tools expose enough metadata for debugging
- Laravel package does not duplicate replay primitives already defined in `apntalk/esl-replay`

---

## 11. Observability and operations phase

### Objective
Make the package operable in real dialer environments.

### Build
- structured logging
- metrics abstraction
- health probes
- connection diagnostics
- worker diagnostics
- liveness/readiness surfaces
- per-node and aggregate health summaries

### Health should report
- connection state
- subscription state
- worker assignment scope
- inflight events
- retry state
- last heartbeat
- drain state
- recent failures

### Exit criteria
- operator can inspect one PBX or all PBXs
- logs and metrics are structured enough for production operations
- diagnosis does not depend on raw dumps alone

---

## 12. Test and hardening phase

### Objective
Make the package safe to evolve.

### Test layers
- unit tests
- contract tests
- integration tests
- replay tests
- multi-PBX assignment tests
- disconnect/reconnect tests
- malformed frame tests
- drain/backpressure tests

### Important test capabilities
- simulated ESL server/client harness
- deterministic fixtures
- cluster/tag/all-active worker assignment coverage
- profile and secret resolution tests
- package-boundary checks against stale plan-shape drift

### Exit criteria
- most logic testable without real PBX
- integration tests cover real ESL behavior paths
- replay tests protect normalized schema stability
- package-boundary tests verify Laravel package does not drift into re-owning protocol/runtime/replay internals

---

## 13. Release model phase

### Objective
Ship with a support policy that matches long-term maintenance.

### Release stages
- `0.1.x` repo foundation + control-plane contracts
- `0.2.x` integrate `apntalk/esl-core`
- `0.3.x` land Laravel-owned runtime handoff and runner seams
- `0.4.x` bind the real `apntalk/esl-react` runner and report upstream runtime observation truthfully
- `0.5.x` integrate `apntalk/esl-replay` and bounded checkpoint/reporting surfaces
- `0.6.x` observability + hardening
- `1.0.0` only after runtime and multi-PBX behavior are stable

Current repo truth at the bounded `0.6.x` RC-ready surface:
- `0.1.x` through `0.6.x` architecture is materially present for the Laravel
  package's control-plane, runtime-handoff, replay integration, health, and
  observability responsibilities
- several originally named classes were merged, renamed, or delegated upstream
  and should not be treated as missing product behavior solely because the
  original plan used different names
- the bounded `0.6.x` package work is complete for:
  - truthful authority docs and compatibility surfaces
  - shipped non-null metrics drivers, with `log` as the default install path
  - load-bearing `max_inflight` enforcement and bounded backpressure metadata
  - deterministic simulated ESL lifecycle verification through the current
    `apntalk/esl-react` boundary
  - a near-runnable example-app cookbook
- remaining release-promotion work is external-only:
  - private-network live validation evidence
  - RC promotion mechanics

Still delegated or deferred by design:
- protocol parsing and low-level transport behavior remain in `apntalk/esl-core`
- reconnect/backoff, heartbeat/session lifecycle, subscription lifecycle, and
  broader async runtime ownership remain in `apntalk/esl-react`
- replay primitives, replay execution, replay projectors/scenario runners, and
  checkpoint-store primitives remain in `apntalk/esl-replay`
- broader dead-letter or queueing architecture remains deferred

### Next repo-owned continuation pack

`Post-0.6 Repo-Owned Operator & Adoption Completion Pack`

Objective:
- finish the next highest-value Laravel-package work without reopening release
  plumbing, live validation, or upstream-owned runtime responsibilities

Scope:
- stronger human-readable operator surfaces for backpressure, metrics-driver,
  drain, and bounded runtime posture
- a self-validating example/adoption path that proves install/config/seed/
  status wiring without requiring live ESL or GitHub setup

Acceptance criteria:
- operators can see bounded backpressure and metrics posture without relying on
  raw JSON fields alone
- human-readable worker/health output gives concise action-oriented wording when
  a worker is draining or refusing new work
- a downstream maintainer can run one bounded local validation path that checks
  config shape, schema presence, seed shape, container bindings, command
  discoverability, and metrics-recorder wiring without live infrastructure

### Policy docs
- public API surface
- internal namespace rules
- deprecation policy
- Laravel/PHP support matrix
- FreeSWITCH compatibility notes
- schema versioning policy
- package boundary policy

---

## Recommended repo structure

```text
laravel-freeswitch-esl/
  composer.json
  README.md
  CHANGELOG.md
  LICENSE
  config/
    freeswitch-esl.php
  database/
    migrations/
    factories/
  docs/
    architecture.md
    control-plane.md
    package-boundaries.md
    event-model.md
    worker-runtime.md
    replay-integration.md
    public-api.md
    compatibility-policy.md
  examples/
    laravel-app/
  src/
    Contracts/
    ControlPlane/
    Drivers/
      Freeswitch/
    Connection/
    Integration/
    Worker/
    Health/
    Observability/
    Console/
    Providers/
    Facades/
    Support/
    Exceptions/
  tests/
    Unit/
    Contract/
    Integration/
    Replay/
    Fixtures/
```

### Note
Avoid rebuilding protocol/parser/runtime internals here if they already belong in:
- `apntalk/esl-core`
- `apntalk/esl-react`
- `apntalk/esl-replay`

---

## Recommended dependency direction

Use:

- `apntalk/esl-core` for protocol/core ESL contracts, parsing, typed events, and command abstractions
- `apntalk/esl-react` for async runtime, connection lifecycle, reconnect behavior, and long-lived worker support
- `apntalk/esl-replay` for replay-safe capture, replay envelopes, replay stores, and replay tooling
- Laravel Testbench for package integration tests
- PHPStan/Psalm for static analysis

---

## Strongest recommendation

Do not start with:
- facade-first design
- single `.env` PBX config
- command-only API
- one worker for one host
- third-party transport adapters as your architectural center

Start with:
- control plane
- contracts
- multi-PBX identity model
- APNTalk-owned ESL abstractions
- `apntalk/esl-core` as protocol foundation
- `apntalk/esl-react` as runtime foundation
- `apntalk/esl-replay` as replay foundation
- worker runtime integration
- event normalization
- replay-safe capture hooks

That path matches APNTalk’s actual product direction as a cohesive ESL package family, not a Laravel package wrapped around third-party transport libraries.
