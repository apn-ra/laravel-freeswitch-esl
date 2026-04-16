<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing a worker's targeting scope.
 *
 * Assignment modes control which PBX nodes the worker will manage:
 *   - 'node'       : single PBX node by ID or slug
 *   - 'cluster'    : all active nodes in a named cluster
 *   - 'tag'        : all active nodes matching a tag
 *   - 'provider'   : all active nodes for a provider code
 *   - 'all-active' : all currently active PBX nodes
 */
final class WorkerAssignment
{
    public const MODE_NODE = 'node';
    public const MODE_CLUSTER = 'cluster';
    public const MODE_TAG = 'tag';
    public const MODE_PROVIDER = 'provider';
    public const MODE_ALL_ACTIVE = 'all-active';

    public const VALID_MODES = [
        self::MODE_NODE,
        self::MODE_CLUSTER,
        self::MODE_TAG,
        self::MODE_PROVIDER,
        self::MODE_ALL_ACTIVE,
    ];

    public function __construct(
        public readonly ?int $id,
        public readonly string $workerName,
        public readonly string $assignmentMode,
        public readonly ?int $pbxNodeId = null,
        public readonly ?string $providerCode = null,
        public readonly ?string $cluster = null,
        public readonly ?string $tag = null,
        public readonly bool $isActive = true,
    ) {
        if (! in_array($assignmentMode, self::VALID_MODES, true)) {
            throw new \InvalidArgumentException(
                "Invalid assignment mode '{$assignmentMode}'. Valid modes: " . implode(', ', self::VALID_MODES)
            );
        }
    }

    /**
     * Create from a database record array.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            id: isset($record['id']) ? (int) $record['id'] : null,
            workerName: (string) $record['worker_name'],
            assignmentMode: (string) $record['assignment_mode'],
            pbxNodeId: isset($record['pbx_node_id']) ? (int) $record['pbx_node_id'] : null,
            providerCode: isset($record['provider_code']) ? (string) $record['provider_code'] : null,
            cluster: isset($record['cluster']) ? (string) $record['cluster'] : null,
            tag: isset($record['tag']) ? (string) $record['tag'] : null,
            isActive: (bool) ($record['is_active'] ?? true),
        );
    }

    /**
     * Create a node-scoped assignment for the given PBX node ID.
     */
    public static function forNode(string $workerName, int $pbxNodeId): self
    {
        return new self(
            id: null,
            workerName: $workerName,
            assignmentMode: self::MODE_NODE,
            pbxNodeId: $pbxNodeId,
        );
    }

    /**
     * Create a cluster-scoped assignment.
     */
    public static function forCluster(string $workerName, string $cluster): self
    {
        return new self(
            id: null,
            workerName: $workerName,
            assignmentMode: self::MODE_CLUSTER,
            cluster: $cluster,
        );
    }

    /**
     * Create a tag-scoped assignment.
     */
    public static function forTag(string $workerName, string $tag): self
    {
        return new self(
            id: null,
            workerName: $workerName,
            assignmentMode: self::MODE_TAG,
            tag: $tag,
        );
    }

    /**
     * Create a provider-scoped assignment.
     */
    public static function forProvider(string $workerName, string $providerCode): self
    {
        return new self(
            id: null,
            workerName: $workerName,
            assignmentMode: self::MODE_PROVIDER,
            providerCode: $providerCode,
        );
    }

    /**
     * Create an all-active assignment.
     */
    public static function allActive(string $workerName): self
    {
        return new self(
            id: null,
            workerName: $workerName,
            assignmentMode: self::MODE_ALL_ACTIVE,
        );
    }
}
