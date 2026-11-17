<?php

namespace App\Http\Middleware;

use App\Services\LoginDestination;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RememberScreen
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && $request->user() && ! $request->ajax() && ! $request->expectsJson()
            && $response->isSuccessful() && str_starts_with($response->headers->get('Content-Type', ''), 'text/html')
            && ! $response->headers->has('Content-Disposition') && ! $request->is('*/print', '*/export', '*/download')
            && ($path = app(LoginDestination::class)->path($request, $request->getRequestUri()))) {
            $user = $request->user();
            $response->headers->setCookie(cookie(LoginDestination::COOKIE, json_encode([
                'user_id' => $user->id, 'tenant_id' => $user->tenant_id, 'role' => $user->role, 'path' => $path,
            ], JSON_THROW_ON_ERROR), 43200, '/', null, $request->isSecure(), true, false, 'lax'));
        }

        return $response;
    }
}
