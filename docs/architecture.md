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
  ConnectionFactoryInterface      — runtime factory contract (esl-react integration point)
  WorkerInterface                 — worker boot/run/drain/shutdown lifecycle
  WorkerAssignmentResolverInterface — assignment scope resolution
  HealthReporterInterface         — structured health snapshot contract
  SecretResolverInterface         — credential resolution contract

  Upstream/                       — @internal development-phase replay stub only
    ReplayCaptureStoreInterface   — stub for apntalk/esl-replay store
```

`Contracts/Upstream/ReplayCaptureStoreInterface` is `@internal`. It exists only until `apntalk/esl-replay` is integrated directly. Do not type-hint against it in application code.

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
  → EslCoreConnectionHandle
```

The handle is retained by the worker scaffolding for later `apntalk/esl-react` consumption. It is not itself a long-lived runtime loop.

`WorkerRuntime::status()` surfaces this seam via `WorkerStatus::meta` so operator-facing Laravel scaffolding can distinguish “handoff prepared” from “live runtime connected.” In the current scaffolding posture, `WorkerStatus::state = running` means boot completed and handoff prepared, while `meta.runtime_loop_active` remains `false`. `WorkerSupervisor::runtimeStatuses()` aggregates those snapshots per PBX node slug without taking ownership of session supervision.

### esl-core integration

The repository already includes a narrow Laravel-owned adapter layer over `apntalk/esl-core`:

```
src/Integration/
  EslCoreConnectionFactory — assembles the current runtime handoff seam from ConnectionContext
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

Current limitation: the default lazy transport opener wraps an internal `apntalk/esl-core`
stream transport implementation because no stable public socket transport type exists yet.
Treat that opener as package-internal scaffolding, not as a stable extension surface.

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
  FreeSwitchHealthCommand         — freeswitch:health
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

Propagation into replay metadata remains future work for `0.5.x`.

Propagation into Laravel-dispatched ESL events is already partially implemented through
`src/Events/*`, where each dispatched event carries `ConnectionContext`.

---

## Event model

Typed ESL events, replies, and normalization remain owned by `apntalk/esl-core`.
This package now owns the Laravel event bridge layer that wraps decoded esl-core messages for dispatch.

See `docs/event-model.md` for the full ownership model and integration plan.

---

## Replay integration

Replay in this package is integration-only. The canonical replay abstractions (`ReplayCaptureStoreInterface`, `ReplayEnvelope`, etc.) belong in `apntalk/esl-replay`.

This package wires:
- Laravel storage binding for the replay store
- Retention policy configuration
- `freeswitch:replay:inspect` command for inspection
- Session/correlation metadata propagation (wired in `0.5.x`)

See `docs/replay-integration.md` for details.

---

## Public API

See `docs/public-api.md` for the full list of stable public surfaces, internal surfaces, and extension points.
