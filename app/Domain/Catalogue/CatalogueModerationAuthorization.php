<?php

namespace App\Domain\Catalogue;

use App\Models\User;
use App\Security\Notifications\ProductionSecurityReadiness;
use App\Security\SecondFactor\PrivilegedWorkflowGuard;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;

final class CatalogueModerationAuthorization
{
    public const OPERATION = 'catalogue-moderation';

    public function __construct(
        private readonly PrivilegedWorkflowGuard $guard,
        private readonly ProductionSecurityReadiness $readiness,
    ) {}

    public function authorize(User $actor, Session $session, bool $consume = true): void
    {
        Gate::forUser($actor)->authorize('moderate-catalogue');
        $this->readiness->assertReady();
        abort_unless($actor->fresh()?->hasVerifiedEmail() && $this->guard->allows($actor, self::OPERATION, $session, $consume), 403);
    }
}
