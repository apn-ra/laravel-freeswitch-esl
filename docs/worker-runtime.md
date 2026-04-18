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
| Explicit prepared dial-target handoff | `apntalk/laravel-freeswitch-esl` maps resolved transport/endpoint context into `apntalk/esl-react` prepared input |
| Direct transport polling handoff | Deferred until `apntalk/esl-react` exposes that public path |
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
3. `drain()` — enters bounded drain mode, records drain start/deadline metadata, and snapshots replay-backed drain checkpoints while waiting for Laravel-owned inflight bookkeeping to settle or timeout
4. `shutdown()` — persists the conservative terminal checkpoint state when needed, then cleans up and returns

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
`WorkerStatus::isRuntimePushObserved()` answers whether that feedback arrived through the upstream push listener rather than Laravel polling a snapshot.
`WorkerStatus::isRuntimeLoopActive()` answers whether that feedback reports a live runtime. On the supported `apntalk/esl-react` `^0.2.10` line, Laravel maps the upstream `RuntimeRunnerHandle::statusSnapshot()` into status metadata and still subscribes to `RuntimeRunnerHandle::onLifecycleChange()` so push-delivered updates refresh that runtime-owned snapshot. The surfaced metadata now includes runtime status phase, active/recovery posture, reconnect attempts, last heartbeat observation, last successful authenticated connect time, last disconnect time/reason, and last recorded failure summary/time.
These fields mean “boot prepared the runtime handoff seam,” “the configured runner was invoked,” and “Laravel observed upstream runner lifecycle state.” They do not give Laravel ownership of reconnect, heartbeat, or session lifecycle behavior.

When `freeswitch:worker` runs a real worker scope and upstream runtime-status truth is available, Laravel now also projects a bounded `HealthSnapshot` from the per-node `WorkerStatus` and persists the latest runtime-linked health facts through `HealthReporter`. That DB-backed path stores only the latest known upstream runtime-status phase, active/recovery posture, connect/disconnect timestamps and reasons, failure summary, and linkage basis. Human-readable `freeswitch:health` can now render a small runtime-linked facts section from that stored snapshot plus an age/staleness hint derived from the stored snapshot timestamp. It does not create a broader live-status history store and does not claim reconnect completion, session continuity restoration, or global event-loop liveness.
The package now also exposes optional JSON HTTP health routes over that same persisted model: `GET /freeswitch-esl/health`, `GET /freeswitch-esl/health/live`, and `GET /freeswitch-esl/health/ready`. These routes report bounded DB-backed summary/readiness/liveness posture only; they do not imply current live runtime ownership or reconnect completion.

### Inflight limits and backpressure

`freeswitch-esl.drain_defaults.max_inflight` is now load-bearing in
`WorkerRuntime`.

Current behavior:
- `beginInflightWork()` rejects new work when accepting it would exceed the
  configured `max_inflight`
- `beginInflightWork()` also rejects new work once drain mode has started
- rejected work fails closed through `WorkerException`
- completing inflight work re-opens capacity immediately

Current status metadata includes:
- `max_inflight`
- `backpressure_active`
- `backpressure_limit_reached`
- `backpressure_reason`
- `backpressure_rejected_total`
- `backpressure_last_rejected_at`

This is intentionally bounded package-owned flow control. It is not a full
queueing, dead-letter, or scheduler architecture.

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

After startup, `freeswitch:worker` reports how many node runtimes reached the prepared-handoff state, how many invoked the configured runner, how many exposed push-based lifecycle observation, and how many reported a running live runtime through the feedback seam. That summary is intentionally narrow and does not claim Laravel owns the `apntalk/esl-react` session lifecycle.

`freeswitch:worker` now also renders a small human-readable operator posture
summary:
- configured metrics driver
- how many prepared nodes currently report bounded backpressure
- how many nodes are in a drain posture

Each node line now includes bounded backpressure wording and a concise
operator-action hint. Those hints are intentionally conservative:
- `none` when the package sees no bounded drain/backpressure issue
- `let inflight work settle before adding work` when drain has started
- `reduce inflight load or raise max_inflight deliberately` when the configured
  inflight limit is being enforced
- `investigate stuck inflight work before restarting` when drain timed out

Those hints do not imply ownership of reconnect, resume execution, or any
broader queueing/dead-letter system.

The command now also prints one bounded replay-backed checkpoint/recovery posture line per node runtime. Those lines report persisted-artifact checkpoint scope, last checkpoint reason/timestamp, whether startup observed a prior checkpoint, whether a bounded replay-backed recovery candidate was found, and current drain posture. They are explicitly not a claim of live socket recovery, reconnect restoration, resume execution, or automatic resume processing.

For automation and stable machine-readable reporting, `freeswitch:worker --json` emits a JSON document that reuses the same `WorkerStatus`-derived checkpoint/drain metadata and now includes additive bounded resume-posture fields derived from the existing replay-backed checkpoint/recovery facts.

For reporting-only automation, `freeswitch:worker:status` now prepares worker runtimes without invoking the bound runtime runner and returns a multi-worker-friendly JSON document built from the same `WorkerStatus` metadata. That surface is observational only: it reports replay-backed checkpoint posture, additive resume-posture fields, and drain state, but does not claim live recovery, reconnect restoration, resume execution, or automatic resume processing.

For persisted checkpoint history rather than prepared runtime posture, `freeswitch:worker:checkpoint-status` now emits a dedicated machine-readable historical summary. It reports latest checkpoint posture per worker/node/profile scope, can optionally include a bounded history window, and now supports additive DB-backed filters plus stable `limit`/`offset` pagination for larger result sets. It also exposes bounded historical pruning posture when that can be derived truthfully from the installed replay store and persisted checkpoint/query state, plus additive top-level retention-policy metadata for the current invocation, including the active upstream support path when one exists. It does not claim that historical checkpoint posture equals current live runtime state, does not execute pruning, and does not imply pruning coordination for active workers.

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

The current adapter supports the default TCP path and now passes an explicit prepared dial URI when the resolved `ConnectionContext` requires a non-default target, including `tls://host:port` for TLS-style handoffs on the supported `apntalk/esl-react` `^0.2.10` line. Direct `apntalk/esl-core` `TransportInterface` polling handoff remains deferred until `apntalk/esl-react` exposes that public path.

The adapter exposes runner feedback from `RuntimeRunnerHandle::statusSnapshot()` on the supported `apntalk/esl-react` `^0.2.10` line and registers `RuntimeRunnerHandle::onLifecycleChange()` so Laravel can refresh cached runtime-owned status truth as the upstream lifecycle changes. Laravel maps that upstream read-only runtime status truth into `WorkerStatus::meta`; it does not reconnect the session, manage heartbeats, execute replay, or own bgapi/event runtime semantics.

This repository now also includes a deterministic simulated ESL server harness
in its integration test suite. That harness exercises connect/auth,
subscription seeding, disconnect observation, reconnect observation, and
Laravel-owned drain posture through the current package boundary without
re-implementing upstream runtime logic in this package.

For future accepted-stream/listener-backed adapters, the upstream `InboundConnectionFactory` is now the preferred bootstrap seam. Binding that seam here does not imply listener or runtime ownership in this package.

The `WorkerRuntime` will remain the assignment-aware orchestration layer; `esl-react` handles the async loop.

---

## Checkpointing and replay integration

Replay capture wiring is now implemented through `apntalk/esl-replay`.
The current worker/runtime posture remains intentionally conservative:

- replay artifacts are persisted with worker/node/profile identity
- worker checkpoints are written through the upstream checkpoint repository and bounded identity references
- worker runtime can save bounded interval-based `periodic` checkpoints after the runtime runner has been invoked and the configured checkpoint interval has elapsed
- `drain()` records `drain_started_at`, `drain_deadline_at`, and saves a `drain-requested` checkpoint
- terminal drain transitions save a second checkpoint with reason `drain-completed` or `drain-timeout`
- startup can report bounded checkpoint-backed recovery hints by looking up the prior checkpoint and checking for later persisted replay records using bounded replay criteria

This does not mean:

- live FreeSWITCH socket recovery
- `apntalk/esl-react` session continuity recovery
- replay execution or re-injection
- a background checkpoint scheduler or timer loop

Configuration: `freeswitch-esl.replay.*`
Configuration: `freeswitch-esl.worker_defaults.checkpoint_interval_seconds`
