# Worker Runtime

## Overview

The worker runtime is a first-class component of this package. In the current package posture, it is the platform-level orchestration and handoff-preparation layer for one or more PBX nodes. It is not yet a live async runtime loop.

---

## Ownership model

| Responsibility | Owner |
|---|---|
| Assignment-aware startup | `apntalk/laravel-freeswitch-esl` (this package) |
| Worker session identity | `apntalk/laravel-freeswitch-esl` |
| Node-level failure isolation | `apntalk/laravel-freeswitch-esl` |
| Graceful drain coordination | `apntalk/laravel-freeswitch-esl` |
| TCP connection lifecycle | `apntalk/esl-react` through the bound runner |
| TLS/direct transport handoff | Deferred until `apntalk/esl-react` exposes that public path |
| Reconnect/backoff loop | `apntalk/esl-react` |
| ESL event subscription management | `apntalk/esl-react` |
| Heartbeat monitoring primitives | `apntalk/esl-react` |

---

## Components

### WorkerRuntime

Manages a single PBX node connection for one worker session.

```
ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime
```

Lifecycle:
1. `boot()` — resolves and **persists** the `ConnectionContext` (with worker session identity attached via `withWorkerSession()`), creates and retains the package-owned runtime handoff bundle through `ConnectionFactoryInterface`, then sets state to `running`
2. `run()` — validates the prepared handoff state and invokes `RuntimeRunnerInterface`; by default this adapts the handoff into `apntalk/esl-react`'s prepared bootstrap input and invokes the upstream runner
3. `drain()` — signals drain mode; current scaffolding records drain intent only
4. `shutdown()` — cleans up resources and returns

Each `WorkerRuntime` carries a stable `sessionId` for log correlation and health reporting.

The resolved `ConnectionContext` is persisted as `$resolvedContext` during `boot()` and accessible via `resolvedContext(): ?ConnectionContext`. This ensures the credential is resolved exactly once.

`boot()` also creates and retains the package-owned runtime handoff bundle via `ConnectionFactoryInterface`, which is now typed directly to `RuntimeHandoffInterface`. The preferred adapter-facing seam is `runtimeHandoff(): ?RuntimeHandoffInterface`; `connectionHandle()` remains a convenience accessor to the current implementation. The current `EslCoreConnectionHandle` packages:
- the resolved `ConnectionContext`
- the opening and closing command sequences
- a preferred-default inbound pipeline created via `InboundPipeline::withDefaults()`
- lazy transport opening through `SocketTransportFactory`

This makes the worker handoff state explicit and inspectable without claiming ownership of a live runtime loop.

`status()` now reports this handoff readiness truthfully through `WorkerStatus::meta` and `WorkerStatus` helper methods:
- `context_resolved`
- `connection_handoff_prepared`
- `handoff_endpoint`
- `runtime_handoff_contract`
- `runtime_handoff_class`

`WorkerStatus::isHandoffPrepared()` answers whether boot prepared an adapter-consumable bundle.
`WorkerStatus::isRuntimeRunnerInvoked()` answers whether the Laravel-owned runtime runner seam was called.
`WorkerStatus::isRuntimeFeedbackObserved()` answers whether the bound runner exposed a feedback snapshot.
`WorkerStatus::isRuntimeLoopActive()` answers whether that feedback reports a live runtime. When the installed `apntalk/esl-react` runner handle exposes `lifecycleSnapshot()`, Laravel maps that upstream snapshot into status metadata including connection/session state, live/reconnecting/draining/stopped flags, reconnect attempts, last heartbeat timestamp, and startup/runtime error fields.
These fields mean “boot prepared the runtime handoff seam,” “the configured runner was invoked,” and “Laravel observed upstream runner lifecycle state.” They do not give Laravel ownership of reconnect, heartbeat, or session lifecycle behavior.

### WorkerSupervisor

Manages one or more `WorkerRuntime` instances for an assignment scope.

```
ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor
```

The supervisor exposes two entry points depending on how the target node set is determined:

**Ephemeral path** — nodes resolved from a `WorkerAssignment` (CLI targeting flags):

```php
$supervisor->run(WorkerAssignment $assignment): void
```

This path calls `WorkerAssignmentResolver::resolveNodes()` internally. Used by `freeswitch:worker` without `--db`.

**DB-backed path** — nodes pre-resolved by the caller:

```php
$supervisor->runForNodes(string $workerName, string $assignmentScope, array $nodes): void
```

Used when `WorkerAssignmentResolver::resolveForWorkerName()` has already resolved nodes from the `worker_assignments` table. The caller passes the node list directly.

Both paths:
- boot one `WorkerRuntime` per resolved node
- isolate node-level failures (one failing node does not abort the others)
- coordinate `drain()` and `shutdown()` across all runtimes

`WorkerSupervisor::runtimeStatuses()` exposes per-node `WorkerStatus` snapshots keyed by PBX node slug. `WorkerSupervisor::runtimeHandoffs()` exposes the prepared adapter-facing bundles keyed by PBX node slug. Both are Laravel-scaffolding inspection surfaces only; neither implies Laravel has observed a live runtime loop. The default runner may be invoked while still leaving `runtime_loop_active = false`.

---

## Worker states

Current emitted states:

```
booting → running → draining → shutdown
```

Reserved future states:
- `failed`
- `reconnecting`

`running` currently means “boot completed and handoff prepared.” It does not mean “a live async runtime loop is active.”

---

## Assignment modes

Start a worker via the `freeswitch:worker` command.

### Ephemeral assignment (CLI targeting flags)

Nodes are resolved at command startup from flags and not persisted to the database.

```bash
# Single node by slug
php artisan freeswitch:worker --pbx=primary-fs

# All nodes in a cluster
php artisan freeswitch:worker --cluster=us-east

# All nodes with a tag
php artisan freeswitch:worker --tag=prod

# All nodes for a provider
php artisan freeswitch:worker --provider=freeswitch

# All active nodes
php artisan freeswitch:worker --all-active
```

### DB-backed assignment

Nodes are resolved from active rows in the `worker_assignments` table for the given worker name.

```bash
php artisan freeswitch:worker --worker=my-worker --db
```

The `--db` flag and any ephemeral targeting flag (`--pbx`, `--cluster`, `--tag`, `--provider`, `--all-active`) are mutually exclusive. Combining them is a command error.

### Worker identity

The `--worker=<name>` option sets the worker identity (default: `esl-worker`). This name appears in logs, retained runtime handoff state, and is the lookup key for `worker_assignments` rows when `--db` is used.

After startup, `freeswitch:worker` reports how many node runtimes reached the prepared-handoff state, how many invoked the configured runner, and how many reported a running live runtime through the feedback seam. That summary is intentionally narrow and does not claim Laravel owns the `apntalk/esl-react` session lifecycle.

---

## Node-level failure isolation

If one node fails to boot or encounters a runtime error, the supervisor logs the error and continues to the next node. This ensures that a single bad PBX node does not bring down workers targeting other nodes in the same scope.

---

## Graceful shutdown

Workers should handle POSIX signals (SIGTERM, SIGINT) to trigger `drain()` and then `shutdown()`.

Signal wiring is the application's responsibility. The package provides `WorkerInterface::drain()` and `WorkerInterface::shutdown()` to integrate with any signal-handling strategy.

---

## esl-react runner binding

The current `WorkerRuntime::run()` body invokes `RuntimeRunnerInterface`. The default binding is `EslReactRuntimeRunnerAdapter`, which maps the prepared `RuntimeHandoffInterface` into `apntalk/esl-react`'s `PreparedRuntimeBootstrapInput` and calls the upstream runner. The `NonLiveRuntimeRunner` remains available through `freeswitch-esl.runtime.runner = non-live` for dry-run or unsupported environments.

```php
// Inside EslReactRuntimeRunnerAdapter:
$input = $this->inputFactory->create($handoff);
$this->runner->run($input);
```

The `ConnectionContext` and connection handle are both prepared during `boot()` (with the worker session ID already attached). The `run()` body only invokes `RuntimeRunnerInterface` after `boot()` has completed, which it enforces with a guard that throws `WorkerException::bootFailed()` if the runtime handoff state is incomplete.

The current adapter supports TCP handoffs. TLS and direct `apntalk/esl-core` `TransportInterface` polling handoff remain deferred until `apntalk/esl-react` exposes those public paths.

The adapter also exposes runner feedback from `RuntimeRunnerHandle`. With older `apntalk/esl-react` versions this is coarse startup state only: `starting`, `running`, or `failed`, plus endpoint, session id, and startup error message when available. With lifecycle-snapshot-capable handles, Laravel maps the upstream read-only lifecycle snapshot into `WorkerStatus::meta`; it does not poll the client, reconnect the session, or manage heartbeats.

For future accepted-stream/listener-backed adapters, the upstream `InboundConnectionFactory` is now the preferred bootstrap seam. Binding that seam here does not imply listener or runtime ownership in this package.

The `WorkerRuntime` will remain the assignment-aware orchestration layer; `esl-react` handles the async loop.

---

## Checkpointing and replay integration (future — requires apntalk/esl-replay)

Replay capture wiring is not implemented in `0.1.x`. The following describes the
intended behavior once `apntalk/esl-replay` is integrated (`0.5.x`):

- each worker session registers with the replay capture store
- events are captured with session and node identity
- checkpoints are written periodically
- drain mode flushes pending captures before completing shutdown

The current `WorkerRuntime::drain()` signals drain mode only (sets state flag).
Flush coordination against a replay store will be added when that integration is wired.

Configuration: `freeswitch-esl.replay.*`
