<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Unit\ControlPlane\ValueObjects;

use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionProfile;
use PHPUnit\Framework\TestCase;

class ConnectionProfileTest extends TestCase
{
    public function test_from_record_decodes_json_fields(): void
    {
        $profile = ConnectionProfile::fromRecord([
            'id' => 1,
            'provider_id' => 1,
            'name' => 'default',
            'retry_policy_json' => '{"max_attempts":5,"initial_delay_ms":1000}',
            'drain_policy_json' => null,
            'subscription_profile_json' => null,
            'replay_policy_json' => null,
            'normalization_profile_json' => null,
            'worker_profile_json' => null,
            'settings_json' => null,
        ]);

        $this->assertSame(5, $profile->retryPolicy['max_attempts']);
        $this->assertSame(1000, $profile->retryPolicy['initial_delay_ms']);
        $this->assertSame([], $profile->drainPolicy);
    }

    public function test_from_config_defaults_creates_valid_profile(): void
    {
        $profile = ConnectionProfile::fromConfigDefaults(
            retryDefaults: ['max_attempts' => 3],
            drainDefaults: ['timeout_ms' => 5000],
        );

        $this->assertNull($profile->id);
        $this->assertSame('default', $profile->name);
        $this->assertSame(3, $profile->retryPolicy['max_attempts']);
        $this->assertSame(5000, $profile->drainPolicy['timeout_ms']);
    }

    public function test_with_retry_policy_returns_new_instance(): void
    {
        $original = ConnectionProfile::fromConfigDefaults(['max_attempts' => 3]);
        $modified = $original->withRetryPolicy(['max_attempts' => 10]);

        $this->assertSame(3, $original->retryPolicy['max_attempts']);
        $this->assertSame(10, $modified->retryPolicy['max_attempts']);
        $this->assertNotSame($original, $modified);
    }

    public function test_handles_null_json_gracefully(): void
    {
        $profile = ConnectionProfile::fromRecord([
            'id' => null,
            'provider_id' => null,
            'name' => 'fallback',
            'retry_policy_json' => null,
            'drain_policy_json' => null,
            'subscription_profile_json' => null,
            'replay_policy_json' => null,
            'normalization_profile_json' => null,
            'worker_profile_json' => null,
            'settings_json' => null,
        ]);

        $this->assertSame([], $profile->retryPolicy);
        $this->assertSame([], $profile->drainPolicy);
    }
}
