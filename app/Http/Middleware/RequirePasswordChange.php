<?php

namespace App\Http\Middleware;

use App\Models\PasswordPolicySetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequirePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $forceResetAt = PasswordPolicySetting::forceResetAt();

        $mustChange = $user->must_change_password
            || ($forceResetAt && (! $user->password_changed_at || $user->password_changed_at->lt($forceResetAt)));

        if ($mustChange) {
            // Allow the forced password-change routes through so the flow can complete
            if ($request->routeIs('password.change', 'password.change.update')) {
                return $next($request);
            }

            return redirect()->route('password.change');
        }

        return $next($request);
    }
}
