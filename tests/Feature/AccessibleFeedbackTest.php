<?php

namespace Tests\Feature;

use App\Livewire\Ingredients\Form;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Livewire\Livewire;
use Tests\TestCase;

class AccessibleFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_notices_and_validation_have_distinct_semantics_and_text(): void
    {
        $status = Blade::render('<x-auth-session-status status="Changes saved" />');
        $this->assertStringContainsString('role="status"', $status);
        $this->assertStringContainsString('aria-live="polite"', $status);
        $this->assertStringContainsString('Changes saved', $status);

        $errors = new ViewErrorBag;
        $errors->put('default', new MessageBag(['name' => 'A name is required.']));
        $summary = Blade::render('<x-validation-summary :errors="$errors" />', ['errors' => $errors]);
        $this->assertStringContainsString('role="alert"', $summary);
        $this->assertStringContainsString('data-validation-summary', $summary);
        $this->assertStringContainsString('tabindex="-1"', $summary);
        $this->assertStringContainsString('Validation errors', $summary);
        $this->assertStringContainsString('A name is required.', $summary);

        $error = Blade::render('<x-input-error :messages="[\'A name is required.\']" />');
        $this->assertStringContainsString('data-feedback-error', $error);
        $this->assertStringContainsString('A name is required.', $error);
    }

    public function test_modal_has_accessible_name_keyboard_handling_and_focus_restoration(): void
    {
        $modal = Blade::render('<x-modal name="confirm-user-deletion" title="Delete account">Deletion cannot be undone.</x-modal>');
        $this->assertStringContainsString('role="dialog"', $modal);
        $this->assertStringContainsString('aria-modal="true"', $modal);
        $this->assertStringContainsString('aria-labelledby="confirm-user-deletion-title"', $modal);
        $this->assertStringContainsString('Deletion cannot be undone.', $modal);
        $this->assertStringContainsString('x-on:keydown.escape.window=', $modal);
        $this->assertStringContainsString('x-on:keydown.tab=', $modal);
        $this->assertStringContainsString('this.opener.focus()', $modal);
    }

    public function test_ingredient_validation_renders_summary_field_marker_and_loading_feedback(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Form::class)
            ->call('save')
            ->assertHasErrors('name')
            ->assertSee('data-validation-summary', false)
            ->assertSee('data-feedback-error', false)
            ->assertSee('wire:loading.attr="aria-busy"', false)
            ->assertSee('Saving…');
    }

    public function test_account_deletion_confirmation_explains_consequences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile'))
            ->assertOk()
            ->assertSee('Delete account')
            ->assertSee('all of its resources and data will be permanently deleted')
            ->assertSee('aria-labelledby="confirm-user-deletion-title"', false);
    }
}
