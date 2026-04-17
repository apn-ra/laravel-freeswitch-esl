<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use Apntalk\EslReplay\Checkpoint\FilesystemCheckpointStore;
use Apntalk\EslReplay\Config\CheckpointConfig;
use Apntalk\EslReplay\Contracts\ReplayCheckpointStoreInterface;

final class ReplayCheckpointStoreFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config): ReplayCheckpointStoreInterface
    {
        return FilesystemCheckpointStore::make(
            new CheckpointConfig(
                storagePath: $this->storagePath($config),
                checkpointKey: 'laravel-freeswitch-esl',
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function storagePath(array $config): string
    {
        $configured = trim((string) ($config['checkpoint_storage_path'] ?? ''));

        if ($configured !== '') {
            return $configured;
        }

        $storagePath = trim((string) ($config['storage_path'] ?? ''));

        if ($storagePath === '') {
            throw new \InvalidArgumentException(
                'freeswitch-esl.replay.checkpoint_storage_path or replay.storage_path must not be empty.',
            );
        }

        if (str_ends_with($storagePath, '.sqlite') || str_ends_with($storagePath, '.db')) {
            return dirname($storagePath).DIRECTORY_SEPARATOR.'checkpoints';
        }

        return rtrim($storagePath, '/\\').DIRECTORY_SEPARATOR.'checkpoints';
    }
}
