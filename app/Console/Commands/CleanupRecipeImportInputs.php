<?php

namespace App\Console\Commands;

use App\Domain\RecipeImports\RecipeImportRetention;
use Illuminate\Console\Command;

final class CleanupRecipeImportInputs extends Command
{
    protected $signature = 'recipe-imports:cleanup-transient';

    protected $description = 'Remove expired transient files and minimize terminal processing metadata on their original clocks';

    public function handle(RecipeImportRetention $retention): int
    {
        $result = $retention->run();
        $this->components->info("Cleaned {$result['sources']} transient input(s) and {$result['metadata']} metadata record(s) minimized.");

        return $result['invalid_clocks'] === 0 && $result['cleanup_failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
