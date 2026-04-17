<?php

namespace ApnTalk\LaravelFreeswitchEsl\Integration\Replay;

use Apntalk\EslCore\Contracts\ReplayEnvelopeInterface;
use Apntalk\EslReplay\Artifact\CapturedArtifactEnvelope;
use ApnTalk\LaravelFreeswitchEsl\ControlPlane\ValueObjects\ConnectionContext;

final class ReplayEnvelopeArtifactAdapter implements CapturedArtifactEnvelope
{
    private readonly \DateTimeImmutable $captureTimestamp;

    public function __construct(
        private readonly ReplayEnvelopeInterface $envelope,
        private readonly ConnectionContext $context,
    ) {
        $timestamp = \DateTimeImmutable::createFromFormat(
            'U.u',
            sprintf('%.6F', $this->envelope->capturedAtMicros() / 1_000_000),
            new \DateTimeZone('UTC'),
        );

        if (! $timestamp instanceof \DateTimeImmutable) {
            throw new \RuntimeException('Unable to convert replay capture timestamp to UTC DateTimeImmutable.');
        }

        $this->captureTimestamp = $timestamp->setTimezone(new \DateTimeZone('UTC'));
    }

    public function getArtifactVersion(): string
    {
        return $this->derivedString('replay-artifact-version') ?? '1';
    }

    public function getArtifactName(): string
    {
        return $this->derivedString('replay-artifact-name') ?? $this->envelope->capturedName();
    }

    public function getCaptureTimestamp(): \DateTimeImmutable
    {
        return $this->captureTimestamp;
    }

    public function getCapturePath(): ?string
    {
        return $this->derivedString('runtime-capture-path');
    }

    public function getConnectionGeneration(): ?string
    {
        return $this->derivedString('runtime-connection-generation');
    }

    public function getSessionId(): ?string
    {
        return $this->envelope->sessionId();
    }

    public function getJobUuid(): ?string
    {
        return $this->protocolFact('job-uuid')
            ?? $this->derivedString('runtime-job-uuid')
            ?? $this->derivedString('job-correlation.job-uuid');
    }

    public function getEventName(): ?string
    {
        return $this->protocolFact('event-name')
            ?? ($this->envelope->capturedType() === 'event' ? $this->envelope->capturedName() : null);
    }

    /**
     * @return array<string, string>
     */
    public function getCorrelationIds(): array
    {
        return array_filter([
            'replay_session_id' => $this->envelope->sessionId(),
            'job_uuid' => $this->getJobUuid(),
            'worker_session_id' => $this->context->workerSessionId,
        ], static fn (?string $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuntimeFlags(): array
    {
        return array_filter([
            'provider_code' => $this->context->providerCode,
            'pbx_node_id' => $this->context->pbxNodeId,
            'pbx_node_slug' => $this->context->pbxNodeSlug,
            'connection_profile_id' => $this->context->connectionProfileId,
            'connection_profile_name' => $this->context->connectionProfileName,
            'worker_session_id' => $this->context->workerSessionId,
            'transport' => $this->context->transport,
            'replay_session_id' => $this->envelope->sessionId(),
            'replay_capture_sequence' => $this->envelope->captureSequence(),
            'replay_protocol_sequence' => $this->envelope->protocolSequence(),
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPayload(): array
    {
        return [
            'captured_type' => $this->envelope->capturedType(),
            'captured_name' => $this->envelope->capturedName(),
            'raw_payload' => $this->envelope->rawPayload(),
            'classifier_context' => $this->envelope->classifierContext(),
            'protocol_facts' => $this->envelope->protocolFacts(),
            'derived_metadata' => $this->envelope->derivedMetadata(),
            'laravel_context' => [
                'provider_code' => $this->context->providerCode,
                'pbx_node_id' => $this->context->pbxNodeId,
                'pbx_node_slug' => $this->context->pbxNodeSlug,
                'connection_profile_id' => $this->context->connectionProfileId,
                'connection_profile_name' => $this->context->connectionProfileName,
                'worker_session_id' => $this->context->workerSessionId,
                'transport' => $this->context->transport,
            ],
        ];
    }

    private function derivedString(string $key): ?string
    {
        $value = $this->envelope->derivedMetadata()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function protocolFact(string $key): ?string
    {
        $value = $this->envelope->protocolFacts()[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
