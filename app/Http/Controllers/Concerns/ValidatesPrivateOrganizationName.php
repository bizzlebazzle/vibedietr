<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

trait ValidatesPrivateOrganizationName
{
    protected function validatedName(Request $request, User $owner, string $table, ?int $ignoreId = null): string
    {
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'user_id' => ['prohibited'],
            'owner_id' => ['prohibited'],
            'normalized_name' => ['prohibited'],
        ]);
        $name = $validated['name'];

        Validator::make(
            ['name' => mb_strtolower($name)],
            ['name' => [
                Rule::unique($table, 'normalized_name')
                    ->where(fn ($query) => $query->where('user_id', $owner->getKey()))
                    ->ignore($ignoreId),
            ]],
            ['name.unique' => 'You already have an item with this name.'],
        )->validate();

        return $name;
    }
}
