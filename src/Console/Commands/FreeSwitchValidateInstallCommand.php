<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionFactoryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\HealthReporterInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\MetricsRecorderInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\PbxRegistryInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\RuntimeRunnerInterface;
use ApnTalk\LaravelFreeswitchEsl\Contracts\WorkerAssignmentResolverInterface;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxConnectionProfile;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxNode;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\PbxProvider;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\Models\WorkerAssignment;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Schema;

class FreeSwitchValidateInstallCommand extends Command
{
    protected $signature = 'freeswitch:validate-install
                            {--example : Also validate the documented example seed shape}
                            {--json    : Emit machine-readable validation output}';

    protected $description = 'Validate package install, wiring, schema, and optional example seed posture without live ESL';

    public function handle(
        ConfigRepository $config,
        Kernel $kernel,
        MetricsRecorderInterface $metrics,
        PbxRegistryInterface $registry,
        ConnectionResolverInterface $resolver,
        ConnectionFactoryInterface $connectionFactory,
        WorkerAssignmentResolverInterface $assignmentResolver,
        HealthReporterInterface $healthReporter,
        RuntimeRunnerInterface $runtimeRunner,
    ): int {
        $configChecks = [
            'default_driver_present' => $this->nonEmptyString($config->get('freeswitch-esl.default_driver')),
            'drivers_configured' => is_array($config->get('freeswitch-esl.drivers')) && $config->get('freeswitch-esl.drivers') !== [],
            'metrics_driver_present' => $this->nonEmptyString($config->get('freeswitch-esl.observability.metrics.driver')),
            'runtime_runner_present' => $this->nonEmptyString($config->get('freeswitch-esl.runtime.runner')),
            'http_health_prefix_present' => $this->nonEmptyString($config->get('freeswitch-esl.http.health.prefix')),
        ];

        $schemaChecks = [
            'pbx_providers' => Schema::hasTable('pbx_providers'),
            'pbx_nodes' => Schema::hasTable('pbx_nodes'),
            'pbx_connection_profiles' => Schema::hasTable('pbx_connection_profiles'),
            'worker_assignments' => Schema::hasTable('worker_assignments'),
        ];

        $bindings = [
            PbxRegistryInterface::class => $registry::class,
            ConnectionResolverInterface::class => $resolver::class,
            ConnectionFactoryInterface::class => $connectionFactory::class,
            WorkerAssignmentResolverInterface::class => $assignmentResolver::class,
            HealthReporterInterface::class => $healthReporter::class,
            MetricsRecorderInterface::class => $metrics::class,
            RuntimeRunnerInterface::class => $runtimeRunner::class,
        ];

        $commandChecks = [];

        foreach ([
            'freeswitch:ping',
            'freeswitch:status',
            'freeswitch:worker',
            'freeswitch:worker:status',
            'freeswitch:worker:checkpoint-status',
            'freeswitch:health',
            'freeswitch:replay:inspect',
            'freeswitch:validate-install',
        ] as $command) {
            $commandChecks[$command] = array_key_exists($command, $kernel->all());
        }

        $metricsDriver = (string) $config->get('freeswitch-esl.observability.metrics.driver', 'log');
        $metrics->increment('freeswitch_esl.install.validation', 1, [
            'driver' => $metricsDriver,
            'surface' => 'validate_install',
        ]);

        $exampleChecks = null;

        if ($this->option('example') === true) {
            $exampleChecks = [
                'provider_freeswitch_present' => PbxProvider::query()->where('code', 'freeswitch')->exists(),
                'node_primary_fs_present' => PbxNode::query()->where('slug', 'primary-fs')->exists(),
                'profile_default_present' => PbxConnectionProfile::query()->where('name', 'default')->exists(),
                'worker_ingest_worker_present' => WorkerAssignment::query()->where('worker_name', 'ingest-worker')->exists(),
            ];
        }

        $passes = $this->allTrue($configChecks)
            && $this->allTrue($schemaChecks)
            && $this->allTrue($commandChecks)
            && ($exampleChecks === null || $this->allTrue($exampleChecks));

        $payload = [
            'report_surface' => 'install_validation',
            'passed' => $passes,
            'config' => [
                'default_driver' => $config->get('freeswitch-esl.default_driver'),
                'metrics_driver' => $metricsDriver,
                'runtime_runner' => $config->get('freeswitch-esl.runtime.runner'),
                'checks' => $configChecks,
            ],
            'schema' => $schemaChecks,
            'bindings' => $bindings,
            'commands' => $commandChecks,
            'metrics' => [
                'driver' => $metricsDriver,
                'recorder_class' => $metrics::class,
                'validation_metric_emitted' => true,
            ],
            'example' => $exampleChecks,
        ];

        if ($this->option('json') === true) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT) ?: '{}');

            return $passes ? self::SUCCESS : self::FAILURE;
        }

        $this->line(sprintf(
            'Config posture: default driver %s; metrics driver %s; runtime runner %s.',
            (string) $payload['config']['default_driver'],
            $metricsDriver,
            (string) $payload['config']['runtime_runner'],
        ));
        $this->line(sprintf(
            'Schema posture: pbx_providers=%s, pbx_nodes=%s, pbx_connection_profiles=%s, worker_assignments=%s.',
            $this->yesNo($schemaChecks['pbx_providers']),
            $this->yesNo($schemaChecks['pbx_nodes']),
            $this->yesNo($schemaChecks['pbx_connection_profiles']),
            $this->yesNo($schemaChecks['worker_assignments']),
        ));
        $this->line(sprintf(
            'Container posture: registry %s; resolver %s; factory %s; assignments %s; health %s; metrics %s; runtime runner %s.',
            class_basename($bindings[PbxRegistryInterface::class]),
            class_basename($bindings[ConnectionResolverInterface::class]),
            class_basename($bindings[ConnectionFactoryInterface::class]),
            class_basename($bindings[WorkerAssignmentResolverInterface::class]),
            class_basename($bindings[HealthReporterInterface::class]),
            class_basename($bindings[MetricsRecorderInterface::class]),
            class_basename($bindings[RuntimeRunnerInterface::class]),
        ));
        $this->line(sprintf(
            'Command posture: %d/%d expected commands are registered.',
            count(array_filter($commandChecks)),
            count($commandChecks),
        ));
        $this->line(sprintf(
            'Observability posture: emitted freeswitch_esl.install.validation through metrics driver %s.',
            $metricsDriver,
        ));

        if ($exampleChecks !== null) {
            $this->line(sprintf(
                'Example seed posture: provider %s; node %s; profile %s; worker %s.',
                $this->yesNo($exampleChecks['provider_freeswitch_present']),
                $this->yesNo($exampleChecks['node_primary_fs_present']),
                $this->yesNo($exampleChecks['profile_default_present']),
                $this->yesNo($exampleChecks['worker_ingest_worker_present']),
            ));
        }

        if ($passes) {
            $this->info('FreeSwitch ESL install validation passed.');

            return self::SUCCESS;
        }

        $this->error('FreeSwitch ESL install validation failed. Use --json to inspect the failing checks.');

        return self::FAILURE;
    }

    /**
     * @param  array<string, bool>  $checks
     */
    private function allTrue(array $checks): bool
    {
        foreach ($checks as $value) {
            if ($value !== true) {
                return false;
            }
        }

        return true;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && $value !== '';
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
