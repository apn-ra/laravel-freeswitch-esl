<?php

namespace ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects;

/**
 * Immutable value object representing a PBX provider family.
 *
 * A provider defines the driver class and capability set for a category of
 * PBX nodes (e.g. all FreeSWITCH nodes). It does not represent a specific
 * server endpoint — that is PbxNode's job.
 */
final class PbxProvider
{
    /**
     * @param  array<string, mixed>  $capabilities
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $driverClass,
        public readonly bool $isActive,
        public readonly array $capabilities = [],
        public readonly array $settings = [],
    ) {}

    /**
     * Create from a database record array.
     *
     * @param  array<string, mixed>  $record
     */
    public static function fromRecord(array $record): self
    {
        return new self(
            id: (int) $record['id'],
            code: (string) $record['code'],
            name: (string) $record['name'],
            driverClass: (string) $record['driver_class'],
            isActive: (bool) $record['is_active'],
            capabilities: isset($record['capabilities_json'])
                ? (array) json_decode((string) $record['capabilities_json'], true)
                : [],
            settings: isset($record['settings_json'])
                ? (array) json_decode((string) $record['settings_json'], true)
                : [],
        );
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities, true)
            || array_key_exists($capability, $this->capabilities);
    }
}
