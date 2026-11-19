<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // Aktifkan autentikasi SPA menggunakan Sanctum.
        $middleware->statefulApi();

        // Middleware alias untuk role dan permission.
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
            'permission' => \App\Http\Middleware\EnsureUserHasPermission::class,
        ]);

        // API tidak diarahkan ke halaman login web.
        $middleware->redirectGuestsTo(function (Request $request) {
            return null;
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {

        // Pastikan respons API menggunakan format JSON.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*')
                || $request->expectsJson(),
        );

    })
    ->create();