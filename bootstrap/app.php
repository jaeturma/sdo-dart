<?php

use App\Http\Middleware\EnsureEmailVerifiedIfEnforced;
use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequirePasswordChange;
use App\Http\Middleware\RestrictWeekendAccess;
use App\Http\Middleware\TrustCloudflareVisitorScheme;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Must run before TrustProxies (see the trustProxies() call below):
        // it reads Cloudflare's CF-Visitor header directly rather than
        // relying on IP-based proxy trust, since that trust check has been
        // observed to fail in production (local Nginx sits between
        // Cloudflare and PHP-FPM, so REMOTE_ADDR never matches Cloudflare's
        // ranges and X-Forwarded-Proto never gets honored).
        $middleware->prepend(TrustCloudflareVisitorScheme::class);

        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'two-factor.enabled' => EnsureTwoFactorEnabled::class,
            'weekend.restrict' => RestrictWeekendAccess::class,
            'password.change.required' => RequirePasswordChange::class,
            'email.verified.enforced' => EnsureEmailVerifiedIfEnforced::class,
        ]);

        // Production sits behind Cloudflare — trust only its published edge
        // IP ranges (source: https://www.cloudflare.com/ips/, current as of
        // this writing; refresh periodically) rather than '*'. Trusting
        // every hop let a client-suppliable X-Forwarded-For header spoof
        // $request->ip() for anyone who could reach the origin directly,
        // defeating the IP-keyed login rate limiter (see
        // FortifyServiceProvider's 'login' RateLimiter).
        $middleware->trustProxies(at: [
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
            '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
            '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
            '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
