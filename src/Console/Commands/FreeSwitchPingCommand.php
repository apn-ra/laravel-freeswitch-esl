<?php

namespace ApnTalk\LaravelFreeswitchEsl\Console\Commands;

use ApnTalk\LaravelFreeswitchEsl\Contracts\ConnectionResolverInterface;
use Illuminate\Console\Command;

/**
 * Verify that connection parameters can be resolved for a PBX node.
 *
 * Does NOT open a live TCP connection — it validates that the control-plane
 * resolution pipeline succeeds (registry, secret, driver). Live connection
 * testing requires apntalk/esl-react to be wired.
 */
class FreeSwitchPingCommand extends Command
{
    protected $signature = 'freeswitch:ping
                            {--pbx= : PBX node slug to ping}
                            {--id=  : PBX node ID to ping (alternative to slug)}';

    protected $description = 'Resolve and display connection parameters for a PBX node';

    public function handle(ConnectionResolverInterface $resolver): int
    {
        $slug = $this->stringOption('pbx');
        $id = $this->stringOption('id');

        if ($slug === null && $id === null) {
            $this->error('Provide --pbx=<slug> or --id=<id>.');

            return self::FAILURE;
        }

        try {
            $context = $slug !== null
                ? $resolver->resolveForSlug($slug)
                : $resolver->resolveForNode((int) $id);

            $this->info('Connection context resolved successfully.');
            $this->table(
                ['Key', 'Value'],
                collect($context->toLogContext())
                    ->map(fn ($v, $k) => [$k, is_array($v) ? $this->jsonString($v) : (string) $v])
                    ->values()
                    ->all()
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Resolution failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }


    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<mixed>  $value
     */
    private function jsonString(array $value): string
    {
        return json_encode($value) ?: '[]';
    }
}
