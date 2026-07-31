<?php

namespace App\Audit;

use App\Audit\Enums\AuditActorType;
use App\Models\User;
use InvalidArgumentException;

final readonly class AuditActor
{
    private function __construct(
        public AuditActorType $type,
        public ?User $user = null,
        public ?string $externalReference = null,
    ) {}

    public static function authenticatedUser(User $user): self
    {
        self::requirePersistedUser($user);

        return new self(AuditActorType::AuthenticatedUser, user: $user);
    }

    public static function administrator(User $user): self
    {
        self::requirePersistedUser($user);

        if (! $user->isAdministrator()) {
            throw new InvalidArgumentException('An administrator audit actor must be an administrator.');
        }

        return new self(AuditActorType::Administrator, user: $user);
    }

    public static function system(): self
    {
        return new self(AuditActorType::System);
    }

    public static function externalOperator(string $reference): self
    {
        return new self(
            AuditActorType::ExternalOperator,
            externalReference: AuditReferenceValidator::validate($reference, 'external actor reference'),
        );
    }

    public static function deployment(string $reference): self
    {
        return new self(
            AuditActorType::Deployment,
            externalReference: AuditReferenceValidator::validate($reference, 'deployment reference'),
        );
    }

    private static function requirePersistedUser(User $user): void
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('An audit user actor must already be persisted.');
        }
    }
}
