<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class Access
{
    public function handle(Request $request, Closure $next, string $role): mixed
    {
        abort_unless($request->user() && $request->user()->role === $role, 403);
        if ($role === 'owner') {
            abort_unless($request->user()->tenant_id, 403);
        }

        return $next($request);
    }
}
