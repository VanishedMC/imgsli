<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Auth is Discord OAuth; there is no `login` route. Guests hitting an
        // auth-protected route go to the Discord flow instead of a 500.
        $middleware->redirectGuestsTo(fn () => route('auth.discord'));

        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Production sits behind Cloudflare: trust only its published IP
        // ranges (TRUSTED_PROXIES) so Request::ip()/isSecure() see the real
        // client and https URLs. Unset locally — no proxy in front of
        // `artisan serve`. `?: null` keeps an empty TRUSTED_PROXIES= entry
        // in .env a no-op instead of registering a bogus proxy.
        $middleware->trustProxies(at: env('TRUSTED_PROXIES') ?: null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
