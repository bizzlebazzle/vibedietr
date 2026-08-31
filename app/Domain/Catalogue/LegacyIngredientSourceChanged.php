<?php

namespace App\Domain\Catalogue;

use RuntimeException;

final class LegacyIngredientSourceChanged extends RuntimeException
{
    public function __construct(public readonly int $ingredientId)
    {
        parent::__construct('A mapped legacy ingredient changed after backfill.');
    }
}
