<?php

namespace App\Domain\Ingredients;

use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupResult;
use App\Integrations\OpenFoodFacts\OpenFoodFactsLookupStatus;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PendingIngredientImportStore
{
    private const LIFETIME_MINUTES = 15;

    public function remember(
        User $user,
        ?int $ingredientId,
        string $requestedBarcode,
        OpenFoodFactsLookupResult $result,
    ): string {
        if ($result->status !== OpenFoodFactsLookupStatus::Success || $result->product === null) {
            throw new InvalidArgumentException('Only a successful provider result can establish pending import state.');
        }

        $token = Str::random(40);

        session()->put($this->key($token), [
            'user_id' => (int) $user->getKey(),
            'ingredient_id' => $ingredientId,
            'requested_barcode' => $requestedBarcode,
            'result' => $result,
            'expires_at' => Date::now()->addMinutes(self::LIFETIME_MINUTES)->getTimestamp(),
        ]);

        return $token;
    }

    public function get(string $token, User $user, ?int $ingredientId): ?PendingIngredientImport
    {
        $stored = session()->get($this->key($token));

        if (! is_array($stored)
            || ($stored['user_id'] ?? null) !== (int) $user->getKey()
            || ($stored['ingredient_id'] ?? null) !== $ingredientId
            || ! is_string($stored['requested_barcode'] ?? null)
            || ! ($stored['result'] ?? null) instanceof OpenFoodFactsLookupResult
            || ! is_int($stored['expires_at'] ?? null)
            || $stored['expires_at'] < Date::now()->getTimestamp()
        ) {
            $this->forget($token);

            return null;
        }

        return new PendingIngredientImport(
            requestedBarcode: $stored['requested_barcode'],
            result: $stored['result'],
        );
    }

    public function forget(?string $token): void
    {
        if ($token !== null && $token !== '') {
            session()->forget($this->key($token));
        }
    }

    private function key(string $token): string
    {
        return "ingredients.pending_import.{$token}";
    }
}
