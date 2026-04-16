# Compatibility Policy

## Support matrix

| Package version | PHP | Laravel |
|---|---|---|
| `0.1.x` | 8.2, 8.3, 8.4 | 11, 12 |

Support for PHP 8.1 and Laravel 10 is not planned.

---

## Release stages

| Version | Focus |
|---|---|
| `0.1.x` | Repo foundation + control-plane contracts + DB schema |
| `0.2.x` | Integrate `apntalk/esl-core` |
| `0.3.x` | Integrate `apntalk/esl-react` |
| `0.4.x` | Laravel worker runtime + assignment orchestration |
| `0.5.x` | Integrate `apntalk/esl-replay` |
| `0.6.x` | Observability + hardening |
| `1.0.0` | Only after runtime and multi-PBX behavior are stable |

---

## Public API

Public API = anything in `src/` that is not in an `Internal/` or `Support/` namespace and is not marked `@internal`.

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

- `src/Contracts/Upstream/` — upstream stubs, will be replaced
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
