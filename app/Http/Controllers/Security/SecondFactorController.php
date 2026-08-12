<?php

namespace App\Http\Controllers\Security;

use App\Http\Controllers\Controller;
use App\Security\Notifications\SecurityEventType;
use App\Security\Notifications\SecurityNotificationIntentService;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\SecondFactorResult;
use App\Security\SecondFactor\SecondFactorVerifier;
use App\Security\SecurityAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

final class SecondFactorController extends Controller
{
    public function show(Request $request): View
    {
        return view('security.second-factor', ['enrolled' => $request->user()->hasConfirmedSecondFactor()]);
    }

    public function begin(Request $request, SecondFactorEnrollmentService $enrollment, RecentAuthentication $authentication): View
    {
        $validated = $request->validate(['password' => ['required', 'string']]);

        try {
            $presentation = $enrollment->begin($request->user(), $validated['password'], $authentication, $request->session());
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['password' => $exception->getMessage()]);
        }

        return view('security.second-factor-confirm', ['presentation' => $presentation]);
    }

    public function confirm(Request $request, SecondFactorEnrollmentService $enrollment): View
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $result = $enrollment->confirm($request->user(), $validated['code'], (string) $request->ip());

        if ($result instanceof SecondFactorResult) {
            throw ValidationException::withMessages(['code' => 'The code could not be accepted ('.$result->status->value.').']);
        }

        return view('security.second-factor-recovery-codes', ['codes' => $result->codes]);
    }

    public function acknowledge(
        Request $request,
        SecondFactorEnrollmentService $enrollment,
        SecurityAuditService $audit,
        SecurityNotificationIntentService $notifications,
    ): RedirectResponse {
        $request->validate(['acknowledged' => ['accepted']]);
        $enrollment->acknowledgeRecoveryCodes($request->user(), $request->session());
        $correlationId = strtolower((string) \Illuminate\Support\Str::ulid());
        $audit->factor($request->user(), $request->user(), 'enrollment_confirmed', 'completed', 'enrollment', $correlationId);
        $notifications->create(SecurityEventType::FactorEnrollmentCompleted, $request->user(), $correlationId);

        return redirect()->route('security.second-factor.show')->with('status', 'Two-step verification is enabled.');
    }

    public function verify(Request $request, SecondFactorVerifier $verifier, RecentAuthentication $authentication): RedirectResponse
    {
        $validated = $request->validate(['code' => ['required', 'string', 'size:6'], 'operation' => ['required', 'string', 'max:64']]);
        $result = $verifier->verify($request->user(), $validated['code'], $validated['operation'], (string) $request->ip());

        if (! $result->succeeded()) {
            throw ValidationException::withMessages(['code' => 'The code could not be accepted ('.$result->status->value.').']);
        }

        $authentication->rememberFreshFactor($request->user(), $validated['operation'], $request->session());

        return back()->with('status', 'Fresh second-factor verification recorded.');
    }

    public function cancel(Request $request, SecondFactorEnrollmentService $enrollment): RedirectResponse
    {
        $enrollment->cancel($request->user());

        return redirect()->route('security.second-factor.show');
    }
}
