# Changelog

All notable changes to `apntalk/laravel-freeswitch-esl` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Added

#### Repo foundation
- `composer.json` with PHP 8.2+, Laravel 11/12 support matrix
- PHPUnit 11 test configuration (`phpunit.xml`)
- PHPStan level 8 configuration (`phpstan.neon`)
- Laravel Pint code-style configuration (`.php-cs-fixer.php`)
- GitHub Actions CI workflow (PHP 8.2/8.3/8.4 × Laravel 11/12)

#### Core contracts
- `PbxRegistryInterface` — multi-PBX node inventory lookups
- `ProviderDriverRegistryInterface` — provider driver map and resolution
- `ProviderDriverInterface` — contract for PBX provider drivers
- `ConnectionResolverInterface` — full resolution pipeline contract
- `ConnectionFactoryInterface` — runtime factory contract
- `WorkerInterface` — worker lifecycle contract
- `WorkerAssignmentResolverInterface` — assignment scope resolution
- `HealthReporterInterface` — structured health snapshot contract
- `SecretResolverInterface` — credential resolution contract
- Upstream stubs in `Contracts/Upstream/` for `apntalk/esl-core` and `apntalk/esl-replay` interfaces

#### Value objects
- `PbxProvider` — immutable provider family VO
- `PbxNode` — immutable PBX endpoint VO with runtime identity
- `ConnectionProfile` — immutable operational policy VO
- `WorkerAssignment` — immutable worker targeting scope VO (5 modes: node/cluster/tag/provider/all-active)
- `ConnectionContext` — fully resolved connection parameters VO (with safe `toLogContext()`)
- `WorkerStatus` — worker operational state snapshot VO
- `HealthSnapshot` — PBX node health snapshot VO

#### Database
- Migration: `pbx_providers` table
- Migration: `pbx_nodes` table (with cluster/region/tag/health indexing)
- Migration: `pbx_connection_profiles` table
- Migration: `worker_assignments` table

#### Eloquent models
- `PbxProvider` model with `toValueObject()` bridge
- `PbxNode` model with tag/cluster/provider scopes and `toValueObject()` bridge
- `PbxConnectionProfile` model with `toValueObject()` bridge
- `WorkerAssignment` model with `toValueObject()` bridge

#### Control-plane services
- `DatabasePbxRegistry` — DB-backed `PbxRegistryInterface` implementation
- `ProviderDriverRegistry` — container-resolved driver registry
- `SecretResolver` — plaintext/env/custom secret resolution modes
- `ConnectionProfileResolver` — DB + config-defaults profile resolution
- `ConnectionResolver` — full pipeline: registry → profile → secret → driver → context
- `WorkerAssignmentResolver` — all five assignment modes

#### Provider drivers
- `FreeSwitchDriver` — first `ProviderDriverInterface` implementation; builds `ConnectionContext` from `PbxNode + ConnectionProfile`

#### Laravel integration
- `FreeSwitchEslServiceProvider` — registers all bindings, publishes config/migrations, boots drivers, registers commands
- `FreeSwitchEsl` facade (optional)
- `FreeSwitchEslManager` — facade-backing manager aggregating registry/resolver/health

#### Artisan commands
- `freeswitch:ping` — resolve and display connection parameters for a node
- `freeswitch:status` — show PBX node inventory with filtering
- `freeswitch:worker` — start a multi-mode worker (node/cluster/tag/provider/all-active)
- `freeswitch:health` — show structured health snapshots
- `freeswitch:replay:inspect` — inspect replay capture store (requires replay enabled)

#### Worker runtime
- `WorkerRuntime` — single-node worker session with boot/run/drain/shutdown lifecycle and session identity
- `WorkerSupervisor` — multi-node orchestrator with per-node failure isolation

#### Health
- `HealthReporter` — DB-backed health snapshot production and recording

#### Exceptions
- `FreeSwitchEslException` — base exception
- `PbxNotFoundException`
- `ConnectionResolutionException`
- `ProviderDriverException`
- `WorkerException`

#### Tests
- 64 tests, 152 assertions, all passing (SQLite in-memory, no live PBX required)
- Unit tests: value objects, secret resolver, worker assignment resolver, FreeSWITCH driver
- Integration tests: service provider bindings, database PBX registry (all lookup modes)

#### Documentation
- `docs/architecture.md`
- `docs/control-plane.md`
- `docs/package-boundaries.md`
- `docs/worker-runtime.md`
- `docs/replay-integration.md`
- `docs/compatibility-policy.md`
- `README.md`

---

## Notes

### WorkerRuntime::run() stub

The `run()` method body is intentionally a stub in this release. Live ESL connections require `apntalk/esl-react` to be wired. The stub logs and returns immediately, allowing the full control-plane layer to be tested and validated before the async runtime is available.

### apntalk/esl-react and apntalk/esl-replay not yet wired

Both packages are listed as `suggest` in `composer.json`. Integration will follow in `0.2.x`-`0.5.x` releases as those packages are published.

### Upstream stubs

`src/Contracts/Upstream/` contains development-phase stub interfaces. These are `@internal` and will be replaced by canonical types from the upstream packages. Do not use them as stable public API surfaces.
