<?php

use App\Http\Middleware\Access;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\RememberScreen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', api: __DIR__.'/../routes/api.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $m): void {
        $m->alias(['access' => Access::class]);
        $m->api(prepend: [ForceJsonResponse::class]);
        $m->web(append: [RememberScreen::class]);
        $m->preventRequestForgery(except: ['webhooks/evolution/*', 'wp/p', 'wp/i/*']);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->dontFlash(['client_secret', 'token', 'credential', 'delivery_code']);
        $e->respond(function (Response $response, Throwable $exception, Request $request) {
            if ($response->getStatusCode() === 419 && ! $request->expectsJson() && ! $request->user()
                && (in_array('auth', $request->route()?->gatherMiddleware() ?? [], true) || $request->is('login'))) {
                return redirect()->route('login')->with('success', 'Sua sessão expirou. Entre novamente para voltar à tela anterior. A última ação não foi reenviada.');
            }

            return $response;
        });
    })->create();
