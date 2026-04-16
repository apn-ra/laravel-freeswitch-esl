# Worker Runtime

## Overview

The worker runtime is a first-class component of this package. It is not a convenience loop inside an Artisan command — it is the platform-level orchestration layer that manages long-lived ESL connections for one or more PBX nodes.

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
1. `boot()` — resolves connection context, sets state to `running`
2. `run()` — enters the event loop (delegates to `apntalk/esl-react` when available)
3. `drain()` — signals drain mode; stops accepting new work
4. `shutdown()` — cleans up resources and returns

Each `WorkerRuntime` carries a stable `sessionId` for log correlation and health reporting.

### WorkerSupervisor

Manages one or more `WorkerRuntime` instances for an assignment scope.

```
ApnTalk\LaravelFreeswitchEsl\Worker\WorkerSupervisor
```

The supervisor:
- resolves the target node set from the `WorkerAssignment`
- boots one `WorkerRuntime` per resolved node
- isolates node-level failures (one failing node does not abort the others)
- coordinates `drain()` and `shutdown()` across all runtimes

---

## Worker states

```
booting → running → draining → shutdown
                  ↘ failed
                  ↘ reconnecting (when esl-react is wired)
```

---

## Assignment modes

Start a worker via the `freeswitch:worker` command:

```bash
# Single node
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

The `--worker=<name>` option sets the worker identity (default: `esl-worker`). This name appears in logs, health snapshots, and can be looked up in `worker_assignments`.

---

## Node-level failure isolation

If one node fails to boot or encounters a runtime error, the supervisor logs the error and continues to the next node. This ensures that a single bad PBX node does not bring down workers targeting other nodes in the same scope.

---

## Graceful shutdown

Workers should handle POSIX signals (SIGTERM, SIGINT) to trigger `drain()` and then `shutdown()`.

Signal wiring is the application's responsibility. The package provides `WorkerInterface::drain()` and `WorkerInterface::shutdown()` to integrate with any signal-handling strategy.

---

## esl-react integration (future)

The current `WorkerRuntime::run()` body is a stub that logs and returns immediately. When `apntalk/esl-react` is available and required, it should be replaced with:

```php
// Inside WorkerRuntime::run():
$context = $this->connectionResolver->resolveForPbxNode($this->node)
    ->withWorkerSession($this->sessionId);

// Delegate to apntalk/esl-react runtime:
$this->reactRuntime->run($context, ...$runtimeOptions);
```

The `WorkerRuntime` will remain the assignment-aware orchestration layer; `esl-react` handles the async loop.

---

## Checkpointing and replay integration

When `apntalk/esl-replay` is wired and replay is enabled:
- each worker session registers with the replay capture store
- events are captured with session and node identity
- checkpoints are written periodically
- drain mode flushes pending captures before shutdown

Configuration: `freeswitch-esl.replay.*`
