<?php

namespace App\Models;

use App\Domain\Recipes\RecipeLifecycle;
use App\Domain\Recipes\RecipeVisibility;
use Database\Factories\RecipeFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property RecipeLifecycle $lifecycle
 * @property RecipeVisibility $visibility
 */
class Recipe extends Model
{
    /** @use HasFactory<RecipeFactory> */
    use HasFactory;

    protected $fillable = ['title', 'servings', 'visibility'];

    protected function casts(): array
    {
        return [
            'servings' => 'decimal:2',
            'lifecycle' => RecipeLifecycle::class,
            'visibility' => RecipeVisibility::class,
            'finalized_at' => 'immutable_datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<RecipeIngredientLine, $this> */
    public function ingredientLines(): HasMany
    {
        return $this->hasMany(RecipeIngredientLine::class)->orderBy('position');
    }

    /** @return HasMany<RecipeInstructionSection, $this> */
    public function instructionSections(): HasMany
    {
        return $this->hasMany(RecipeInstructionSection::class)->orderBy('position');
    }

    /** @return HasMany<RecipeInstructionStep, $this> */
    public function instructionSteps(): HasMany
    {
        return $this->hasMany(RecipeInstructionStep::class)->orderBy('position');
    }

    /** @return HasMany<RecipeVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class)->orderBy('version_number');
    }

    /** @return BelongsTo<RecipeVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'current_recipe_version_id');
    }

    /** @return HasOne<RecipeDraftRevision, $this> */
    public function activeRevision(): HasOne
    {
        return $this->hasOne(RecipeDraftRevision::class);
    }

    /** @return HasOne<RecipeImport, $this> */
    public function sourceImport(): HasOne
    {
        return $this->hasOne(RecipeImport::class);
    }

    /** @return HasOne<RecipeRemixLineage, $this> */
    public function remixLineage(): HasOne
    {
        return $this->hasOne(RecipeRemixLineage::class, 'remix_recipe_id');
    }

    /** @return HasMany<PublicRecipeTag, $this> */
    public function publicTags(): HasMany
    {
        return $this->hasMany(PublicRecipeTag::class)->orderBy('name')->orderBy('id');
    }

    /** @return BelongsToMany<ManagedRecipeTerm, $this> */
    public function managedTerms(): BelongsToMany
    {
        return $this->belongsToMany(ManagedRecipeTerm::class, 'managed_recipe_term_recipes')
            ->withTimestamps()->orderBy('category')->orderBy('name')->orderBy('managed_recipe_terms.id');
    }

    /** @return HasMany<ManagedRecipeTermSuggestion, $this> */
    public function managedTermSuggestions(): HasMany
    {
        return $this->hasMany(ManagedRecipeTermSuggestion::class);
    }

    public function isFinalized(): bool
    {
        return $this->getRawOriginal('lifecycle') === RecipeLifecycle::Finalized->value
            && $this->current_recipe_version_id !== null;
    }

    public function isPubliclyViewable(): bool
    {
        return $this->isFinalized()
            && $this->getRawOriginal('visibility') === RecipeVisibility::Public->value;
    }

    public function canBeUsedInPlansFor(?User $user): bool
    {
        if (! $this->isFinalized()) {
            return false;
        }

        return $this->getRawOriginal('visibility') === RecipeVisibility::Public->value
            || ($user !== null && $user->getKey() === $this->user_id);
    }

    /** @param Builder<Recipe> $query */
    public function scopeFinalized(Builder $query): void
    {
        $query->where('lifecycle', RecipeLifecycle::Finalized->value)
            ->whereNotNull('current_recipe_version_id');
    }

    /** @param Builder<Recipe> $query */
    public function scopePubliclyViewable(Builder $query): void
    {
        $query->finalized()
            ->where('visibility', RecipeVisibility::Public->value);
    }

    /** @param Builder<Recipe> $query */
    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->where(function (Builder $visible) use ($user): void {
            $visible->publiclyViewable();

            if ($user !== null) {
                $visible->orWhere('user_id', $user->getKey());
            }
        });
    }
}
