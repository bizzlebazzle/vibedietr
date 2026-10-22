<?php

namespace Tests\Feature;

use App\Jobs\ProcessAccountDeletion;
use App\Models\User;
use App\Security\SecondFactor\RecentAuthentication;
use App\Security\SecondFactor\SecondFactorEnrollmentService;
use App\Security\SecondFactor\TotpEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;
use Tests\Concerns\UsesErasureJournal;
use Tests\TestCase;

class AdministratorAccountDeletionTest extends TestCase
{
    use RefreshDatabase, UsesErasureJournal;

    public function test_sole_administrator_account_deletion_is_denied(): void
    {
        [$administrator, $secret] = $this->enrolledAdministrator();
        $this->actingAs($administrator);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->set('code', app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep()))
            ->call('deleteUser')
            ->assertHasErrors('password');

        $this->assertNotNull($administrator->fresh());
        $this->assertAuthenticatedAs($administrator);
    }

    public function test_administrator_with_active_replacement_can_enter_authenticated_recovery(): void
    {
        [$administrator, $secret] = $this->enrolledAdministrator();
        $replacement = User::factory()->administrator()->create();
        Queue::fake([ProcessAccountDeletion::class]);
        $this->actingAs($administrator);

        Volt::test('profile.delete-user-form')
            ->set('password', 'password')
            ->set('code', app(TotpEngine::class)->codeAt($secret, app(TotpEngine::class)->currentTimestep()))
            ->call('deleteUser')
            ->assertHasNoErrors()
            ->assertRedirect('/account-recovery');

        $this->assertNotNull($administrator->fresh());
        $this->assertFalse($administrator->isActive());
        $this->assertTrue($replacement->isAdministrator());
        $this->assertSame(1, User::query()->where('is_administrator', true)->whereDoesntHave('accountDeletion')->count());
        $this->assertGuest();
        Queue::assertPushed(ProcessAccountDeletion::class);
    }

    /** @return array{User, string} */
    private function enrolledAdministrator(): array
    {
        $this->freezeSecond();
        config(['administrator-security.verification.delay_seconds' => [0, 0, 0, 0, 0]]);
        $user = User::factory()->create(['password' => 'password']);
        $service = app(SecondFactorEnrollmentService::class);
        $presentation = $service->begin($user, 'password', app(RecentAuthentication::class), app('session.store'));
        $engine = app(TotpEngine::class);
        $service->confirm($user, $engine->codeAt($presentation->manualKey, $engine->currentTimestep()), '192.0.2.5');
        $service->acknowledgeRecoveryCodes($user);
        $user->forceFill(['is_administrator' => true])->save();
        $this->travel(30)->seconds();

        return [$user, $presentation->manualKey];
    }
}
