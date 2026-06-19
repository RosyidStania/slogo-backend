<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, $role): Response
    {
        // Cek apakah user sudah login dan memiliki role yang sesuai
        if ($request->user() && $request->user()->role === $role) {
            return $next($request);
        }

        // Jika rolenya salah (misal User mencoba akses halaman Admin)
        return response()->json([
            'success' => false,
            'message' => 'Akses ditolak. Anda bukan ' . $role
        ], 403);
    }
}