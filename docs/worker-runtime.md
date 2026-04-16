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
| TCP/TLS connection lifecycle | `apntalk/esl-react` (not yet wired) |
| Reconnect/backoff loop | `apntalk/esl-react` (not yet wired) |
| ESL event subscription management | `apntalk/esl-react` (not yet wired) |
| Heartbeat monitoring primitives | `apntalk/esl-react` (not yet wired) |

---

## Components

### WorkerRuntime

Manages a single PBX node connection for one worker session.

```
ApnTalk\LaravelFreeswitchEsl\Worker\WorkerRuntime
```

Lifecycle:
1. `boot()` — resolves and **persists** the `ConnectionContext` (with worker session identity attached via `withWorkerSession()`), creates and retains the package-owned connection handle through `ConnectionFactoryInterface`, then sets state to `running`
2. `run()` — validates and consumes the prepared handoff state for the current implementation; today it logs and returns immediately, and later will delegate to `apntalk/esl-react`
3. `drain()` — signals drain mode; current scaffolding records drain intent only
4. `shutdown()` — cleans up resources and returns

Each `WorkerRuntime` carries a stable `sessionId` for log correlation and health reporting.

The resolved `ConnectionContext` is persisted as `$resolvedContext` during `boot()` and accessible via `resolvedContext(): ?ConnectionContext`. This ensures the credential is resolved exactly once.

`boot()` also creates and retains the package-owned runtime handoff handle via `ConnectionFactoryInterface`. That handle is accessible via `connectionHandle(): ?EslCoreConnectionHandle` and packages:
- the resolved `ConnectionContext`
- the opening and closing command sequences
- a fresh inbound pipeline
- lazy raw transport opening

This makes the worker handoff state explicit and inspectable without claiming ownership of a live runtime loop.

`status()` now reports this handoff readiness truthfully through `WorkerStatus::meta`:
- `context_resolved`
- `connection_handoff_prepared`
- `handoff_endpoint`

These fields mean “boot prepared the runtime handoff seam,” not “a live async runtime is connected.”
`runtime_loop_active` is currently always `false`.

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

`WorkerSupervisor::runtimeStatuses()` exposes per-node `WorkerStatus` snapshots keyed by PBX node slug. This is a Laravel-scaffolding inspection surface for retained handoff state only.

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

After startup, `freeswitch:worker` reports how many node runtimes reached the prepared-handoff state. That summary is intentionally narrow and does not claim a live `apntalk/esl-react` loop is running.

---

## Node-level failure isolation

If one node fails to boot or encounters a runtime error, the supervisor logs the error and continues to the next node. This ensures that a single bad PBX node does not bring down workers targeting other nodes in the same scope.

---

## Graceful shutdown

Workers should handle POSIX signals (SIGTERM, SIGINT) to trigger `drain()` and then `shutdown()`.

Signal wiring is the application's responsibility. The package provides `WorkerInterface::drain()` and `WorkerInterface::shutdown()` to integrate with any signal-handling strategy.

---

## esl-react integration (future)

The current `WorkerRuntime::run()` body is a stub that logs and returns immediately. It does not start or own a live async ESL session. When `apntalk/esl-react` is available and required, it should be replaced with:

```php
// Inside WorkerRuntime::run():
// $this->connectionHandle is already prepared by boot() — no re-resolution needed.
$this->reactRuntime->run($this->connectionHandle, ...$runtimeOptions);
```

The `ConnectionContext` and connection handle are both prepared during `boot()` (with the worker session ID already attached). The `run()` stub body must call `boot()` first, which it enforces with a guard that throws `WorkerException::bootFailed()` if the runtime handoff state is incomplete.

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
