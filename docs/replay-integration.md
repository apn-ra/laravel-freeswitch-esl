# Replay Integration

## Ownership model

`apntalk/laravel-freeswitch-esl` does NOT own replay primitives. In the current package posture it
only ships replay-oriented config and inspection scaffolding; actual replay wiring remains future work.

| Responsibility | Owner |
|---|---|
| `ReplayCaptureStoreInterface` (canonical) | `apntalk/esl-replay` |
| `ReplayEnvelope` | `apntalk/esl-replay` |
| `ReplayProjector` | `apntalk/esl-replay` |
| `ReplayScenarioRunner` | `apntalk/esl-replay` |
| `ReplayCursor` | `apntalk/esl-replay` |
| Laravel storage binding | `apntalk/laravel-freeswitch-esl` (planned `0.5.x`) |
| Retention policy configuration | `apntalk/laravel-freeswitch-esl` |
| `freeswitch:replay:inspect` command | `apntalk/laravel-freeswitch-esl` (current stub surface) |
| Session/correlation metadata propagation | `apntalk/laravel-freeswitch-esl` (planned `0.5.x`) |

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

**This section describes the target integration, not yet implemented.**
Replay capture wiring is planned for `0.5.x`.

The integration point is currently the stub interface:
`ApnTalk\LaravelFreeswitchEsl\Contracts\Upstream\ReplayCaptureStoreInterface` (`@internal`)

When `apntalk/esl-replay` is published and integrated:

1. The stub interface will be **removed** from `Contracts/Upstream/`.
2. The package will directly depend on the canonical `apntalk/esl-replay` contract.
3. The application binding will target the canonical upstream interface, not the stub.

At that point, in your `AppServiceProvider`:

```php
// Target type will be the canonical apntalk/esl-replay interface, e.g.:
// ApnTalk\EslReplay\Contracts\ReplayCaptureStoreInterface
// The exact namespace will be confirmed when apntalk/esl-replay is published.

public function register(): void
{
    $this->app->singleton(
        // canonical apntalk/esl-replay interface (available after 0.5.x integration):
        \ApnTalk\EslReplay\Contracts\ReplayCaptureStoreInterface::class,
        fn ($app) => new \ApnTalk\EslReplay\Stores\DatabaseReplayCaptureStore(...)
    );
}
```

**Do not bind against `Contracts\Upstream\ReplayCaptureStoreInterface` in application code.**
That stub is `@internal`, will be removed when the upstream package is integrated, and is
not a stable contract surface.

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

Once replay capture is integrated, each captured envelope should carry runtime identity:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `worker_session_id`
- `connection_profile_name`
- `captured_at` timestamp

That metadata will be populated by Laravel-side wiring, not by `apntalk/esl-replay` primitives.
