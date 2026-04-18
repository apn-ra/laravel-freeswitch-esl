<?php

namespace ApnTalk\LaravelFreeswitchEsl\Exceptions;

class WorkerException extends FreeSwitchEslException
{
    public static function noNodesResolved(string $workerName): self
    {
        return new self("Worker [{$workerName}] resolved zero target PBX nodes. Nothing to run.");
    }

    public static function bootFailed(string $workerName, string $reason): self
    {
        return new self("Worker [{$workerName}] failed to boot: {$reason}");
    }

    public static function inflightRejected(string $workerName, string $pbxNodeSlug, string $reason): self
    {
        return new self(sprintf(
            'Worker [%s] rejected inflight work for PBX node [%s]: %s',
            $workerName,
            $pbxNodeSlug,
            $reason,
        ));
    }
}
