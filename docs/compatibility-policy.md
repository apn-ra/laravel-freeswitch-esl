# Compatibility Policy

## Support matrix

| Package version | PHP | Laravel |
|---|---|---|
| `0.1.x` | 8.3, 8.4 | 11, 12 |

Support for PHP 8.2 and below, and Laravel 10, is not planned.

---

## Release stages

| Version | Focus |
|---|---|
| `0.1.x` | Repo foundation, control-plane contracts, DB schema, worker lifecycle scaffolding |
| `0.2.x` | Integrate `apntalk/esl-core` (typed events, command dispatch, event normalizer, stable transport/bootstrap seams) |
| `0.3.x` | Runtime-prep milestone: explicit handoff bundles, runner seams, interface-first worker/runtime boundaries, truthful runner/status reporting |
| `0.4.x` | Real `apntalk/esl-react` runner binding plus upstream lifecycle snapshot observation in Laravel status/operator surfaces |
| `0.5.x` | Integrate `apntalk/esl-replay` (capture wiring, retention, replay inspection) |
| `0.6.x` | Observability + hardening |
| `1.0.0` | Only after runtime and multi-PBX behavior are stable |

**Current repo posture:** `0.1.x` control-plane scope is complete, `0.2.x` `apntalk/esl-core` integration is in place, `0.3.x` runtime-prep seams are landed, and `0.4.x` lifecycle observation is now in place: `RuntimeHandoffInterface`, `RuntimeRunnerInterface`, a non-live fallback runner, default adaptation into `PreparedRuntimeBootstrapInput`, explicit prepared dial-target mapping on the supported `apntalk/esl-react` `^0.2.1` line, and real `RuntimeRunnerHandle::lifecycleSnapshot()` consumption through Laravel worker/operator status surfaces.

Current worker/runtime truth:
- `WorkerRuntime::run()` invokes the Laravel-owned runtime runner seam; the default binding calls `apntalk/esl-react`, and the `non-live` fallback may return immediately
- Laravel consumes runner-handle feedback for status reporting; on the supported `apntalk/esl-react` `^0.2.1` line, lifecycle snapshots provide connection/session/liveness/reconnect/drain truth and prepared dial targets can be passed explicitly for non-default transports
- `WorkerStatus::state = running` currently means handoff prepared, not live async session active
- reconnecting/failed worker states remain reserved for future Laravel-side operator modeling beyond the current snapshot-fed status surface

---

## Public API

Public API = anything documented in `docs/public-api.md`, plus any shipped `src/` surface that is
not in an `Internal/` or `Support/` namespace and is not marked `@internal`.

The following are stable public API surfaces:

- All interfaces in `src/Contracts/` (excluding `Contracts/Upstream/` which are stubs)
- All value objects in `src/ControlPlane/ValueObjects/`
- Service provider class name and registered bindings
- Config key `freeswitch-esl` and all documented config keys
- DB migration table names and column names
- Artisan command signatures

---

## Internal API

The following are NOT stable public API:

- `src/Contracts/Upstream/ReplayCaptureStoreInterface` — replay stub, will be replaced
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

## FreeSWITCH compatibility

This package integrates with FreeSWITCH via `apntalk/esl-core` and `apntalk/esl-react`.
FreeSWITCH version compatibility is governed by those packages.

This package targets FreeSWITCH ESL (Event Socket Library) in inbound client mode.
Outbound server mode is planned for a future version.

---

## Package boundary policy

Package boundaries are enforced by the `CLAUDE.md` execution contract and documented in `docs/package-boundaries.md`.

Changes that drift across package boundaries (i.e. adding protocol internals to this package that belong in `apntalk/esl-core`) are treated as breaking architectural changes regardless of the semver impact.
