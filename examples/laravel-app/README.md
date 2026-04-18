# Example: Laravel Application Integration

This directory is a minimal adoption cookbook for `apntalk/laravel-freeswitch-esl`.
It is intentionally small: enough to validate package install, control-plane
seeding, worker startup, health routes, and basic observability posture without
pretending to be a full demo product.

## Posture

This example is `near-runnable`, not a published Laravel skeleton. Use it as a
file set to copy into a fresh Laravel app or into a scratch validation app.

Included here:

- [database/seeders/FreeswitchEslExampleSeeder.php](/home/grimange/apn_projects/laravel-freeswitch-esl/examples/laravel-app/database/seeders/FreeswitchEslExampleSeeder.php)
- [database/seeders/DatabaseSeeder.php](/home/grimange/apn_projects/laravel-freeswitch-esl/examples/laravel-app/database/seeders/DatabaseSeeder.php)
- [app/Providers/FreeswitchEslExampleServiceProvider.php](/home/grimange/apn_projects/laravel-freeswitch-esl/examples/laravel-app/app/Providers/FreeswitchEslExampleServiceProvider.php)
- [config/freeswitch-esl.php](/home/grimange/apn_projects/laravel-freeswitch-esl/examples/laravel-app/config/freeswitch-esl.php)

## Intended validation flow

1. Install the package into a fresh Laravel app.
2. Publish package config and migrations.
3. Copy the example files from this directory into that app.
4. Run migrations and the example seeder.
5. Verify control-plane commands.
6. Start the worker in ephemeral and DB-backed modes.
7. Check JSON health endpoints and metrics behavior.

## Fresh-app setup

```bash
composer create-project laravel/laravel laravel-freeswitch-esl-example
cd laravel-freeswitch-esl-example
composer require apntalk/laravel-freeswitch-esl
php artisan vendor:publish --tag=freeswitch-esl-config
php artisan vendor:publish --tag=freeswitch-esl-migrations
```

Copy these example files into the Laravel app:

- `examples/laravel-app/database/seeders/*` → `database/seeders/`
- `examples/laravel-app/app/Providers/FreeswitchEslExampleServiceProvider.php` → `app/Providers/`
- `examples/laravel-app/config/freeswitch-esl.php` → merge the relevant values into your published package config

Register the example provider in `bootstrap/providers.php` or
`config/app.php` depending on your Laravel version:

```php
App\Providers\FreeswitchEslExampleServiceProvider::class,
```

## Environment

The package can validate most of this flow without a live PBX. For a local-only
bootstrap flow, these example values are enough:

```env
FREESWITCH_ESL_DRIVER=freeswitch
FREESWITCH_ESL_METRICS_DRIVER=log
FREESWITCH_ESL_HTTP_HEALTH_ENABLED=true
FREESWITCH_ESL_REPLAY_ENABLED=false
```

If you have a real validation PBX, also set:

```env
FREESWITCH_ESL_FALLBACK_ENABLED=false
FREESWITCH_ESL_HOST=127.0.0.1
FREESWITCH_ESL_PORT=8021
FREESWITCH_ESL_PASSWORD=ClueCon
```

## Seed data

Run the example seeder:

```bash
php artisan migrate --seed
```

The seeded example creates:

- provider `freeswitch`
- PBX node `primary-fs`
- connection profile `default`
- DB-backed worker assignment `ingest-worker`

## Control-plane validation

Verify that the package can resolve and report the seeded node:

```bash
php artisan freeswitch:ping --pbx=primary-fs
php artisan freeswitch:status
php artisan freeswitch:health
php artisan freeswitch:health --summary --json
```

## Worker validation

Ephemeral single-node startup:

```bash
php artisan freeswitch:worker --worker=ingest-worker --pbx=primary-fs
```

DB-backed startup using the seeded `worker_assignments` row:

```bash
php artisan freeswitch:worker --worker=ingest-worker --db
php artisan freeswitch:worker:status --worker=ingest-worker --db
php artisan freeswitch:worker:checkpoint-status --worker=ingest-worker
```

## Health endpoints

The package registers health routes when
`freeswitch-esl.http.health.enabled=true`.

Validate the JSON surfaces:

```bash
php artisan serve
curl http://127.0.0.1:8000/freeswitch-esl/health
curl http://127.0.0.1:8000/freeswitch-esl/health/live
curl http://127.0.0.1:8000/freeswitch-esl/health/ready
```

## Observability and replay

Basic observability is available immediately through the default log-backed
metrics driver. Start a worker or record health, then inspect the Laravel log
for metric records such as:

- `freeswitch_esl.worker.boot`
- `freeswitch_esl.worker.run_invoked`
- `freeswitch_esl.health.snapshot_recorded`

Optional replay inspection remains available when replay is enabled:

```env
FREESWITCH_ESL_REPLAY_ENABLED=true
```

```bash
php artisan freeswitch:replay:inspect --json
```

That command is most useful after a live or simulated runtime has emitted
replay artifacts.

## What this example does not claim

- It does not turn this repository into an owner of reconnect semantics.
- It does not provide a production-ready dashboard or queueing stack.
- It does not replace upstream live-runtime validation from `apntalk/esl-react`.
