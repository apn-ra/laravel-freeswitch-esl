# Control Plane

## Overview

The control plane governs how the package discovers PBX nodes, resolves connections, and assigns workers. It is the database-backed layer that makes this package a serious multi-PBX integration rather than a single `.env` wrapper.

---

## PBX Registry

**Interface:** `PbxRegistryInterface`
**Implementation:** `DatabasePbxRegistry`

The registry is the single source of truth for live PBX node inventory.

Supported lookups:
- `findById(int $id): PbxNode`
- `findBySlug(string $slug): PbxNode`
- `allActive(): PbxNode[]`
- `allByCluster(string $cluster): PbxNode[]`
- `allByTags(string[] $tags): PbxNode[]`
- `allByProvider(string $providerCode): PbxNode[]`

All lookups return immutable `PbxNode` value objects. The registry eagerly loads provider data to populate `providerCode` on the value object.

---

## Provider Driver Registry

**Interface:** `ProviderDriverRegistryInterface`
**Implementation:** `ProviderDriverRegistry`

Maps provider codes (e.g. `freeswitch`) to driver class implementations.

Drivers are registered from config at service-provider boot time:
```php
// config/freeswitch-esl.php
'drivers' => [
    'freeswitch' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
],
```

To add a new provider, implement `ProviderDriverInterface` and register it in config.

---

## Connection Resolution

**Interface:** `ConnectionResolverInterface`
**Implementation:** `ConnectionResolver`

The resolver runs the full connection resolution pipeline:

1. Load `PbxNode` from the registry
2. Resolve `ConnectionProfile` from the DB or config defaults
3. Resolve the plaintext credential via `SecretResolverInterface`
4. Resolve the provider driver via `ProviderDriverRegistryInterface`
5. Call `driver->buildConnectionContext(node, profile)` to get a `ConnectionContext` skeleton
6. Inject the resolved credential into the context

The output is a `ConnectionContext` VO carrying all parameters needed to open a connection, with runtime identity (node, provider, profile, worker session) already attached.

**Security:** `ConnectionContext.resolvedPassword` is plaintext. Do not log the full `ConnectionContext` object. Use `$context->toLogContext()` for safe logging.

---

## Connection Profiles

**Model:** `PbxConnectionProfile`
**Service:** `ConnectionProfileResolver`

Connection profiles are reusable operational policy bundles:
- `retry_policy_json` — reconnect/backoff parameters
- `drain_policy_json` — graceful drain parameters
- `subscription_profile_json` — ESL event subscription configuration
- `replay_policy_json` — replay capture settings
- `normalization_profile_json` — event normalization settings
- `worker_profile_json` — worker operational settings

Resolution order:
1. Named profile (if requested)
2. Default profile for the node's provider (first match in DB)
3. Config-level defaults (`freeswitch-esl.retry_defaults`, `freeswitch-esl.drain_defaults`)

---

## Worker Assignments

**Model:** `WorkerAssignment`
**Interface:** `WorkerAssignmentResolverInterface`
**Implementation:** `WorkerAssignmentResolver`

Worker assignments control which PBX nodes a given worker process will manage.

Modes:
| Mode | DB fields used | Resolution |
|---|---|---|
| `node` | `pbx_node_id` | `registry->findById(pbx_node_id)` |
| `cluster` | `cluster` | `registry->allByCluster(cluster)` |
| `tag` | `tag` | `registry->allByTags([tag])` |
| `provider` | `provider_code` | `registry->allByProvider(provider_code)` |
| `all-active` | — | `registry->allActive()` |

---

## Secret Resolution

**Interface:** `SecretResolverInterface`
**Implementation:** `SecretResolver`

The `password_secret_ref` field on `pbx_nodes` is not the literal credential — it is an opaque reference resolved by the secret resolver.

Modes (config: `freeswitch-esl.secret_resolver.mode`):
| Mode | Behavior |
|---|---|
| `plaintext` | `password_secret_ref` is the literal credential |
| `env` | `password_secret_ref` is an env variable name |
| `custom` | Delegates to a custom `SecretResolverInterface` class |

---

## Database tables

### `pbx_providers`
Stores provider families. Each row defines a provider code, driver class, and capability flags.

### `pbx_nodes`
The live PBX endpoint inventory. Rows carry host, port, transport, credentials (as secret refs), cluster, region, and tags.

### `pbx_connection_profiles`
Reusable operational profiles. Profiles store policy JSON for retry, drain, subscription, replay, normalization, and worker behavior.

### `worker_assignments`
Records which workers own which targeting scopes. Used by the `freeswitch:worker` command for DB-driven assignment and by `WorkerAssignmentResolver::resolveForWorkerName()`.

---

## Seeding a PBX node (example)

```php
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;

$provider = PbxProvider::create([
    'code'         => 'freeswitch',
    'name'         => 'FreeSWITCH',
    'driver_class' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
    'is_active'    => true,
]);

PbxNode::create([
    'provider_id'         => $provider->id,
    'name'                => 'Primary FS',
    'slug'                => 'primary-fs',
    'host'                => '10.0.0.10',
    'port'                => 8021,
    'username'            => '',
    'password_secret_ref' => 'ClueCon',  // plaintext in dev; use env/vault in prod
    'transport'           => 'tcp',
    'is_active'           => true,
    'cluster'             => 'us-east',
    'tags_json'           => ['prod', 'primary'],
]);
```

Then resolve and inspect:
```bash
php artisan freeswitch:ping --pbx=primary-fs
php artisan freeswitch:status --cluster=us-east
```
