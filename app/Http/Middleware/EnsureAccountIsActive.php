<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->activo) {
            $user?->revokeAccessTokens();

            return response()->json([
                'message' => 'La cuenta esta inactiva. Solicita acceso al administrador.',
            ], 403);
        }

        return $next($request);
    }
}
