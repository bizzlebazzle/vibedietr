<?php

namespace App\Http\Controllers\Security;

use App\Administrator\AdministratorPromotionLifecycle;
use App\Administrator\AdministratorRevocation;
use App\Http\Controllers\Controller;
use App\Models\AdministratorPromotionRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

final class AdministratorLifecycleController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $ownPromotions = AdministratorPromotionRequest::query()->where('target_user_id', $user->getKey())->latest()->get();
        $pending = $user->isAdministrator()
            ? AdministratorPromotionRequest::query()->where('status', 'pending')->with(['target', 'initiator'])->oldest()->get()
            : collect();
        $administrators = $user->isAdministrator()
            ? User::query()->where('is_administrator', true)->orderBy('name')->get()
            : collect();

        return view('security.administrator-lifecycle', compact('ownPromotions', 'pending', 'administrators'));
    }

    public function initiate(Request $request, AdministratorPromotionLifecycle $lifecycle): RedirectResponse
    {
        $validated = $request->validate(['target_user_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->perform(fn () => $lifecycle->initiate($request->user(), User::query()->findOrFail($validated['target_user_id']), $request->session()), 'Promotion request created.');
    }

    public function accept(Request $request, AdministratorPromotionRequest $promotion, AdministratorPromotionLifecycle $lifecycle): RedirectResponse
    {
        return $this->perform(fn () => $lifecycle->accept($request->user(), $promotion, $request->session()), 'Administrator promotion accepted.');
    }

    public function decline(Request $request, AdministratorPromotionRequest $promotion, AdministratorPromotionLifecycle $lifecycle): RedirectResponse
    {
        return $this->perform(fn () => $lifecycle->decline($request->user(), $promotion, $request->session()), 'Administrator promotion declined.');
    }

    public function cancel(Request $request, AdministratorPromotionRequest $promotion, AdministratorPromotionLifecycle $lifecycle): RedirectResponse
    {
        return $this->perform(fn () => $lifecycle->cancel($request->user(), $promotion, $request->session()), 'Administrator promotion cancelled.');
    }

    public function revoke(Request $request, User $user, AdministratorRevocation $revocation): RedirectResponse
    {
        return $this->perform(fn () => $revocation->revoke($request->user(), $user, $request->session()), 'Administrator access revoked.');
    }

    private function perform(callable $operation, string $message): RedirectResponse
    {
        try {
            $operation();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['lifecycle' => $exception->getMessage()]);
        }

        return back()->with('status', $message);
    }
}
