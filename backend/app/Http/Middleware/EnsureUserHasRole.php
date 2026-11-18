<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(
        Request $request,
        Closure $next,
        string ...$roles
    ): Response {
        $user = $request->user();

        if (!$user || !$user->role) {
            return response()->json([
                'message' => 'Akses ditolak.',
            ], 403);
        }

        if (!in_array($user->role->slug, $roles, true)) {
            return response()->json([
                'message' => 'Kamu tidak memiliki izin untuk mengakses fitur ini.',
            ], 403);
        }

        return $next($request);
    }
}