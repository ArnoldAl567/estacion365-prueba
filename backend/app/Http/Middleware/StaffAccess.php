<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffAccess
{
    public function handle(Request $request, Closure $next, ?string $role = null): Response
    {
        abort_unless($request->user()?->active, 401, 'Inicia sesión para continuar.');
        abort_if($role && $request->user()->role !== $role, 403, 'Tu cuenta no tiene permiso para esta acción.');

        return $next($request);
    }
}
