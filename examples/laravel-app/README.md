# Example: Laravel Application Integration

This directory will contain a minimal reference Laravel application demonstrating
how to integrate `apntalk/laravel-freeswitch-esl`.

> **Status:** Scaffold placeholder. Full example will be added in a later pass
> alongside live ESL runtime integration (0.3.x).

---

## What this example will cover

1. Installing and configuring the package
2. Seeding a PBX provider and node
3. Seeding a connection profile
4. Seeding worker assignments (DB-backed targeting)
5. Running diagnostics via artisan commands
6. Starting a worker process (ephemeral and DB-backed paths)
7. Binding a custom secret resolver
8. Inspecting health snapshots

---

## Current usable steps (0.1.x)

The control-plane layer is functional now. You can follow these steps without a live PBX:

### 1. Install

```bash
composer require apntalk/laravel-freeswitch-esl
php artisan vendor:publish --tag=freeswitch-esl-config
php artisan vendor:publish --tag=freeswitch-esl-migrations
php artisan migrate
```

### 2. Seed a provider and node

See `database/seeders/PbxSeeder.php` in this directory (to be added in a later pass).

For now, use tinker or a manual seeder:

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
    'password_secret_ref' => 'ClueCon',
    'transport'           => 'tcp',
    'is_active'           => true,
    'cluster'             => 'us-east',
    'tags_json'           => ['prod'],
]);
```

### 3. Seed a DB-backed worker assignment

```php
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment;

WorkerAssignment::create([
    'worker_name'     => 'ingest-worker',
    'assignment_mode' => 'cluster',
    'cluster'         => 'us-east',
    'is_active'       => true,
]);
```

### 4. Verify the control plane

```bash
php artisan freeswitch:ping --pbx=primary-fs
php artisan freeswitch:status
php artisan freeswitch:health
```

### 5. Start a worker (ephemeral)

```bash
php artisan freeswitch:worker --pbx=primary-fs
```

### 6. Start a worker (DB-backed assignment)

```bash
php artisan freeswitch:worker --worker=ingest-worker --db
```

---

## Planned files (to be added)

```
examples/laravel-app/
  database/
    seeders/
      PbxSeeder.php
  app/
    Providers/
      EslServiceProvider.php   — custom secret resolver binding example
  config/
    freeswitch-esl.php          — annotated config example
  README.md                     — this file
```
