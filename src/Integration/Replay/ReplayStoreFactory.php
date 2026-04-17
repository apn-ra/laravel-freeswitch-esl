<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use Apntalk\EslReplay\Config\ReplayConfig;
use Apntalk\EslReplay\Config\StorageConfig;
use Apntalk\EslReplay\Contracts\ReplayArtifactStoreInterface;
use Apntalk\EslReplay\Storage\ReplayArtifactStore;

final class ReplayStoreFactory
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function make(array $config): ReplayArtifactStoreInterface
    {
        $adapter = $this->normalizeAdapter((string) ($config['store_driver'] ?? 'database'));
        $storagePath = $this->storagePath($config, $adapter);

        return ReplayArtifactStore::make(
            new ReplayConfig(
                new StorageConfig(
                    storagePath: $storagePath,
                    adapter: $adapter,
                ),
            ),
        );
    }

    private function normalizeAdapter(string $driver): string
    {
        return match ($driver) {
            'database', 'sqlite' => StorageConfig::ADAPTER_SQLITE,
            'filesystem', 'file' => StorageConfig::ADAPTER_FILESYSTEM,
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported freeswitch-esl.replay.store_driver [%s]. Supported values are [database, sqlite, filesystem].',
                $driver,
            )),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function storagePath(array $config, string $adapter): string
    {
        $path = trim((string) ($config['storage_path'] ?? ''));

        if ($path === '') {
            throw new \InvalidArgumentException(
                'freeswitch-esl.replay.storage_path must not be empty when replay is enabled.',
            );
        }

        if ($adapter !== StorageConfig::ADAPTER_SQLITE) {
            return $path;
        }

        if (str_ends_with($path, '.sqlite') || str_ends_with($path, '.db')) {
            return $path;
        }

        return rtrim($path, '/\\') . DIRECTORY_SEPARATOR . 'replay.sqlite';
    }
}
