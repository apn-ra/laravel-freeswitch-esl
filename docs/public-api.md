# Public API

The stable public API surface of `apntalk/laravel-freeswitch-esl` is defined here.
For the full compatibility and deprecation policy, see `docs/compatibility-policy.md`.
For bounded health snapshot/readiness/liveness semantics, see `docs/health-model.md`.

---

## Stable public surfaces (current 0.6.x hardening checkpoint)

### Contracts (`src/Contracts/`)

All interfaces in `src/Contracts/` are stable public API **except** `src/Contracts/Upstream/`
which is explicitly `@internal`.

| Interface | Purpose |
|---|---|
| `PbxRegistryInterface` | Multi-PBX node inventory lookups |
| `ProviderDriverRegistryInterface` | Provider driver map and resolution |
| `ProviderDriverInterface` | Contract for PBX provider drivers |
| `ConnectionResolverInterface` | Full resolution pipeline |
| `ConnectionFactoryInterface` | Runtime handoff factory returning the Laravel-owned `RuntimeHandoffInterface` boundary |
| `RuntimeHandoffInterface` | Adapter-facing prepared runtime bundle contract for runtime integrations |
| `RuntimeRunnerInterface` | Laravel-owned runtime runner contract that `WorkerRuntime::run()` invokes |
| `RuntimeRunnerFeedbackProviderInterface` | Observation seam for runners that expose Laravel-consumable lifecycle feedback |
| `WorkerInterface` | Worker boot/run/drain/shutdown lifecycle |
| `WorkerAssignmentResolverInterface` | Assignment scope resolution |
| `HealthReporterInterface` | Structured health snapshot contract |
| `MetricsRecorderInterface` | Laravel-facing metrics hook contract with shipped `log`, `event`, and `null` drivers |
| `SecretResolverInterface` | Credential resolution contract |

### Value objects (`src/ControlPlane/ValueObjects/`)

All value objects are stable public API:

| Class | Description |
|---|---|
| `PbxProvider` | Immutable provider family identity |
| `PbxNode` | Immutable PBX endpoint identity with runtime identity fields |
| `ConnectionProfile` | Immutable operational policy VO |
| `WorkerAssignment` | Immutable worker targeting scope (5 modes) |
| `ConnectionContext` | Fully resolved connection parameters (use `toLogContext()` for safe logging) |
| `RuntimeRunnerFeedback` | Runner feedback snapshot consumed by `WorkerStatus::meta`; maps upstream `RuntimeRunnerHandle` runtime status truth without owning runtime lifecycle |
| `WorkerStatus` | Worker operational state snapshot; helper methods and `meta` distinguish handoff-prepared, adapter-ready, runner-invoked, snapshot/push-observed lifecycle state, and runtime-active state |
| `HealthSnapshot` | PBX node health at a point in time |

### Laravel integration

| Surface | Notes |
|---|---|
| `FreeSwitchEslServiceProvider` | Service provider class name is stable |
| All registered container bindings | Stable (interface → implementation map) |
| Config key `freeswitch-esl` | Config shape is stable; see `config/freeswitch-esl.php` |
| HTTP health routes | `GET /freeswitch-esl/health`, `GET /freeswitch-esl/health/live`, `GET /freeswitch-esl/health/ready` |
| All artisan command signatures | `freeswitch:ping`, `freeswitch:status`, `freeswitch:worker`, `freeswitch:worker:status`, `freeswitch:worker:checkpoint-status`, `freeswitch:health`, `freeswitch:replay:inspect` |
| DB migration table names | `pbx_providers`, `pbx_nodes`, `pbx_connection_profiles`, `worker_assignments` |
| DB column names | Stable; see `database/migrations/` |

### esl-core integration surfaces

These surfaces are now shipped and should be treated as public package surfaces unless and until they are explicitly moved under an internal namespace:

| Class | Purpose |
|---|---|
| `EslCoreConnectionFactory` | Creates the current `RuntimeHandoffInterface` bundle from `ConnectionContext` |
| `EslCoreConnectionHandle` | Current `RuntimeHandoffInterface` implementation carrying resolved context, esl-core pipeline, and boot command sequences |
| `EslCoreCommandFactory` | Builds typed `apntalk/esl-core` command objects from Laravel-owned inputs |
| `EslCorePipelineFactory` | Creates per-session inbound decode pipelines |
| `EslCoreEventBridge` | Dispatches decoded esl-core messages into Laravel events |
| `EslReactRuntimeBootstrapInputFactory` | Maps Laravel `RuntimeHandoffInterface` bundles into `apntalk/esl-react` prepared bootstrap input, including explicit dial URIs for non-default transports on the supported upstream line |
| `EslReactRuntimeRunnerAdapter` | Invokes the upstream `apntalk/esl-react` runner behind Laravel's `RuntimeRunnerInterface` |
| `EslEventReceived` | Laravel event carrying typed event, normalized event, and `ConnectionContext` |
| `EslReplyReceived` | Laravel event carrying typed reply and `ConnectionContext` |
| `EslDisconnected` | Laravel event carrying disconnect notice context |
| `MetricsRecorded` | Laravel event emitted by the shipped event-backed metrics recorder |

Current Laravel bridge event schema posture:
- `EslEventReceived::SCHEMA_VERSION = "1.0"`
- `EslReplyReceived::SCHEMA_VERSION = "1.0"`
- `EslDisconnected::SCHEMA_VERSION = "1.0"`

Stable upstream seams bound in the Laravel container:
- `Apntalk\EslCore\Contracts\TransportFactoryInterface` → `SocketTransportFactory`
- `Apntalk\EslCore\Contracts\InboundConnectionFactoryInterface` → `InboundConnectionFactory`
- `Apntalk\EslReact\Contracts\RuntimeRunnerInterface` → `AsyncEslRuntime::runner()`

Runtime runner binding:
- `ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface` resolves to the `apntalk/esl-react` adapter by default.
- Set `freeswitch-esl.runtime.runner = non-live` to retain the no-op fallback runner for dry-run or unsupported environments.

---

## Internal / non-stable surfaces

| Surface | Notes |
|---|---|
| `WorkerReplayCheckpointManager` internals | Laravel integration detail over upstream replay checkpoint/query APIs |
| Eloquent model internals | Relationships and scopes may change |
| `WorkerRuntime` internal methods | Implementation detail; only `WorkerInterface` methods are stable |
| `WorkerSupervisor` internal methods | Implementation detail |
| Service implementation internals | Only the interface contract is stable |

Current worker/runtime posture notes:
- `WorkerInterface::run()` is a stable contract; current shipped implementations invoke the configured runtime runner.
- `WorkerStatus::state = running` currently means boot completed and runtime handoff prepared; use `WorkerStatus::isHandoffPrepared()`, `isRuntimeRunnerInvoked()`, `isRuntimeFeedbackObserved()`, and `isRuntimeLoopActive()` to distinguish prepared scaffolding, seam invocation, observed runner feedback, and upstream runtime-status-derived live async session state.
- worker drain/checkpoint status is surfaced conservatively through `WorkerStatus::meta`, including bounded replay-backed checkpoint save/resume hints, additive periodic checkpoint metadata, and bounded drain completion/timeout fields; this does not imply live session recovery or replay execution
- `freeswitch:worker` now renders those bounded replay-backed checkpoint/recovery hints in human-readable operator output, `freeswitch:worker --json` exposes the same posture plus additive machine-readable resume-posture fields in a stable form, `freeswitch:worker:status` provides a dedicated reporting-oriented JSON surface that prepares runtimes without invoking the runtime runner and carries the same additive resume-posture fields, and `freeswitch:worker:checkpoint-status` provides a dedicated historical checkpoint summary surface over persisted checkpoint state with additive filters, stable `limit`/`offset` pagination, additive historical pruning-posture fields when those can be derived truthfully from upstream filesystem retention planning, and additive top-level retention-policy/support-basis metadata for the current invocation; `freeswitch:health --summary` exposes a bounded aggregate DB-backed health summary with conservative readiness/liveness posture, and human-readable `freeswitch:health` can now show a small runtime-linked facts section plus a bounded age/staleness hint derived from the stored snapshot timestamp when the snapshot contains selected upstream runtime-status facts from a real worker run, while `freeswitch:health` and `freeswitch:status` remain intentionally narrower and do not show live worker recovery posture
- the optional HTTP health routes reuse the same DB-backed `HealthReporter` model and expose JSON-only bounded summary, readiness posture, and liveness posture surfaces; they do not claim current live socket state, reconnect completion, or process/event-loop liveness beyond the latest persisted snapshot facts

---

## Extension points

### Adding a new provider driver

Implement `ProviderDriverInterface` and register in config:

```php
// config/freeswitch-esl.php
'drivers' => [
    'freeswitch'  => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
    'my-provider' => \App\PbxDrivers\MyProviderDriver::class,
],
```

### Custom secret resolver

Implement `SecretResolverInterface`, set `secret_resolver.mode = custom`, and
set `secret_resolver.resolver_class` to your FQCN.

### Custom health reporter

Bind your implementation against `HealthReporterInterface` after the service provider loads,
or extend `HealthReporter` and re-bind in your `AppServiceProvider`.

### Custom metrics recorder

The package ships three metrics drivers selected through
`freeswitch-esl.observability.metrics.driver`:

- `log` — default; emits structured log records
- `event` — dispatches `MetricsRecorded`
- `null` — explicit no-op fallback

Bind your own implementation against `MetricsRecorderInterface` after the
service provider loads if you need StatsD, Prometheus, OpenTelemetry, or
another sink.
