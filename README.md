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
- **Replay inspection scaffolding** — config surface and operator command placeholders for future `apntalk/esl-replay` integration

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
  → PbxRegistryInterface        (DB-backed node inventory)
  → ConnectionResolverInterface (node + profile + secret + driver → ConnectionContext)
  → WorkerSupervisor            (multi-node orchestration)
  → WorkerRuntime               (per-node worker session)
  → [apntalk/esl-react]         (async ESL runtime — future wiring)
```

---

## Development status

Current repository posture:
- `0.1.x` foundation is in place for the Laravel package, DB-backed control plane, worker scaffolding, and operator commands
- `0.2.x` groundwork has partially landed via direct `apntalk/esl-core` integration for typed commands, inbound decoding, and Laravel event bridging

The package is currently usable for:
- Control-plane setup (DB-backed PBX inventory)
- Connection parameter resolution and validation
- Worker assignment resolution and boot orchestration
- Health snapshot inspection
- `apntalk/esl-core` command/pipeline/event-bridge integration inside Laravel

Still deferred:
- Live ESL runtime lifecycle via `apntalk/esl-react`
- Reconnect-safe long-lived worker behavior
- Replay capture/store integration via `apntalk/esl-replay`

`WorkerRuntime::run()` remains a stub until `apntalk/esl-react` is wired.

Current worker status semantics:
- `booting` means handoff state is not yet prepared
- `running` means boot completed and the runtime handoff seam is prepared
- `running` does not mean a live async ESL loop is active

---

## Testing

```bash
composer test
```

The test suite uses SQLite in-memory and does not require a live PBX.

---

## License

MIT
