<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Menyimpan catatan aktivitas autentikasi.
     */
    private function writeAuditLog(
        Request $request,
        ?int $userId,
        string $action,
        array $metadata = []
    ): void {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => 'AUTH',
            'entity_id' => null,
            'metadata' => array_merge($metadata, [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]),
            'created_at' => now(),
        ]);
    }

    /**
     * Login menggunakan session Laravel.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::with('role')
            ->where('email', $credentials['email'])
            ->first();

        // LOGIN GAGAL
        if (
            !$user ||
            !Hash::check($credentials['password'], $user->password)
        ) {
            $this->writeAuditLog(
                $request,
                $user?->id,
                'LOGIN_FAILED',
                [
                    'email' => $credentials['email'],
                    'reason' => 'Invalid credentials',
                ]
            );

            throw ValidationException::withMessages([
                'email' => ['Email atau password tidak valid.'],
            ]);
        }

        // BLOKIR AKUN TANPA ROLE
        if (!$user->role) {
            $this->writeAuditLog(
                $request,
                $user->id,
                'LOGIN_BLOCKED',
                [
                    'email' => $user->email,
                    'reason' => 'Account has no assigned role',
                ]
            );

            return response()->json([
                'message' => 'Akun belum memiliki role.',
            ], 403);
        }

        // LOGIN MENGGUNAKAN SESSION WEB
        Auth::guard('web')->login($user);

        // Regenerasi session untuk mencegah session fixation.
        $request->session()->regenerate();

        // CATAT LOGIN BERHASIL
        $this->writeAuditLog(
            $request,
            $user->id,
            'LOGIN_SUCCESS',
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->slug,
            ]
        );

        return response()->json([
            'message' => 'Login berhasil.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->slug,
            ],
        ], 200);
    }

    /**
     * Logout pengguna dan mengakhiri session.
     */
    public function logout(Request $request)
    {
        $user = $request->user()->load('role');

        // CATAT LOGOUT SEBELUM SESSION DIHAPUS
        $this->writeAuditLog(
            $request,
            $user->id,
            'LOGOUT',
            [
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug,
            ]
        );

        $currentToken = $user->currentAccessToken();

        // Jika menggunakan Personal Access Token, hapus token aktif.
        if ($currentToken instanceof PersonalAccessToken) {
            $currentToken->delete();
        } else {
            // Jika menggunakan session SPA, akhiri session.
            Auth::guard('web')->logout();

            $request->session()->invalidate();

            $request->session()->regenerateToken();
        }

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }

    /**
     * Informasi pengguna yang sedang login.
     */
    public function me(Request $request)
    {
        $user = $request->user()->load('role');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->slug,
            ],
        ]);
    }
}