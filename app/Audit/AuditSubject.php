<?php

namespace App\Audit;

use App\Audit\Enums\AuditSubjectType;
use App\Models\User;
use InvalidArgumentException;

final readonly class AuditSubject
{
    private function __construct(
        public AuditSubjectType $type,
        public ?User $user = null,
        public ?string $identifier = null,
    ) {}

    public static function user(User $user): self
    {
        if (! $user->exists || $user->getKey() === null) {
            throw new InvalidArgumentException('An audit user subject must already be persisted.');
        }

        return new self(AuditSubjectType::UserAccount, user: $user);
    }

    public static function resource(AuditSubjectType $type, int|string $identifier): self
    {
        if ($type === AuditSubjectType::UserAccount) {
            throw new InvalidArgumentException('User subjects must use the erasable identity mapping.');
        }

        return new self(
            $type,
            identifier: AuditReferenceValidator::validate((string) $identifier, 'subject identifier'),
        );
    }

    public static function system(): self
    {
        return new self(AuditSubjectType::SystemOperation);
    }
}
