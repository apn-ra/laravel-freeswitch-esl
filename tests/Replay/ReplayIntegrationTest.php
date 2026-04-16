<?php

namespace ApnTalk\LaravelFreeswitchEsl\Tests\Replay;

use ApnTalk\LaravelFreeswitchEsl\Tests\TestCase;

/**
 * Replay integration tests.
 *
 * These tests cover the Laravel-side wiring of apntalk/esl-replay:
 *   - replay capture store binding and container resolution
 *   - retention policy enforcement
 *   - session/correlation metadata propagation into captured envelopes
 *   - replay inspection command output
 *   - partitioning by provider, PBX node, worker session, and time window
 *
 * ---
 * STATUS: Planned for 0.5.x (integrate apntalk/esl-replay).
 * ---
 *
 * All tests are skipped until apntalk/esl-replay is integrated.
 * When the package is available:
 *   1. Remove the skip calls
 *   2. Implement the ReplayCaptureStoreInterface binding in the test setUp()
 *   3. Assert capture, retrieval, and partitioning behavior
 */
class ReplayIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->markTestSkipped(
            'Replay integration tests require apntalk/esl-replay (planned for 0.5.x). '
            . 'Remove this skip once that package is integrated.'
        );
    }

    public function test_replay_capture_store_can_be_bound_in_container(): void
    {
        // When esl-replay is available:
        // $store = $this->app->make(ReplayCaptureStoreInterface::class);
        // $this->assertInstanceOf(ReplayCaptureStoreInterface::class, $store);
        $this->fail('Not yet implemented — remove setUp skip when esl-replay is integrated.');
    }

    public function test_capture_envelope_carries_runtime_identity(): void
    {
        // When esl-replay is available:
        // Assert that captured envelopes include provider_code, pbx_node_id,
        // pbx_node_slug, worker_session_id, connection_profile_name.
        $this->fail('Not yet implemented — remove setUp skip when esl-replay is integrated.');
    }

    public function test_retrieve_returns_envelopes_within_time_window(): void
    {
        // When esl-replay is available:
        // Capture N envelopes, retrieve within a time window, assert count and identity.
        $this->fail('Not yet implemented — remove setUp skip when esl-replay is integrated.');
    }

    public function test_partitioning_by_pbx_node_slug_is_enforced(): void
    {
        // When esl-replay is available:
        // Capture for node A and node B, assert retrieval by partition key is isolated.
        $this->fail('Not yet implemented — remove setUp skip when esl-replay is integrated.');
    }

    public function test_replay_inspect_command_exits_gracefully_when_disabled(): void
    {
        // When esl-replay is available:
        // Assert that the command reports "replay disabled" cleanly.
        $this->fail('Not yet implemented — remove setUp skip when esl-replay is integrated.');
    }
}
