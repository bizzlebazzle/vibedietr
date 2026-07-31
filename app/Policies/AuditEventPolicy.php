<?php

namespace App\Policies;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AuditEventPolicy
{
    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, AuditEvent $auditEvent): bool
    {
        return Gate::forUser($user)->allows('access-admin')
            && $auditEvent->purpose->isAdministratorReadable();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }

    public function delete(User $user, AuditEvent $auditEvent): bool
    {
        return false;
    }
}
