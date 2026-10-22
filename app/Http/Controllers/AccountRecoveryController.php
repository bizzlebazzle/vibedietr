<?php

namespace App\Http\Controllers;

use App\Domain\AccountDeletion\AccountLifecycle;
use App\Domain\AccountDeletion\AuthenticateLifecycle;
use App\Domain\AccountDeletion\JournalUnavailable;
use App\Jobs\ProcessAccountDeletion;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class AccountRecoveryController extends Controller
{
    public function show(): View
    {
        return view('account-recovery');
    }

    public function store(Request $request, AuthenticateLifecycle $authentication, AccountLifecycle $lifecycle): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'code' => ['nullable', 'string', 'max:12'],
            'operation' => ['required', 'in:recover,waive'],
            'confirmed' => ['accepted'],
        ]);
        $user = User::query()->where('email', $validated['email'])->first();
        if (! $user || $user->isActive()) {
            throw ValidationException::withMessages(['password' => 'The account credentials could not be accepted.']);
        }
        $authentication->verify($user, $validated['password'], $validated['code'] ?? '', 'account.'.$validated['operation'], (string) $request->ip());
        try {
            if ($validated['operation'] === 'recover') {
                $lifecycle->recover($user);
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                Auth::login($user);
                $request->session()->regenerate();

                return redirect()->route('dashboard')->with('status', 'Your account has been recovered. Permanently removed plans remain unavailable; their private history is included in your account export.');
            }
            $lifecycle->waive($user);
            $record = $user->accountDeletion()->firstOrFail();
            ProcessAccountDeletion::dispatch($record->id, $record->correlation_id);

            return redirect()->route('account-recovery')->with('status', 'Recovery has been waived. Final purge is queued and this account cannot be recovered.');
        } catch (JournalUnavailable) {
            throw ValidationException::withMessages(['recovery' => 'Lifecycle confirmation is unavailable. Your original deadline is preserved; please retry.']);
        }
    }
}
