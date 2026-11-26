<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StaffAccess
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless($request->user()?->active, 401, 'Inicia sesión para continuar.');
        abort_if($roles && ! in_array($request->user()->role, $roles, true), 403, 'Tu cuenta no tiene permiso para esta acción.');

        return $next($request);
    }
}
