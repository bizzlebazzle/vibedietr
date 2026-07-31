<?php

namespace App\Audit;

use App\Models\AuditActorIdentity;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AuditActorIdentityEraser
{
    public function eraseForUser(User|int $user): int
    {
        $userId = $user instanceof User ? $user->getKey() : $user;

        return DB::table((new AuditActorIdentity)->getTable())
            ->where('user_id', $userId)
            ->delete();
    }

    public function eraseExternalReference(string $reference): int
    {
        $reference = AuditReferenceValidator::validate($reference, 'external actor reference');

        return DB::table((new AuditActorIdentity)->getTable())
            ->where('external_reference', $reference)
            ->delete();
    }
}
