<?php

namespace App\Http\Middleware;

use App\Models\TwoFactorSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! TwoFactorSetting::isEnforced()) {
            return $next($request);
        }

        if (! $user->hasEnabledTwoFactorAuthentication()) {
            // Allow Fortify's 2FA management routes through so setup can complete
            if ($request->routeIs('two-factor.setup', 'two-factor.enroll')) {
                return $next($request);
            }

            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }
}
