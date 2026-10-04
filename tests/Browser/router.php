<?php

// Dedicated testing server; never used by the normal application entry point.
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
abort_unless($app->environment('testing') && config('database.connections.mysql.database') === 'testing', 403);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$public = realpath(__DIR__.'/../../public');
$file = is_string($path) ? realpath($public.$path) : false;
if ($file && str_starts_with($file, $public.'/') && is_file($file)) {
    return false;
}

// Use the production build even when the developer has a running Vite server.
Vite::useHotFile(sys_get_temp_dir().'/vibedietr-browser-no-hot');
$app->handleRequest(Request::capture());
