# Public API

The stable public API surface of `apntalk/laravel-freeswitch-esl` is defined here.
For the full compatibility and deprecation policy, see `docs/compatibility-policy.md`.

---

## Stable public surfaces (current 0.3.x runtime-prep checkpoint)

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
| `RuntimeRunnerFeedbackProviderInterface` | Optional observation seam for runners that expose coarse lifecycle feedback |
| `WorkerInterface` | Worker boot/run/drain/shutdown lifecycle |
| `WorkerAssignmentResolverInterface` | Assignment scope resolution |
| `HealthReporterInterface` | Structured health snapshot contract |
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
| `RuntimeRunnerFeedback` | Coarse runner feedback snapshot consumed by `WorkerStatus::meta` without owning runtime lifecycle |
| `WorkerStatus` | Worker operational state snapshot; helper methods and `meta` distinguish handoff-prepared, adapter-ready, runner-invoked, and runtime-active state |
| `HealthSnapshot` | PBX node health at a point in time |

### Laravel integration

| Surface | Notes |
|---|---|
| `FreeSwitchEslServiceProvider` | Service provider class name is stable |
| All registered container bindings | Stable (interface → implementation map) |
| Config key `freeswitch-esl` | Config shape is stable; see `config/freeswitch-esl.php` |
| All artisan command signatures | `freeswitch:ping`, `freeswitch:status`, `freeswitch:worker`, `freeswitch:health`, `freeswitch:replay:inspect` |
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
| `EslReactRuntimeBootstrapInputFactory` | Maps Laravel `RuntimeHandoffInterface` bundles into `apntalk/esl-react` prepared bootstrap input |
| `EslReactRuntimeRunnerAdapter` | Invokes the upstream `apntalk/esl-react` runner behind Laravel's `RuntimeRunnerInterface` |
| `EslEventReceived` | Laravel event carrying typed event, normalized event, and `ConnectionContext` |
| `EslReplyReceived` | Laravel event carrying typed reply and `ConnectionContext` |
| `EslDisconnected` | Laravel event carrying disconnect notice context |

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
| `src/Contracts/Upstream/ReplayCaptureStoreInterface` | `@internal` replay stub, will be removed when `apntalk/esl-replay` is integrated |
| Eloquent model internals | Relationships and scopes may change |
| `WorkerRuntime` internal methods | Implementation detail; only `WorkerInterface` methods are stable |
| `WorkerSupervisor` internal methods | Implementation detail |
| Service implementation internals | Only the interface contract is stable |

Current worker/runtime posture notes:
- `WorkerInterface::run()` is a stable contract; current shipped implementations invoke the configured runtime runner.
- `WorkerStatus::state = running` currently means boot completed and runtime handoff prepared; use `WorkerStatus::isHandoffPrepared()`, `isRuntimeRunnerInvoked()`, `isRuntimeFeedbackObserved()`, and `isRuntimeLoopActive()` to distinguish prepared scaffolding, seam invocation, observed runner feedback, and Laravel-observed live async session state.

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
