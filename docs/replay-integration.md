# Replay Integration

## Ownership model

`apntalk/laravel-freeswitch-esl` does NOT own replay primitives. It now provides a Laravel-side
integration layer around the released `apntalk/esl-replay` storage package and the existing
`apntalk/esl-react` replay hook emission path.

| Responsibility | Owner |
|---|---|
| `ReplayArtifactStoreInterface` (canonical durable store) | `apntalk/esl-replay` |
| `ReplayCheckpointRepository` / bounded checkpoint query primitives | `apntalk/esl-replay` |
| `CapturedArtifactEnvelope` | `apntalk/esl-replay` |
| `StoredReplayRecord` | `apntalk/esl-replay` |
| `ReplayReadCursor` | `apntalk/esl-replay` |
| Laravel storage binding | `apntalk/laravel-freeswitch-esl` |
| Laravel checkpoint-store binding | `apntalk/laravel-freeswitch-esl` |
| Retention policy configuration | `apntalk/laravel-freeswitch-esl` |
| `freeswitch:replay:inspect` command | `apntalk/laravel-freeswitch-esl` |
| Session/correlation metadata propagation | `apntalk/laravel-freeswitch-esl` |
| Worker drain/checkpoint coordination | `apntalk/laravel-freeswitch-esl` |
| Live replay hook emission | `apntalk/esl-react` |

---

## Enabling replay capture

Replay is disabled by default.

```php
// config/freeswitch-esl.php
'replay' => [
    'enabled'        => true,
    'store_driver'   => 'database',
    'storage_path'   => storage_path('app/freeswitch-esl/replay'),
    'checkpoint_storage_path' => storage_path('app/freeswitch-esl/replay/checkpoints'),
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

Replay capture wiring is implemented through the released upstream store contract:
`Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface`

This package binds that contract when:

- `freeswitch-esl.replay.enabled = true`
- `apntalk/esl-replay` is installed

The Laravel binding uses `Apntalk\EslReplay\Storage\ReplayArtifactStore::make(...)`
and currently maps:

- `store_driver=database` or `sqlite` → upstream SQLite adapter
- `store_driver=filesystem` → upstream append-only filesystem adapter

In your own application, override the store binding against the upstream contract:

```php
public function register(): void
{
    $this->app->singleton(
        \Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface::class,
        fn () => \Apntalk\EslReplay\Storage\ReplayArtifactStore::make(...)
    );
}
```

## Replay checkpoint binding

Replay-backed checkpoint coordination is also wired through the released upstream checkpoint
contract: `Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface`

The current Laravel integration uses the upstream filesystem checkpoint store and keeps
checkpoint files separate from artifact persistence:

- `checkpoint_storage_path` when explicitly configured
- otherwise a `checkpoints/` directory next to the configured replay storage path

These checkpoints are intentionally narrow. They record persisted-artifact progress only and
do not claim live FreeSWITCH socket recovery, upstream runtime-session recovery, or reconnect
continuity.

---

## Replay partitioning

Replay data is partitioned by:
- **Upstream replay stream** — ordered append sequence inside the configured artifact store
- **Provider / PBX / worker identity** — stored in `StoredReplayRecord::$runtimeFlags`
- **Replay session / job correlation** — stored in `sessionId`, `jobUuid`, and `correlationIds`
- **Time window** — bounded via `ReplayReadCriteria`

`freeswitch:replay:inspect` performs bounded time-window reads against the upstream store and
filters by `runtime_flags.pbx_node_slug` when `--pbx=` is provided.

Worker checkpoints use the same persisted replay stream as their identity anchor, but save a
stable worker/node/profile checkpoint key so later Laravel worker runs can truthfully report
whether a prior replay-backed checkpoint exists for that scope.

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

Each persisted replay record now carries Laravel runtime identity derived from the resolved
`ConnectionContext` used to boot the worker/runtime:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `worker_session_id`
- `connection_profile_name`
- `transport`

The upstream replay record also keeps:
- replay session identity from the captured `ReplayEnvelopeInterface`
- artifact name/version/path emitted by `apntalk/esl-react`
- raw envelope payload, classifier context, protocol facts, and derived metadata

That enrichment is populated by Laravel-side sink/adaptation code. `apntalk/esl-replay`
continues to own durable storage semantics.

## Checkpoint and drain coordination

Current worker checkpoint/drain behavior is intentionally conservative:

- `WorkerRuntime::drain()` records `drain_started_at` and a bounded `drain_deadline_at`
- drain saves a replay-backed `drain-requested` checkpoint over the current worker session's persisted replay artifacts
- drain completes immediately when no inflight work is present
- drain can wait for Laravel-owned inflight bookkeeping to reach zero
- drain times out conservatively when the bounded deadline is reached
- terminal drain transitions save a second bounded checkpoint with reason `drain-completed` or `drain-timeout`

Checkpoint keys are stable for the worker scope:

- `worker_name`
- `provider_code`
- `pbx_node_slug`
- `connection_profile_name`

Checkpoint metadata preserves the current runtime identity:

- `worker_session_id`
- `pbx_node_id`
- `pbx_node_slug`
- `provider_code`
- `connection_profile_name`
- `replay_session_id` and `job_uuid` when available from the persisted replay artifacts
- checkpoint save reason and last consumed replay append sequence

Startup recovery uses the upstream bounded checkpoint/query seams:

- checkpoints are written through `ReplayCheckpointReference` / `ReplayCheckpointRepository`
- checkpoint lookup stays bounded through `ReplayCheckpointCriteria`
- replay recovery hints use bounded `ReplayReadCriteria` keyed by persisted identity anchors such as:
  - `replaySessionId`
  - `workerSessionId`
  - `pbxNodeSlug`
  - `jobUuid`

Laravel currently uses those seams only to surface conservative recovery posture for a worker scope.
It does not execute replay, re-inject traffic, or claim live session recovery.

That posture is now rendered directly in `freeswitch:worker` output as operator-facing checkpoint/recovery hints, and `freeswitch:worker --json` exposes the same bounded posture in a machine-readable form for automation. For reporting-oriented automation, `freeswitch:worker:status` prepares worker runtimes without invoking the bound runtime runner and emits a dedicated JSON payload that can report one or more worker scopes. The default `freeswitch:health` and `freeswitch:status` commands remain conservative: they explicitly note that DB-backed health snapshots and control-plane inventory do not themselves expose live worker recovery posture.

This remains a Laravel coordination surface, not a replay executor and not a live runtime
recovery mechanism.
