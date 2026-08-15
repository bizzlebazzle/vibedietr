<?php

namespace App\Domain\Recipes;

use RuntimeException;

final class StaleRecipeDraft extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This draft changed elsewhere after you opened the editor. Your unsaved changes are still here; reload when you are ready to review the newer version.');
    }
}
