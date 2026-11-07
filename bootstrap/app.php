<?php

use App\Http\Middleware\Access;
use App\Http\Middleware\ForceJsonResponse;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->alias(['access' => Access::class]);
        $m->api(prepend: [ForceJsonResponse::class]);
        $m->preventRequestForgery(except: ['webhooks/evolution/*', 'wp/p', 'wp/i/*']);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->dontFlash(['client_secret', 'token', 'credential', 'delivery_code']);
    })->create();
