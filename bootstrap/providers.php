<?php

use App\Providers\AdministratorSecurityServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\SecurityControlServiceProvider;
use App\Providers\VoltServiceProvider;

return [
    AdministratorSecurityServiceProvider::class,
    AppServiceProvider::class,
    VoltServiceProvider::class,
    SecurityControlServiceProvider::class,
];
