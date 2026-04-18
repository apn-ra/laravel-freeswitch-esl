# Compatibility Policy

## Support matrix

| Package version | PHP | Laravel |
|---|---|---|
| `0.5.x` | 8.3, 8.4 | 11, 12 |
| `0.6.x` | 8.3, 8.4 | 11, 12 |

Support for PHP 8.2 and below, and Laravel 10, is not planned.

---

## Release stages

| Version | Focus |
|---|---|
| `0.1.x` | Repo foundation, control-plane contracts, DB schema, worker lifecycle scaffolding |
| `0.2.x` | Integrate `apntalk/esl-core` (typed events, command dispatch, event normalizer, stable transport/bootstrap seams) |
| `0.3.x` | Runtime-prep milestone: explicit handoff bundles, runner seams, interface-first worker/runtime boundaries, truthful runner/status reporting |
| `0.4.x` | Real `apntalk/esl-react` runner binding plus upstream runtime status observation in Laravel status/operator surfaces |
| `0.5.x` | Replay integration, bounded checkpoint/reporting surfaces, and runtime-linked health snapshot persistence/reporting |
| `0.6.x` | Additional hardening and operator-surface polish without taking upstream runtime/replay ownership |
| `1.0.0` | Only after runtime and multi-PBX behavior are stable |

**Current repo posture:** `0.1.x` control-plane scope is complete, `0.2.x` `apntalk/esl-core` integration is in place, `0.3.x` runtime-prep seams are landed, `0.4.x` runtime observation is in place, `0.5.x` replay integration is in place, and the current `0.6.x` line now includes truthful authority docs, shipped metrics drivers with a non-null default, load-bearing inflight/backpressure enforcement, deterministic simulated ESL lifecycle verification, and a near-runnable example app cookbook without moving protocol, reconnect, or replay ownership out of upstream packages.

Support-floor rationale:
- composer requires PHP `^8.3`
- the supported `apntalk/esl-react` `^0.2.10` runtime dependency also requires PHP `^8.3`
- this repository therefore does not truthfully support PHP 8.2 on the current release line

Current worker/runtime truth:
- `WorkerRuntime::run()` invokes the Laravel-owned runtime runner seam; the default binding calls `apntalk/esl-react`, and the `non-live` fallback may return immediately
- Laravel consumes runner-handle feedback for status reporting; on the supported `apntalk/esl-react` `^0.2.10` line, runtime status snapshots and push-based lifecycle callbacks provide connection/session/liveness/reconnect/drain truth and prepared dial targets can be passed explicitly for non-default transports. Validation/stability truth for reconnect, heartbeat, and bgapi/event runtime behavior remains upstream-owned and upstream-documented.
- `WorkerStatus::state = running` currently means handoff prepared, not live async session active
- reconnecting/failed worker states are now surfaced when upstream runtime status snapshots report those phases; Laravel still does not own reconnect or failure-recovery mechanics
- HTTP health/readiness/liveness routes and the CLI health summary now expose conservative bounded DB-backed posture only, not process/event-loop liveness guarantees

---

## Public API

Public API = anything documented in `docs/public-api.md`, plus any shipped `src/` surface that is
not in an `Internal/` or `Support/` namespace and is not marked `@internal`.

The following are stable public API surfaces:

- All interfaces in `src/Contracts/`
- All value objects in `src/ControlPlane/ValueObjects/`
- Service provider class name and registered bindings
- Config key `freeswitch-esl` and all documented config keys
- DB migration table names and column names
- Artisan command signatures

---

## Internal API

The following are NOT stable public API:

- Model internals
- `WorkerRuntime` and `WorkerSupervisor` internal methods
- Service implementation internals

---

## Deprecation policy

Before `1.0.0`:
- Breaking changes are allowed in minor versions with a changelog entry
- Changes to public contracts will be documented

After `1.0.0`:
- Breaking changes require a major version bump
- Public API surfaces are stable for the lifetime of a major version

---

## Laravel bridge event schema-version policy

The `SCHEMA_VERSION` constants on the shipped Laravel bridge-wrapper events
apply to the wrapper payload shape exposed by this package:

- `EslEventReceived::SCHEMA_VERSION`
- `EslReplyReceived::SCHEMA_VERSION`
- `EslDisconnected::SCHEMA_VERSION`

This version applies to the Laravel event wrapper contract itself, including:

- top-level wrapper field names and meaning
- wrapper metadata carried for downstream consumers
- the presence, type, or semantics of wrapper-carried typed or normalized
  payload references

This version does not define FreeSWITCH protocol compatibility, raw esl-core
frame/event compatibility, or a separate Laravel-native normalized domain-event
layer.

A breaking schema change is any change that would require a downstream consumer
of the Laravel wrapper event to change its code or assumptions, including:

- removing a wrapper field
- renaming a wrapper field
- changing the meaning or type of a wrapper field
- changing whether a wrapper field is present

The schema version must bump when such a breaking wrapper change ships. Additive
changes may remain on the same schema version when existing consumers can
ignore the new data safely.

Compatibility expectation for downstream consumers:

- consumers should treat `SCHEMA_VERSION` as the compatibility marker for the
  Laravel bridge-wrapper event contract
- additive fields may appear without a schema bump
- breaking wrapper changes will be called out in release notes and changelog
  entries
- consumers that need strict compatibility checks should gate on the wrapper
  `SCHEMA_VERSION`, not on package SemVer alone

---

## FreeSWITCH compatibility

This package integrates with FreeSWITCH via `apntalk/esl-core` and `apntalk/esl-react`.
FreeSWITCH version compatibility is governed by those packages.

This package targets FreeSWITCH ESL (Event Socket Library) in inbound client mode.
Outbound server mode is explicitly deferred beyond the current `1.0.0`
stabilization target. Reaching `1.0.0` for this package does not require
Laravel-owned outbound support, and no outbound server-mode public API should be
assumed on the current release line.

---

## Package boundary policy

Package boundaries are enforced by the `CLAUDE.md` execution contract and documented in `docs/package-boundaries.md`.

Changes that drift across package boundaries (i.e. adding protocol internals to this package that belong in `apntalk/esl-core`) are treated as breaking architectural changes regardless of the semver impact.
