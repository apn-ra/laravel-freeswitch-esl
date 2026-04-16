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
- Health/diagnostic reporting
- Replay integration wiring (Laravel storage bindings, retention, inspection commands)
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

During early development (before upstream packages are published to Packagist),
this package maintains stub interfaces in `src/Contracts/Upstream/`:

- `EslClientInterface` — stub for `apntalk/esl-core` client
- `CommandDispatcherInterface` — stub for `apntalk/esl-core` dispatcher
- `EventStreamInterface` — stub for `apntalk/esl-core` stream
- `EventNormalizerInterface` — stub for `apntalk/esl-core` normalizer
- `ReplayCaptureStoreInterface` — stub for `apntalk/esl-replay` store

These stubs:
- are `@internal` and `@deprecated` from the start
- carry `Boundary:` notes in their docblocks
- will be replaced by the real upstream types once those packages are available
- must NOT be used as stable public API surfaces

When upstream packages are added to `require`, the corresponding stubs should be removed
and references updated to the canonical upstream interface.

---

## Enforcement

The `CLAUDE.md` execution contract enforces these boundaries on every implementation task:
1. Boundary check before any non-trivial change
2. Explicit conflict reporting if a change drifts across boundaries
3. Thin adapters are acceptable; re-owned protocol internals are not
