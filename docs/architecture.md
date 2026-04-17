# Architecture

## Package mission

`apntalk/laravel-freeswitch-esl` is the Laravel integration and multi-PBX control plane for the APNTalk ESL package family.

The package implements the following flow:

```
Laravel app → PBX registry → provider driver → resolved runtime connection → worker/event pipeline
```

---

## Core components

### Public contracts

The stable public API surface of this package lives in `src/Contracts/`.

```
src/Contracts/
  PbxRegistryInterface            — multi-PBX node inventory lookups
  ProviderDriverRegistryInterface — provider driver map and resolution
  ProviderDriverInterface         — contract for PBX provider drivers
  ConnectionResolverInterface     — full resolution pipeline contract
  ConnectionFactoryInterface      — runtime handoff factory contract returning RuntimeHandoffInterface
  RuntimeHandoffInterface         — adapter-facing prepared runtime bundle contract
  RuntimeRunnerInterface          — Laravel-owned runtime runner seam for invoking adapters
  WorkerInterface                 — worker boot/run/drain/shutdown lifecycle
  WorkerAssignmentResolverInterface — assignment scope resolution
  HealthReporterInterface         — structured health snapshot contract
  SecretResolverInterface         — credential resolution contract
```

---

### Control plane

The control plane is database-backed. Configuration provides driver wiring and defaults. The database provides the live PBX inventory.

```
src/ControlPlane/
  ValueObjects/       — Immutable DTOs used throughout the runtime
    PbxProvider       — Provider family identity
    PbxNode           — Single PBX endpoint identity
    ConnectionProfile — Reusable operational policy
    WorkerAssignment  — Worker targeting scope
    ConnectionContext — Fully resolved connection parameters
    WorkerStatus      — Worker operational state snapshot
    HealthSnapshot    — PBX node health at a point in time

  Models/             — Eloquent DB representations
    PbxProvider
    PbxNode
    PbxConnectionProfile
    WorkerAssignment

  Services/           — Control-plane service implementations
    DatabasePbxRegistry        — PbxRegistryInterface impl (DB-backed)
    ProviderDriverRegistry     — ProviderDriverRegistryInterface impl
    ConnectionResolver         — ConnectionResolverInterface impl
    ConnectionProfileResolver  — Profile resolution from DB or config defaults
    WorkerAssignmentResolver   — WorkerAssignmentResolverInterface impl
    SecretResolver             — SecretResolverInterface impl
```

### Provider drivers

```
src/Drivers/
  FreeSwitchDriver    — First ProviderDriverInterface implementation
```

Drivers build `ConnectionContext` from `PbxNode + ConnectionProfile`. They do not open connections or parse ESL frames — those responsibilities belong in `apntalk/esl-core` and `apntalk/esl-react`.

### Worker orchestration

```
src/Worker/
  WorkerRuntime      — Single-node worker (implements WorkerInterface; retains resolved context + connection handle)
  WorkerSupervisor   — Multi-node orchestrator (boots/supervises WorkerRuntime instances)
```

The supervisor resolves target nodes from the assignment scope, boots one runtime per node, and isolates node failures.

Each `WorkerRuntime` now advances the handoff path to:

```
PbxNode
  → ConnectionResolverInterface
  → ConnectionContext
  → ConnectionFactoryInterface
  → RuntimeHandoffInterface
  → EslCoreConnectionHandle (current implementation)
  → RuntimeRunnerInterface
  → apntalk/esl-react PreparedRuntimeBootstrapInput
```

The prepared handoff bundle is retained by the worker scaffolding for later runtime-adapter consumption. The current implementation is `EslCoreConnectionHandle`, but runtime adapters should target the Laravel-owned `RuntimeHandoffInterface`. It is not itself a long-lived runtime loop.

`WorkerRuntime::status()` surfaces this seam via `WorkerStatus::meta` and helper methods so operator-facing Laravel scaffolding can distinguish “handoff prepared,” “adapter-ready,” “adapter invoked,” “runner feedback observed,” “push-observed lifecycle updates,” and “live runtime connected.” In the current posture, `WorkerStatus::state = running` means boot completed and handoff prepared, `meta.runtime_adapter_ready` is the adapter-consumable seam flag, `meta.runtime_runner_invoked` means the Laravel-owned runtime runner seam was called, `meta.runtime_feedback_observed` means the bound runner exposed lifecycle feedback, `meta.runtime_push_lifecycle_observed` means the feedback was delivered through upstream `onLifecycleChange()`, and `meta.runtime_loop_active` is true only when the feedback reports a live runtime. On the supported `apntalk/esl-react` `^0.2.7` line, Laravel maps `RuntimeRunnerHandle::lifecycleSnapshot()` into status metadata and subscribes to `RuntimeRunnerHandle::onLifecycleChange()` instead of inferring live-ness from runner startup state. `WorkerSupervisor::runtimeStatuses()` aggregates those snapshots per PBX node slug, while `runtimeHandoffs()` exposes the prepared adapter-facing bundles without taking ownership of session supervision.

### esl-core integration

The repository already includes a narrow Laravel-owned adapter layer over `apntalk/esl-core`:

```
src/Integration/
  EslCoreConnectionFactory — assembles the current RuntimeHandoffInterface seam from ConnectionContext
  EslCoreConnectionHandle  — package-owned opaque handle for transport/pipeline/command bootstrapping
  EslCoreCommandFactory   — builds typed esl-core command objects from Laravel inputs
  EslCorePipelineFactory  — creates per-session inbound decode pipelines
  EslCoreEventBridge      — dispatches decoded esl-core messages as Laravel events

src/Events/
  EslEventReceived        — wraps typed event + normalized substrate + ConnectionContext
  EslReplyReceived        — wraps typed reply + ConnectionContext
  EslDisconnected         — wraps disconnect notice + ConnectionContext
```

This is intentionally an adapter layer, not a reimplementation of protocol parsing. Frame decoding, typed message construction, raw transport contracts, and reply/event types remain owned by `apntalk/esl-core`.

`EslCoreConnectionFactory` is the current package-owned runtime handoff seam. It does not implement the `apntalk/esl-react` worker loop; it assembles the resolved context, opening/closing command sequences, inbound pipeline, and lazy raw transport opening into a package-owned handle.
It now uses `apntalk/esl-core`'s stable public construction seams:
- `SocketTransportFactory` for socket/stream transport construction
- `InboundPipeline::withDefaults()` for the preferred default ingress path
- `InboundConnectionFactory` as the accepted-stream bootstrap seam available for future runtime adapters

This package still does not own listener/runtime behavior. The accepted-stream factory is only bound as an upstream seam for later integration work. The Laravel-owned `RuntimeRunnerInterface` is an invocation seam; the default binding now adapts `RuntimeHandoffInterface` into `apntalk/esl-react`'s `PreparedRuntimeBootstrapInput`, including an explicit prepared dial URI when the resolved transport requires a non-default target such as `tls://host:port`. Live loop, reconnect, heartbeat, and session lifecycle behavior remain owned by `apntalk/esl-react`.

### Health and observability

```
src/Health/
  HealthReporter     — HealthReporterInterface impl (DB-backed health snapshots)
```

### Laravel integration

```
src/Providers/
  FreeSwitchEslServiceProvider   — Main service provider

src/Console/Commands/
  FreeSwitchPingCommand           — freeswitch:ping
  FreeSwitchStatusCommand         — freeswitch:status
  FreeSwitchWorkerCommand         — freeswitch:worker
  FreeSwitchHealthCommand         — freeswitch:health (DB-backed snapshots plus optional bounded aggregate summary)
  FreeSwitchReplayInspectCommand  — freeswitch:replay:inspect

src/Facades/
  FreeSwitchEsl           — Optional facade
  FreeSwitchEslManager    — Facade-backing manager
```

---

## Database schema

| Table | Purpose |
|---|---|
| `pbx_providers` | Provider families and driver metadata |
| `pbx_nodes` | Actual PBX endpoints (the live inventory) |
| `pbx_connection_profiles` | Reusable operational policy profiles |
| `worker_assignments` | Worker-to-scope targeting records |

See `database/migrations/` for schema details.

---

## Connection resolution pipeline

```
freeswitch:ping --pbx=my-node
  → FreeSwitchPingCommand
    → ConnectionResolverInterface::resolveForSlug('my-node')
      → PbxRegistryInterface::findBySlug('my-node')         → PbxNode VO
      → ConnectionProfileResolver::resolveDefaultForProvider(provider_id) → ConnectionProfile VO
      → SecretResolverInterface::resolve(passwordSecretRef) → plaintext credential
      → ProviderDriverRegistryInterface::resolve('freeswitch') → FreeSwitchDriver
      → FreeSwitchDriver::buildConnectionContext(node, profile) → ConnectionContext VO (no cred)
      → ConnectionResolver re-injects resolved credential
      → ConnectionContext (fully resolved)
```

---

## Worker assignment modes

| Mode | Target |
|---|---|
| `node` | Single PBX node by ID |
| `cluster` | All active nodes in a named cluster |
| `tag` | All active nodes matching a tag |
| `provider` | All active nodes for a provider code |
| `all-active` | All currently active PBX nodes |

---

## Multi-PBX guarantee

The control plane never assumes a single PBX. Every path through registry, resolver, worker supervisor, and health reporter supports one-or-many nodes. Single-node mode is just a special case of the general case.

---

## Runtime identity

Every connection context and worker status carries:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `connection_profile_id` / `connection_profile_name`
- `worker_session_id` (assigned by WorkerRuntime)

Current `HealthSnapshot` surfaces are narrower. They carry node/provider identity and DB-backed
health fields, but they do not yet carry connection-profile identity or worker-session identity.

In `0.1.x`, connection/runtime identity is propagated into structured logs and worker/runtime status
surfaces, not into full live runtime health telemetry.

Replay integration now also persists that runtime identity into replay artifact runtime flags and uses
the same worker/node/profile identity anchor for bounded replay-backed checkpoint coordination and
bounded worker recovery hints.

Propagation into Laravel-dispatched ESL events is already partially implemented through
`src/Events/*`, where each dispatched event carries `ConnectionContext`.

---

## Event model

Typed ESL events, replies, and normalization remain owned by `apntalk/esl-core`.
This package now owns the Laravel event bridge layer that wraps decoded esl-core messages for dispatch.

See `docs/event-model.md` for the full ownership model and integration plan.

---

## Replay integration

Replay in this package is integration-only. The canonical durable replay abstractions (`ReplayArtifactStoreInterface`, `CapturedArtifactEnvelope`, `StoredReplayRecord`, etc.) belong in `apntalk/esl-replay`.

This package wires:
- Laravel storage binding for the replay artifact store
- Laravel storage binding for the replay checkpoint store
- Laravel binding for the upstream replay checkpoint repository
- Retention policy configuration
- `freeswitch:replay:inspect` command for inspection
- Session/correlation metadata propagation into stored runtime flags and payloads
- bounded worker drain/checkpoint coordination plus checkpoint-backed recovery posture over persisted replay artifacts

See `docs/replay-integration.md` for details.

---

## Public API

See `docs/public-api.md` for the full list of stable public surfaces, internal surfaces, and extension points.
