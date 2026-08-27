<?php

namespace App\Domain\RecipeImports;

enum RecipeImportType: string
{
    case PastedText = 'pasted_text';
}
