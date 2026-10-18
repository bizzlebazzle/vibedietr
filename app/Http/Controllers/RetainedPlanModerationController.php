<?php

namespace App\Http\Controllers;

use App\Domain\AccountDeletion\AuthenticateLifecycle;
use App\Domain\AccountDeletion\ModerateRetainedPlan;
use App\Security\SecondFactor\RecentAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class RetainedPlanModerationController extends Controller
{
    public function __invoke(Request $request, int $mealPlan, ModerateRetainedPlan $moderation): RedirectResponse
    {
        abort_unless($request->user()->isAdministrator(), 403);
        $data = $request->validate(['password' => ['required', 'string', 'max:1024'], 'code' => ['required', 'string', 'size:6'], 'action' => ['required', 'in:suppress,remove'], 'reason' => ['required', 'in:privacy,security,legal,moderation']]);
        $user = $request->user();
        app(AuthenticateLifecycle::class)->verify($user, $data['password'], $data['code'], 'retained-plan.'.$data['action'], (string) $request->ip());
        $authentication = app(RecentAuthentication::class);
        abort_unless($authentication->confirmPrimary($user, $data['password'], $request->session()), 403);
        $authentication->rememberFreshFactor($user, 'retained-plan.'.$data['action'], $request->session());
        $moderation->apply($request->user(), $mealPlan, $data['action'], $data['reason'], $request->session());

        return redirect()->route('dashboard')->with('status', 'Retained plan '.$data['action'].' action completed.');
    }
}
