# Event Model

> **Status:** Partially implemented.
> `apntalk/esl-core` is now required directly and this package already ships the Laravel adapter layer for typed commands, inbound decoding, and event dispatch.
> Higher-level app-facing normalized projections and replay-linked event workflows remain deferred until a later bounded pass; they are not part of the currently shipped `0.6.x` public surface.

---

## Ownership model

The event model is split across two APNTalk packages with explicit boundaries:

| Layer | Owner | Description |
|---|---|---|
| Raw ESL frame parsing | `apntalk/esl-core` | Bytes → `RawEslFrame` |
| Typed FreeSWITCH event objects | `apntalk/esl-core` | `RawEslFrame` → `ChannelCreated`, `HangupCompleted`, etc. |
| Event normalization contracts | `apntalk/esl-core` | `EventNormalizerInterface` |
| Typed → normalized conversion | `apntalk/esl-core` | `RawEslEvent` → `NormalizedEvent` |
| Laravel event bridge | `apntalk/laravel-freeswitch-esl` (this package) | Dispatch into Laravel event system |
| Laravel bridge-wrapper events | `apntalk/laravel-freeswitch-esl` (this package) | Laravel `Event` classes wrapping upstream typed payloads plus context |
| App-facing normalized domain events | `apntalk/laravel-freeswitch-esl` (this package) | Higher-level Laravel-native domain events derived from normalized data |

This package must not re-own raw ESL parsing, typed protocol event definitions,
or the normalization engine. Those belong in `apntalk/esl-core`.

---

## Current implementation in this repository

The following `0.2.x`-style adapter surfaces already exist here:

- `src/Integration/EslCoreCommandFactory.php`
- `src/Integration/EslCorePipelineFactory.php`
- `src/Integration/EslCoreEventBridge.php`
- `src/Events/EslEventReceived.php`
- `src/Events/EslReplyReceived.php`
- `src/Events/EslDisconnected.php`

Current behavior:
- command objects are built using `apntalk/esl-core` typed command classes
- inbound ESL frames can be decoded through `InboundPipeline`
- decoded messages can be bridged into Laravel wrapper events with `ConnectionContext` attached
- `EslEventReceived` carries both the upstream typed event and the upstream normalized payload produced by `apntalk/esl-core`
- that shipped wrapper layer is not the same thing as a Laravel-native normalized domain-event layer

Still deferred:
- long-lived runtime ownership in `apntalk/esl-react`
- higher-level Laravel-facing normalized domain projections like `NormalizedCallCreated`
- replay-enriched event capture via `apntalk/esl-replay`

---

## Intended raw event layer (from apntalk/esl-core)

Once `apntalk/esl-core` is integrated, this package will consume typed events including:

- `ChannelCreated`
- `ChannelAnswered`
- `BridgeStarted`
- `BridgeEnded`
- `HangupCompleted`
- `PlaybackStarted`
- `PlaybackStopped`
- `BgapiJobCompleted`
- Custom FreeSWITCH events

---

## Deferred normalized domain-event layer (Laravel-facing, owned here)

Today the package dispatches low-level Laravel bridge events (`EslEventReceived`,
`EslReplyReceived`, `EslDisconnected`) around `apntalk/esl-core` payloads.

Future work may add higher-level Laravel-native normalized domain events such as:

- `NormalizedCallCreated`
- `NormalizedCallAnswered`
- `NormalizedCallBridged`
- `NormalizedCallEnded`
- `NormalizedMediaStarted`
- `NormalizedQueueRetry`
- `NormalizedDrainStateChanged`

If this later domain-event layer is added, every dispatched event will carry runtime identity:
- `provider_code`
- `pbx_node_id`
- `pbx_node_slug`
- `connection_profile_name`
- `worker_session_id`
- `schema_version`

The currently shipped Laravel bridge-wrapper events now expose `schemaVersion = "1.0"` on:

- `EslEventReceived`
- `EslReplyReceived`
- `EslDisconnected`

That version applies only to the shipped Laravel wrapper contract owned by this
package. It does not mean a higher-level normalized domain-event layer already
exists, and it does not replace upstream `apntalk/esl-core` event/reply typing
or normalization ownership.

---

## Schema versioning

If a later Laravel-native normalized domain-event layer is introduced, those
normalized events will carry an explicit `schema_version` field. Breaking
changes to that normalized event structure will require a version bump. This
protects downstream consumers from silent schema drift.

Schema version policy: `MAJOR.MINOR` aligned with the normalized event contract, not
the package's own semantic version.

---

## What remains to be added in later phases

- higher-level normalized Laravel event class list, if those projections are added
- schema version starting point for those higher-level events
- replay-linked metadata examples once `apntalk/esl-replay` is integrated
- runtime-driven dispatch examples once `apntalk/esl-react` is wired
