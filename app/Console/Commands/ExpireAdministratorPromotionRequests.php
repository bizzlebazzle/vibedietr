<?php

namespace App\Console\Commands;

use App\Administrator\ExpireAdministratorPromotions;
use Illuminate\Console\Command;

final class ExpireAdministratorPromotionRequests extends Command
{
    protected $signature = 'administrator:expire-promotions';

    protected $description = 'Finalize expired administrator promotion requests';

    public function handle(ExpireAdministratorPromotions $expiry): int
    {
        $this->components->info($expiry->execute().' administrator promotion request(s) expired.');

        return self::SUCCESS;
    }
}
