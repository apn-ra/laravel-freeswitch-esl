<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default PBX Provider Driver
    |--------------------------------------------------------------------------
    | The default provider driver used when resolving connections and workers.
    | This should match a key in the 'drivers' map below.
    */
    'default_driver' => env('FREESWITCH_ESL_DRIVER', 'freeswitch'),

    /*
    |--------------------------------------------------------------------------
    | Provider Driver Map
    |--------------------------------------------------------------------------
    | Maps provider codes to their driver class implementations. The driver
    | class must implement ProviderDriverInterface.
    |
    | The database pbx_providers table is the live inventory. This map defines
    | which drivers are available to the package.
    */
    'drivers' => [
        'freeswitch' => \ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database Table Prefix
    |--------------------------------------------------------------------------
    | Optional prefix for all control-plane tables. Useful when deploying this
    | package alongside other packages in the same database.
    */
    'table_prefix' => env('FREESWITCH_ESL_TABLE_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Local Fallback Connection
    |--------------------------------------------------------------------------
    | An optional in-config PBX node definition used when DB-backed inventory
    | is not yet seeded or during local development. This is NOT the primary
    | inventory source — it is a fallback/bootstrap helper only.
    |
    | Set 'enabled' to false to disable the fallback entirely.
    */
    'fallback' => [
        'enabled' => env('FREESWITCH_ESL_FALLBACK_ENABLED', false),
        'provider' => 'freeswitch',
        'host'     => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
        'port'     => (int) env('FREESWITCH_ESL_PORT', 8021),
        'password' => env('FREESWITCH_ESL_PASSWORD', 'ClueCon'),
        'timeout'  => (int) env('FREESWITCH_ESL_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Secret Resolution
    |--------------------------------------------------------------------------
    | Controls how connection passwords/secrets are resolved.
    |
    | Modes:
    |   'plaintext'  - password_secret_ref is the literal credential
    |   'env'        - password_secret_ref is an env variable name
    |   'vault'      - password_secret_ref is a Vault secret path (future)
    |   'custom'     - password_secret_ref is resolved by a custom resolver class
    */
    'secret_resolver' => [
        'mode'           => env('FREESWITCH_ESL_SECRET_MODE', 'plaintext'),
        'resolver_class' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Policy Defaults
    |--------------------------------------------------------------------------
    | Default retry/reconnect behavior for connection profiles that do not
    | specify their own policy.
    */
    'retry_defaults' => [
        'max_attempts'    => (int) env('FREESWITCH_ESL_MAX_RETRY', 5),
        'initial_delay_ms' => (int) env('FREESWITCH_ESL_RETRY_INITIAL_MS', 1000),
        'backoff_factor'  => (float) env('FREESWITCH_ESL_RETRY_BACKOFF', 2.0),
        'max_delay_ms'    => (int) env('FREESWITCH_ESL_RETRY_MAX_MS', 60000),
        'jitter'          => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drain Policy Defaults
    |--------------------------------------------------------------------------
    | Default graceful drain settings for workers.
    */
    'drain_defaults' => [
        'timeout_ms'       => (int) env('FREESWITCH_ESL_DRAIN_TIMEOUT_MS', 30000),
        'max_inflight'     => (int) env('FREESWITCH_ESL_MAX_INFLIGHT', 100),
        'check_interval_ms' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Health Check Thresholds
    |--------------------------------------------------------------------------
    | Thresholds that govern health status transitions.
    */
    'health' => [
        'heartbeat_timeout_seconds' => (int) env('FREESWITCH_ESL_HEARTBEAT_TIMEOUT', 60),
        'failure_threshold'          => (int) env('FREESWITCH_ESL_FAILURE_THRESHOLD', 3),
        'recovery_threshold'         => (int) env('FREESWITCH_ESL_RECOVERY_THRESHOLD', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Defaults
    |--------------------------------------------------------------------------
    | Default worker runtime settings when not overridden by an assignment profile.
    */
    'worker_defaults' => [
        'heartbeat_interval_seconds' => (int) env('FREESWITCH_ESL_HEARTBEAT_INTERVAL', 30),
        'shutdown_timeout_seconds'   => (int) env('FREESWITCH_ESL_SHUTDOWN_TIMEOUT', 30),
        'checkpoint_interval_seconds' => (int) env('FREESWITCH_ESL_CHECKPOINT_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay Integration
    |--------------------------------------------------------------------------
    | Settings governing replay capture wiring via apntalk/esl-replay.
    | Capture is disabled by default and must be explicitly enabled.
    */
    'replay' => [
        'enabled'          => env('FREESWITCH_ESL_REPLAY_ENABLED', false),
        'store_driver'     => env('FREESWITCH_ESL_REPLAY_STORE', 'database'),
        'retention_days'   => (int) env('FREESWITCH_ESL_REPLAY_RETENTION_DAYS', 7),
    ],

];
