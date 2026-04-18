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
- **Load-bearing metrics drivers** — shipped log-backed and Laravel event-backed metrics recorders, with structured logging enabled by default
- **Optional HTTP health integration** — JSON health, readiness, and liveness posture routes over the same DB-backed health snapshot model
- **Replay-backed integration** — upstream replay capture/store wiring, inspection, bounded checkpoint/drain coordination, and bounded interval-based periodic checkpoints

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

'observability' => [
    'metrics' => [
        'driver' => 'log', // log | event | null
        'log_level' => 'info',
    ],
],

'http' => [
    'health' => [
        'enabled' => true,
        'prefix' => 'freeswitch-esl/health',
    ],
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
| `freeswitch:worker:status` | Report machine-readable prepared runtime status for one or more worker scopes |
| `freeswitch:worker:checkpoint-status` | Report machine-readable historical checkpoint posture for one or more worker scopes, with additive filters, stable pagination, bounded pruning posture, and retention-support metadata |
| `freeswitch:health` | Show health snapshots, with `--summary` for a bounded aggregate DB-backed summary |
| `freeswitch:replay:inspect` | Inspect replay capture store |
| `freeswitch:validate-install` | Validate config/schema/bindings/commands, with optional example-seed checks, without live ESL |

HTTP health routes:

| Route | Description |
|---|---|
| `GET /freeswitch-esl/health` | JSON DB-backed health summary with snapshot list |
| `GET /freeswitch-esl/health/live` | JSON bounded liveness posture over the latest DB-backed snapshots |
| `GET /freeswitch-esl/health/ready` | JSON bounded readiness posture over the latest DB-backed snapshots |

---

## Architecture

See `docs/architecture.md` for the full architectural overview.
See `docs/health-model.md` for the bounded DB-backed health/readiness/liveness model.

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
- `0.4.x` runtime observation is in place through the default `apntalk/esl-react` runner binding, upstream `RuntimeRunnerHandle::statusSnapshot()`, and push-based `RuntimeRunnerHandle::onLifecycleChange()`
- `0.5.x` replay integration is in place through `apntalk/esl-replay`, including bounded checkpoint/reporting surfaces and runtime-linked DB-backed health snapshots
- `0.6.x` hardening is now in place for truthful docs, shipped metrics drivers, load-bearing `max_inflight` enforcement, a deterministic simulated ESL lifecycle harness, and a near-runnable example app cookbook

The package is currently usable for:
- Control-plane setup (DB-backed PBX inventory)
- Connection parameter resolution and validation
- Worker assignment resolution and boot orchestration
- Health snapshot inspection
- `apntalk/esl-core` command/pipeline/event-bridge integration inside Laravel
- Laravel bridge-wrapper events with an explicit `schemaVersion` field for downstream schema tracking
- stable upstream transport and accepted-stream bootstrap seams bound for future runtime adapters
- a Laravel-owned runtime handoff contract that adapters can consume without re-resolving control-plane state, with `ConnectionFactoryInterface` now typed to that boundary
- a Laravel-owned runtime runner seam that `WorkerRuntime::run()` invokes; the default binding adapts to `apntalk/esl-react`, while `non-live` remains available as a fallback/dry-run runner
- real upstream runtime-status observation on the supported `apntalk/esl-react` `^0.2.10` line for live connection/session/liveness/reconnect/drain status reporting on the same public runner seam, with validation/stability truth continuing to come from upstream `apntalk/esl-react`
- richer prepared dial-target handoff into `apntalk/esl-react`, including explicit TLS-style dial URIs when the resolved `ConnectionContext` requires them
- shipped metrics drivers with structured logging enabled by default and Laravel event dispatch available as an alternative sink
- real load-bearing inflight/backpressure enforcement through `freeswitch-esl.drain_defaults.max_inflight`
- deterministic lifecycle verification using a simulated ESL server harness that exercises connect, subscribe, disconnect, reconnect observation, and drain posture through the package boundary

Current publication target:

- final `0.6.0`
- validated runtime baseline: `v0.6.0-rc2` at `8602d3fed7f12d829d12538631b35503ac2410ba`
- later final-release preparation is docs-only and keeps the RC2 runtime evidence applicable to the publish candidate
- a near-runnable example app cookbook under `examples/laravel-app`

Still deferred:
- Laravel-owned runtime supervision, reconnect/backoff ownership, and heartbeat/session lifecycle ownership
- replay execution/re-injection and live-session recovery from replay checkpoints
- stronger live-process liveness guarantees than the latest DB-backed health snapshot model can prove
- a higher-level Laravel-native normalized domain-event layer beyond the shipped
  wrapper events around upstream esl-core payloads
- outbound server-mode support; the current package scope and `1.0` horizon are
  explicitly inbound-client-only

`WorkerRuntime::run()` now invokes the Laravel-owned `RuntimeRunnerInterface` seam. By default, the package maps `RuntimeHandoffInterface` into `apntalk/esl-react`'s `PreparedRuntimeBootstrapInput` and calls the upstream runner. On the supported `apntalk/esl-react` `^0.2.10` line, Laravel consumes `RuntimeRunnerHandle::statusSnapshot()` and registers `RuntimeRunnerHandle::onLifecycleChange()` so machine-readable worker and health-adjacent surfaces can reflect runtime-owned phase, reconnect posture, connect/disconnect observation, and failure summaries without claiming reconnect or resume execution ownership. Laravel can also pass an explicit prepared dial URI when the resolved transport requires it. Reconnect, heartbeat, session lifecycle, bgapi/event runtime semantics, and broader runtime supervision remain owned by the bound runner.

Current worker status semantics:
- `booting` means handoff state is not yet prepared
- `running` means boot completed and the runtime handoff seam is prepared
- `running` does not by itself mean a live async ESL loop is active; check `runtime_loop_active` / `isRuntimeLoopActive()` for upstream-feedback-derived live observation
- drain/checkpoint metadata is conservative and replay-backed; it can report bounded checkpoint save/resume/recovery posture, but it does not claim live `apntalk/esl-react` session recovery

Operator output posture:
- `freeswitch:worker` renders bounded replay-backed checkpoint/recovery hints per node runtime
- `freeswitch:worker` and `freeswitch:worker:status` now expose bounded backpressure metadata such as `max_inflight`, `backpressure_active`, and rejection posture
- `freeswitch:worker` also renders a small human-readable operator posture summary for the configured metrics driver, bounded backpressure counts, and per-node action wording for drain or overload posture
- `freeswitch:worker --json` exposes the same bounded checkpoint/recovery posture in a machine-readable form for automation and now includes additive resume-posture fields without implying resume execution
- `freeswitch:worker:status` provides a dedicated machine-readable reporting surface that prepares worker runtimes without invoking the bound runtime runner, can report multiple DB-backed worker scopes in one call, and carries the same additive resume-posture fields
- `freeswitch:worker:checkpoint-status` provides a dedicated machine-readable historical checkpoint summary surface over persisted worker/node/profile checkpoint posture, with bounded optional history entries, additive filters, stable `limit`/`offset` pagination, additive historical pruning-posture fields when those can be derived truthfully from the upstream filesystem retention planner, and additive top-level retention-policy/support-basis metadata for the current invocation
- `freeswitch:health` remains a DB-backed health snapshot surface, now with an optional bounded aggregate `--summary` posture; the human-readable output shows the configured metrics driver, can render bounded backpressure snapshot facts when present, and when a real `freeswitch:worker` run records upstream runtime-status facts it can also show a small runtime-linked facts section with the latest persisted phase, active/recovery posture, connect/disconnect, failure summary, and a bounded snapshot-age hint derived from the stored snapshot timestamp, but the command still does not claim worker recovery visibility, reconnect ownership, or broader live-runtime guarantees
- `freeswitch:status` remains a control-plane inventory surface and explicitly does not claim worker recovery visibility

---

## Testing

```bash
composer test
```

The test suite uses SQLite in-memory and does not require a live PBX.

For a bounded local install/adoption check without live ESL, run:

```bash
php artisan freeswitch:validate-install
php artisan freeswitch:validate-install --example
```

For the current `0.6.x` release-candidate framing and upgrade notes, see
`docs/releases/0.6.0-rc2.md`.

### Optional private-environment live validation

The repository includes one optional helper workflow at
`.github/workflows/live-smoke.yml`, but it is not the normal or required
release-validation path for this package.

Real FreeSWITCH validation for this repository is expected to be run by a
maintainer from a private-network environment that can actually reach the
target ESL endpoint.

Validation posture:

- read-only and bounded to connect/auth/`api status`/event subscription checks
- operator-run or private-runner-run, not part of the default public CI gate
- environment-specific and external to deterministic package release evidence
- does not change runtime ownership or turn this package into the owner of live
  ESL supervision
- uses the direct upstream `apntalk/esl-core` smoke helper path on `v0.2.8+`;
  no downstream proxy/workaround is required

For the exact private validation procedure and retained evidence shape, use
`docs/releases/0.6.0-private-live-validation-runbook.md`.

Quick operator path from a private-network checkout:

```bash
cp .env.private-validation.example .env.private-validation
php bin/freeswitch-private-live-validate.php --env-file=.env.private-validation --dry-run
php bin/freeswitch-private-live-validate.php --env-file=.env.private-validation
```

## Metrics and observability

The package now ships real metrics recorder implementations:

- `log` — default; emits structured metric records through Laravel's logger
- `event` — dispatches Laravel `MetricsRecorded` events for app-level forwarding
- `null` — explicit no-op fallback for environments that want silence

Override the default by setting `FREESWITCH_ESL_METRICS_DRIVER`, or rebind
`MetricsRecorderInterface` in your application if you need a custom sink.

---

## License

MIT
