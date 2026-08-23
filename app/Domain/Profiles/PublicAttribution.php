<?php

namespace App\Domain\Profiles;

use App\Models\PublicProfile;
use App\Models\Recipe;
use App\Models\RecipeVersion;

final readonly class PublicAttribution
{
    private function __construct(
        public string $name,
        public ?string $profileId,
    ) {}

    public static function fromVersion(Recipe $recipe, RecipeVersion $version): ?self
    {
        $name = $version->public_attribution_name;
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        $profileId = PublicProfile::query()
            ->where('user_id', $recipe->user_id)
            ->where('profile_enabled', true)
            ->value('id');

        return new self(
            name: trim($name),
            profileId: is_string($profileId) ? $profileId : null,
        );
    }

    /** @return array{name: string, profile_id: string|null} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'profile_id' => $this->profileId,
        ];
    }
}
