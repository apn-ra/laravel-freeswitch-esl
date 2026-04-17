# Package Boundaries

## APNTalk ESL Package Family

The APNTalk ESL ecosystem is split across four packages with explicit ownership:

| Package | Owns |
|---|---|
| `apntalk/esl-core` | Protocol/core ESL contracts, frame parsing, command abstractions, typed FreeSWITCH event modeling |
| `apntalk/esl-react` | ReactPHP async runtime, reconnect lifecycle, long-lived worker-safe connection behavior |
| `apntalk/esl-replay` | Replay-safe capture/store abstractions, replay envelopes, replay projectors, scenario runners |
| `apntalk/laravel-freeswitch-esl` | Laravel integration, multi-PBX control plane, worker orchestration, operational surfaces, replay integration wiring |

---

## What this package owns

- Laravel service provider
- Package config publishing
- Container bindings
- Multi-PBX PBX registry (database-backed)
- Provider driver registry
- Connection profile resolution
- Secret resolution
- Worker assignment resolution
- Worker bootstrapping and supervisor orchestration
- Laravel-owned runtime handoff bundle contracts, interface-first factory seams, runner seams, esl-react binding adapters, lifecycle-snapshot translation into status surfaces, and adapter-facing scaffolding
- Health/diagnostic reporting
- Replay integration scaffolding and inspection surfaces (without owning canonical replay contracts)
- Artisan commands for all operational surfaces
- Laravel facades (optional convenience surface)

---

## What this package does NOT own

### From `apntalk/esl-core`
- Raw ESL frame parsing
- Protocol-level authentication
- Typed ESL event definitions (ChannelCreate, etc.)
- Command request/response objects
- EventStream/EventNormalizer implementations

### From `apntalk/esl-react`
- TCP/TLS socket lifecycle
- ReactPHP event loop integration
- Reconnect/backoff engine
- Subscription lifecycle management
- Heartbeat monitoring primitives

### From `apntalk/esl-replay`
- Replay envelope definitions
- Replay capture store contracts (canonical)
- Replay projector contracts
- Replay scenario runner
- Replay cursor

---

## Upstream stubs

`apntalk/esl-core` is now consumed directly by this package, so the old local esl-core stubs are gone.

The only remaining local upstream stub is:

- `ReplayCaptureStoreInterface` — temporary `@internal` placeholder for `apntalk/esl-replay`

That stub:
- is marked `@internal`
- exists only to keep Laravel-side replay wiring isolated until `apntalk/esl-replay` is integrated
- must NOT be treated as stable public API
- should be removed once the canonical replay package contract is required directly

---

## Enforcement

The `CLAUDE.md` execution contract enforces these boundaries on every implementation task:
1. Boundary check before any non-trivial change
2. Explicit conflict reporting if a change drifts across boundaries
3. Thin adapters are acceptable; re-owned protocol internals are not
