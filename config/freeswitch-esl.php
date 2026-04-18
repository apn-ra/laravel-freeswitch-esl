<?php

use ApnTalk\LaravelFreeswitchEsl\Drivers\FreeSwitchDriver;

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
        'freeswitch' => FreeSwitchDriver::class,
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
        'mode' => env('FREESWITCH_ESL_SECRET_MODE', 'plaintext'),
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
        'max_attempts' => (int) env('FREESWITCH_ESL_MAX_RETRY', 5),
        'initial_delay_ms' => (int) env('FREESWITCH_ESL_RETRY_INITIAL_MS', 1000),
        'backoff_factor' => (float) env('FREESWITCH_ESL_RETRY_BACKOFF', 2.0),
        'max_delay_ms' => (int) env('FREESWITCH_ESL_RETRY_MAX_MS', 60000),
        'jitter' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drain Policy Defaults
    |--------------------------------------------------------------------------
    | Default graceful drain settings for workers.
    */
    'drain_defaults' => [
        'timeout_ms' => (int) env('FREESWITCH_ESL_DRAIN_TIMEOUT_MS', 30000),
        'max_inflight' => (int) env('FREESWITCH_ESL_MAX_INFLIGHT', 100),
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
        'failure_threshold' => (int) env('FREESWITCH_ESL_FAILURE_THRESHOLD', 3),
        'recovery_threshold' => (int) env('FREESWITCH_ESL_RECOVERY_THRESHOLD', 1),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Health Integration
    |--------------------------------------------------------------------------
    | Optional Laravel route registration for DB-backed health snapshot and
    | bounded readiness/liveness posture output. These routes reuse the
    | existing HealthReporter surface and do not imply live runtime ownership.
    */
    'http' => [
        'health' => [
            'enabled' => env('FREESWITCH_ESL_HTTP_HEALTH_ENABLED', true),
            'prefix' => env('FREESWITCH_ESL_HTTP_HEALTH_PREFIX', 'freeswitch-esl/health'),
            'middleware' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Observability
    |--------------------------------------------------------------------------
    | Metrics emission is enabled by default through the shipped log-backed
    | recorder. Set the driver to "event" to emit Laravel events instead, or
    | "null" to preserve the previous no-op behavior explicitly.
    */
    'observability' => [
        'metrics' => [
            'driver' => env('FREESWITCH_ESL_METRICS_DRIVER', 'log'),
            'log_level' => env('FREESWITCH_ESL_METRICS_LOG_LEVEL', 'info'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Defaults
    |--------------------------------------------------------------------------
    | Default worker runtime settings when not overridden by an assignment profile.
    */
    'worker_defaults' => [
        'heartbeat_interval_seconds' => (int) env('FREESWITCH_ESL_HEARTBEAT_INTERVAL', 30),
        'shutdown_timeout_seconds' => (int) env('FREESWITCH_ESL_SHUTDOWN_TIMEOUT', 30),
        'checkpoint_interval_seconds' => (int) env('FREESWITCH_ESL_CHECKPOINT_INTERVAL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime Runner Binding
    |--------------------------------------------------------------------------
    | Selects the Laravel-owned runtime runner binding.
    |
    | Supported values:
    |   'esl-react' - adapt RuntimeHandoffInterface into apntalk/esl-react's
    |                 prepared bootstrap input and invoke its runner seam
    |   'non-live'  - retain the truthful no-op runner for dry-run/fallback use;
    |                 workers will not maintain a live ESL session
    */
    'runtime' => [
        'runner' => env('FREESWITCH_ESL_RUNTIME_RUNNER', 'esl-react'),

        'react' => [
            // Live runtime connector options passed to React\Socket\Connector.
            // Use top-level 'timeout', nested 'tcp' socket options, and nested
            // 'tls' SSL options. Per-context connect_timeout_seconds and
            // stream_context_options.socket/ssl are projected onto this live
            // connector path when present.
            'connector_options' => [],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Replay Integration
    |--------------------------------------------------------------------------
    | Settings governing replay capture wiring via apntalk/esl-replay.
    | Capture is disabled by default and must be explicitly enabled.
    */
    'replay' => [
        'enabled' => env('FREESWITCH_ESL_REPLAY_ENABLED', false),
        'store_driver' => env('FREESWITCH_ESL_REPLAY_STORE', 'database'),
        'storage_path' => env('FREESWITCH_ESL_REPLAY_STORAGE_PATH', storage_path('app/freeswitch-esl/replay')),
        'checkpoint_storage_path' => env('FREESWITCH_ESL_REPLAY_CHECKPOINT_STORAGE_PATH', ''),
        'retention_days' => (int) env('FREESWITCH_ESL_REPLAY_RETENTION_DAYS', 7),
    ],

];
