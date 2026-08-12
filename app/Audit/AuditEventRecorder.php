<?php

namespace App\Audit;

use App\Audit\Enums\AuditAction;
use App\Audit\Enums\AuditActorType;
use App\Audit\Enums\AuditIdentityType;
use App\Models\AuditActorIdentity;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AuditEventRecorder
{
    public function __construct(
        private readonly AuditPayloadValidator $payloadValidator,
        private readonly AuditIntegrity $integrity,
    ) {}

    /**
     * Append a trusted application audit event using server-authoritative UTC time.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(
        AuditAction $action,
        AuditActor $actor,
        AuditSubject $subject,
        array $payload,
        ?string $correlationId = null,
        ?string $evidenceReference = null,
    ): AuditEvent {
        if (! in_array($actor->type, $action->allowedActorTypes(), true)) {
            throw new InvalidArgumentException('The actor type is not allowed for this audit action.');
        }

        if (! in_array($subject->type, $action->allowedSubjectTypes(), true)) {
            throw new InvalidArgumentException('The subject type is not allowed for this audit action.');
        }

        $payload = $this->payloadValidator->validate($action, $payload);
        $correlationId = AuditReferenceValidator::validate($correlationId, 'correlation identifier');
        $evidenceReference = AuditReferenceValidator::validate($evidenceReference, 'evidence reference');

        return DB::transaction(function () use (
            $action,
            $actor,
            $subject,
            $payload,
            $correlationId,
            $evidenceReference,
        ): AuditEvent {
            $event = new AuditEvent;

            $event->forceFill([
                'id' => $event->newUniqueId(),
                'action' => $action,
                'purpose' => $action->purpose(),
                'retention_class' => $action->retentionClass(),
                'actor_type' => $actor->type,
                'actor_identity_id' => $this->identityForActor($actor),
                'subject_type' => $subject->type,
                'subject_identity_id' => $this->identityForSubject($subject),
                'subject_identifier' => $subject->identifier,
                'occurred_at' => Date::now()->utc()->toImmutable(),
                'correlation_id' => $correlationId,
                'evidence_reference' => $evidenceReference,
                'schema_version' => 1,
                'payload' => $payload,
            ]);

            $event->integrity_hash = $this->integrity->sign($this->integrity->attributesFor($event));
            $event->save();

            return $event->fresh();
        });
    }

    private function identityForActor(AuditActor $actor): ?string
    {
        if ($actor->user !== null) {
            return $this->identityForUser((int) $actor->user->getKey());
        }

        return match ($actor->type) {
            AuditActorType::ExternalOperator => $this->identityForExternal(
                AuditIdentityType::ExternalOperator,
                $actor->externalReference,
            ),
            AuditActorType::Deployment => $this->identityForExternal(
                AuditIdentityType::Deployment,
                $actor->externalReference,
            ),
            default => null,
        };
    }

    private function identityForSubject(AuditSubject $subject): ?string
    {
        return $subject->user === null
            ? null
            : $this->identityForUser((int) $subject->user->getKey());
    }

    private function identityForUser(int $userId): string
    {
        $identity = AuditActorIdentity::query()->where('user_id', $userId)->first();

        if ($identity !== null) {
            return $identity->getKey();
        }

        return $this->createIdentity([
            'identity_type' => AuditIdentityType::User,
            'user_id' => $userId,
            'external_reference' => null,
        ]);
    }

    private function identityForExternal(AuditIdentityType $type, ?string $reference): string
    {
        if ($reference === null) {
            throw new InvalidArgumentException('An external audit actor requires a reference.');
        }

        $identity = AuditActorIdentity::query()
            ->where('identity_type', $type)
            ->where('external_reference', $reference)
            ->first();

        if ($identity !== null) {
            return $identity->getKey();
        }

        return $this->createIdentity([
            'identity_type' => $type,
            'user_id' => null,
            'external_reference' => $reference,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function createIdentity(array $attributes): string
    {
        $identity = new AuditActorIdentity;
        $identity->forceFill(['id' => $identity->newUniqueId(), ...$attributes]);
        $identity->save();

        return $identity->getKey();
    }
}
