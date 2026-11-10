<?php

namespace App\Http\Middleware;

use App\Services\TenantState;
use Closure;
use Illuminate\Http\Request;

class Access
{
    public function handle(Request $request, Closure $next, string $role): mixed
    {
        abort_unless($request->user() && $request->user()->role === $role, 403);
        abort_unless($request->user()->access_enabled ?? true, 403);
        if (in_array($role, ['owner', 'staff'], true)) {
            abort_unless(app(TenantState::class)->active($request->user()->tenant_id), 403, 'O acesso desta empresa está indisponível. Entre em contato com a administração.');
        }
        if ($role === 'owner') {
            abort_unless($request->user()->tenant_id, 403);
        }

        return $next($request);
    }
}
