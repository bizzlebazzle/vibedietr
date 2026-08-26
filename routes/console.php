<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('administrator:expire-promotions')
    ->hourly()
    ->timezone('UTC')
    ->name('administrator-expire-promotions')
    ->withoutOverlapping(10)
    ->onOneServer();

Schedule::command('queue:prune-operational-failures')
    ->dailyAt('00:15')
    ->timezone('UTC')
    ->name('queue-prune-operational-failures')
    ->withoutOverlapping(10)
    ->onOneServer();
