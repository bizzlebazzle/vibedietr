<?php

namespace App\Models;

use App\Domain\Recipes\ManagedRecipeTermSuggestionSource;
use App\Domain\Recipes\ManagedRecipeTermSuggestionStatus;
use Database\Factories\ManagedRecipeTermSuggestionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property ManagedRecipeTermSuggestionSource $source
 * @property ManagedRecipeTermSuggestionStatus $status
 */
class ManagedRecipeTermSuggestion extends Model
{
    /** @use HasFactory<ManagedRecipeTermSuggestionFactory> */
    use HasFactory, HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'source' => ManagedRecipeTermSuggestionSource::class,
            'status' => ManagedRecipeTermSuggestionStatus::class,
            'decided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Recipe, $this> */
    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    /** @return BelongsTo<ManagedRecipeTerm, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(ManagedRecipeTerm::class, 'managed_recipe_term_id');
    }

    /** @return BelongsTo<User, $this> */
    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by_user_id');
    }
}
