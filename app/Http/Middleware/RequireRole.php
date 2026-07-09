<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $activeRole = $this->normalizeRole((string) ($user?->role ?? ''));
        $allowedRoles = array_map(fn (string $role) => $this->normalizeRole($role), $roles);

        if ($activeRole === User::ROLE_ADMIN || in_array($activeRole, $allowedRoles, true)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'No tienes permisos para realizar esta operacion.',
        ], 403);
    }

    private function normalizeRole(string $role): string
    {
        return match (strtolower($role)) {
            User::ROLE_CASHIER, User::ROLE_VENDOR, 'vendedor' => User::ROLE_VENDOR,
            User::ROLE_DELIVERY => User::ROLE_DELIVERY,
            User::ROLE_ADMIN, 'administrador' => User::ROLE_ADMIN,
            default => '',
        };
    }
}
