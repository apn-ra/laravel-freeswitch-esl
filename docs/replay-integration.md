# Replay Integration

## Ownership model

`apntalk/laravel-freeswitch-esl` does NOT own replay primitives. It wires them into Laravel.

| Responsibility | Owner |
|---|---|
| `ReplayCaptureStoreInterface` (canonical) | `apntalk/esl-replay` |
| `ReplayEnvelope` | `apntalk/esl-replay` |
| `ReplayProjector` | `apntalk/esl-replay` |
| `ReplayScenarioRunner` | `apntalk/esl-replay` |
| `ReplayCursor` | `apntalk/esl-replay` |
| Laravel storage binding | `apntalk/laravel-freeswitch-esl` (this package) |
| Retention policy configuration | `apntalk/laravel-freeswitch-esl` |
| `freeswitch:replay:inspect` command | `apntalk/laravel-freeswitch-esl` |
| Session/correlation metadata propagation | `apntalk/laravel-freeswitch-esl` |

---

## Enabling replay capture

Replay is disabled by default.

```php
// config/freeswitch-esl.php
'replay' => [
    'enabled'        => true,
    'store_driver'   => 'database',
    'retention_days' => 7,
],
```

Or via `.env`:
```env
FREESWITCH_ESL_REPLAY_ENABLED=true
FREESWITCH_ESL_REPLAY_RETENTION_DAYS=7
```

---

## Replay store binding

When `apntalk/esl-replay` is installed, bind the canonical store in your `AppServiceProvider`:

```php
use ApnTalk\EslReplay\Contracts\ReplayCaptureStoreInterface;
use ApnTalk\EslReplay\Stores\DatabaseReplayCaptureStore;

public function register(): void
{
    $this->app->singleton(
        \ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream\ReplayCaptureStoreInterface::class,
        fn ($app) => new DatabaseReplayCaptureStore(...)
    );
}
```

Until `apntalk/esl-replay` is available, the stub interface in `Contracts\Upstream\` serves as the integration point.

---

## Replay partitioning

Replay data is partitioned by:
- **Provider** — provider code (e.g. `freeswitch`)
- **PBX node** — `pbx_node_slug`
- **Worker session** — `worker_session_id`
- **Time window** — `from` / `to` timestamps

This partitioning makes replay data queryable per-node for targeted debugging.

---

## Inspection command

```bash
# Show recent envelopes for all nodes
php artisan freeswitch:replay:inspect

# Show envelopes for a specific node
php artisan freeswitch:replay:inspect --pbx=primary-fs

# Time-windowed query
php artisan freeswitch:replay:inspect --from="2024-01-01T00:00:00" --to="2024-01-01T01:00:00"

# JSON output for scripting
php artisan freeswitch:replay:inspect --json
```

---

## Session metadata propagation

Each captured envelope carries runtime identity:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `worker_session_id`
- `connection_profile_name`
- `captured_at` timestamp

This metadata is populated by the Laravel-side wiring, not by `apntalk/esl-replay` primitives.
