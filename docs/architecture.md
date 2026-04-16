# Architecture

## Package mission

`apntalk/laravel-freeswitch-esl` is the Laravel integration and multi-PBX control plane for the APNTalk ESL package family.

The package implements the following flow:

```
Laravel app → PBX registry → provider driver → resolved runtime connection → worker/event pipeline
```

---

## Core components

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
  WorkerRuntime      — Single-node worker (implements WorkerInterface)
  WorkerSupervisor   — Multi-node orchestrator (boots/supervises WorkerRuntime instances)
```

The supervisor resolves target nodes from the assignment scope, boots one runtime per node, and isolates node failures.

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
  → FreeSwitchWorkerCommand / FreeSwitchPingCommand
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

Every connection context, worker status, and health snapshot carries:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `connection_profile_id` / `connection_profile_name`
- `worker_session_id` (assigned by WorkerRuntime)

This identity propagates into logs, events, replay metadata, and health snapshots.

---

## Replay integration

Replay in this package is integration-only. The canonical replay abstractions (`ReplayCaptureStoreInterface`, `ReplayEnvelope`, etc.) belong in `apntalk/esl-replay`.

This package wires:
- Laravel storage binding for the replay store
- Retention policy configuration
- `freeswitch:replay:inspect` command for inspection
- Session/correlation metadata propagation

See `docs/replay-integration.md` for details.
