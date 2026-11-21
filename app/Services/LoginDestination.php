<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class LoginDestination
{
    public const COOKIE = 'last_work_screen';

    public function path(Request $request, mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > 1800 || preg_match('/[\\\\\x00-\x1f\x7f]/', rawurldecode($value))) {
            return null;
        }
        if (str_starts_with($value, $request->getSchemeAndHttpHost().'/')) {
            $value = substr($value, strlen($request->getSchemeAndHttpHost()));
        }
        if (! str_starts_with($value, '/') || str_starts_with($value, '//') || str_starts_with(rawurldecode($value), '//')) {
            return null;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($value, 'GET'));
        } catch (HttpExceptionInterface) {
            return null;
        }

        return in_array('auth', $route->gatherMiddleware(), true) && $route->uri() !== 'app' ? $value : null;
    }

    public function afterLogin(Request $request): string
    {
        $intended = $this->path($request, $request->session()->pull('url.intended'));
        $saved = json_decode((string) $request->cookie(self::COOKIE), true);
        $remembered = is_array($saved)
            && ($saved['user_id'] ?? null) === $request->user()->id
            && ($saved['tenant_id'] ?? null) === $request->user()->tenant_id
            && ($saved['role'] ?? null) === $request->user()->role
            ? $this->path($request, $saved['path'] ?? null) : null;

        return $intended ?? $remembered ?? '/app';
    }
}
