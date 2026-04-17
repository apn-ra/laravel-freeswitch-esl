# apntalk/laravel-freeswitch-esl

Laravel integration and multi-PBX control plane for the APNTalk ESL package family.

---

## What this package is

A production-grade Laravel package that provides:

- **Database-backed PBX inventory** — nodes, providers, profiles stored in the DB
- **Provider-aware connection resolution** — FreeSWITCH is the first driver, not the only model
- **Worker assignment orchestration** — target one node, a cluster, a tag, or all active nodes
- **Long-lived worker bootstrapping** — explicit boot/run/drain/shutdown scaffolding, with live async runtime behavior still deferred to `apntalk/esl-react`
- **Structured health and diagnostics** — machine-usable operational state per node
- **Replay inspection scaffolding** — config surface and operator inspection command for future `apntalk/esl-replay` integration

## What this package is not

- A single `.env` PBX connection wrapper
- A facade over hidden global runtime state
- An owner of ESL protocol internals (those belong in `apntalk/esl-core`)
- An owner of async runtime primitives (those belong in `apntalk/esl-react`)
- An owner of replay primitives (those belong in `apntalk/esl-replay`)

---

## Package family

This package is part of the APNTalk ESL package family:

| Package | Role |
|---|---|
| `apntalk/esl-core` | Protocol/core ESL contracts, parsing, typed events |
| `apntalk/esl-react` | ReactPHP async runtime and reconnect lifecycle |
| `apntalk/esl-replay` | Replay-safe capture, envelopes, projectors |
| `apntalk/laravel-freeswitch-esl` | Laravel integration and multi-PBX control plane |

---

## Requirements

- PHP 8.3+
- Laravel 11 or 12

---

## Installation

```bash
composer require apntalk/laravel-freeswitch-esl
```

Publish config and migrations:

```bash
php artisan vendor:publish --tag=freeswitch-esl-config
php artisan vendor:publish --tag=freeswitch-esl-migrations
php artisan migrate
```

---

## Quick start

### 1. Seed a provider and node

```php
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;

$provider = PbxProvider::create([
    'code'         => 'freeswitch',
    'name'         => 'FreeSWITCH',
    'driver_class' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
    'is_active'    => true,
]);

PbxNode::create([
    'provider_id'         => $provider->id,
    'name'                => 'Primary FS',
    'slug'                => 'primary-fs',
    'host'                => '10.0.0.10',
    'port'                => 8021,
    'username'            => '',
    'password_secret_ref' => 'ClueCon',
    'transport'           => 'tcp',
    'is_active'           => true,
    'cluster'             => 'us-east',
    'tags_json'           => ['prod'],
]);
```

### 2. Verify resolution

```bash
php artisan freeswitch:ping --pbx=primary-fs
php artisan freeswitch:status
php artisan freeswitch:health
```

### 3. Start a worker

```bash
# Single node
php artisan freeswitch:worker --pbx=primary-fs

# All nodes in us-east cluster
php artisan freeswitch:worker --cluster=us-east

# All active nodes
php artisan freeswitch:worker --all-active
```

---

## Configuration

Published config: `config/freeswitch-esl.php`

Key settings:

```php
'default_driver' => 'freeswitch',

'drivers' => [
    'freeswitch' => FreeSwitchDriver::class,
],

'secret_resolver' => [
    'mode' => 'plaintext', // plaintext | env | custom
],

'retry_defaults' => [
    'max_attempts'    => 5,
    'initial_delay_ms' => 1000,
    'backoff_factor'  => 2.0,
    'max_delay_ms'    => 60000,
],

'health' => [
    'heartbeat_timeout_seconds' => 60,
],

'replay' => [
    'enabled' => false,
],
```

---

## Artisan commands

| Command | Description |
|---|---|
| `freeswitch:ping --pbx=<slug>` | Resolve and display connection parameters |
| `freeswitch:status` | Show PBX node inventory |
| `freeswitch:status --cluster=<name>` | Filter by cluster |
| `freeswitch:worker --pbx=<slug>` | Start worker for one node |
| `freeswitch:worker --cluster=<name>` | Start worker for a cluster |
| `freeswitch:worker --tag=<name>` | Start worker for nodes with tag |
| `freeswitch:worker --provider=<code>` | Start worker for all provider nodes |
| `freeswitch:worker --all-active` | Start worker for all active nodes |
| `freeswitch:health` | Show health snapshots |
| `freeswitch:replay:inspect` | Inspect replay capture store |

---

## Architecture

See `docs/architecture.md` for the full architectural overview.

The core flow:

```
Laravel app
  → PbxRegistryInterface         (DB-backed node inventory)
  → ConnectionResolverInterface  (node + profile + secret + driver → ConnectionContext)
  → ConnectionFactoryInterface   (ConnectionContext → RuntimeHandoffInterface)
  → WorkerSupervisor             (multi-node orchestration)
  → WorkerRuntime                (per-node worker session + retained handoff state)
  → RuntimeRunnerInterface       (Laravel handoff → apntalk/esl-react runner input)
  → apntalk/esl-react            (async ESL runtime ownership)
```

---

## Development status

Current repository posture:
- `0.1.x` foundation is in place for the Laravel package, DB-backed control plane, worker scaffolding, and operator commands
- `0.2.x` integration is in place for stable `apntalk/esl-core` transport/bootstrap, command, pipeline, and Laravel event-bridge seams
- `0.3.x` runtime-prep work is in place for adapter-facing runtime handoff bundles, runner seams, and truthful worker/runtime reporting
- `0.4.x` lifecycle observation is in place through the default `apntalk/esl-react` runner binding and upstream `RuntimeRunnerHandle::lifecycleSnapshot()`

The package is currently usable for:
- Control-plane setup (DB-backed PBX inventory)
- Connection parameter resolution and validation
- Worker assignment resolution and boot orchestration
- Health snapshot inspection
- `apntalk/esl-core` command/pipeline/event-bridge integration inside Laravel
- stable upstream transport and accepted-stream bootstrap seams bound for future runtime adapters
- a Laravel-owned runtime handoff contract that adapters can consume without re-resolving control-plane state, with `ConnectionFactoryInterface` now typed to that boundary
- a Laravel-owned runtime runner seam that `WorkerRuntime::run()` invokes; the default binding adapts to `apntalk/esl-react`, while `non-live` remains available as a fallback/dry-run runner
- real upstream lifecycle snapshot observation on the supported `apntalk/esl-react` `^0.2.1` line for live connection/session/liveness/reconnect/drain status reporting
- richer prepared dial-target handoff into `apntalk/esl-react`, including explicit TLS-style dial URIs when the resolved `ConnectionContext` requires them

Still deferred:
- Laravel-owned runtime supervision, reconnect/backoff ownership, and heartbeat/session lifecycle ownership
- Replay capture/store integration via `apntalk/esl-replay`

`WorkerRuntime::run()` now invokes the Laravel-owned `RuntimeRunnerInterface` seam. By default, the package maps `RuntimeHandoffInterface` into `apntalk/esl-react`'s `PreparedRuntimeBootstrapInput` and calls the upstream runner. On the supported `apntalk/esl-react` `^0.2.1` line, Laravel consumes `RuntimeRunnerHandle::lifecycleSnapshot()` end to end for status reporting and can pass an explicit prepared dial URI when the resolved transport requires it. Reconnect, heartbeat, and session lifecycle remain owned by the bound runner.

Current worker status semantics:
- `booting` means handoff state is not yet prepared
- `running` means boot completed and the runtime handoff seam is prepared
- `running` does not by itself mean a live async ESL loop is active; check `runtime_loop_active` / `isRuntimeLoopActive()` for upstream-feedback-derived live observation

---

## Testing

```bash
composer test
```

The test suite uses SQLite in-memory and does not require a live PBX.

---

## License

MIT
