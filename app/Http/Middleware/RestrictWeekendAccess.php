<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class RestrictWeekendAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $day = now()->dayOfWeek;

        if ($day === Carbon::SATURDAY || $day === Carbon::SUNDAY) {
            $user = $request->user();

            if (! $user || (! $user->hasAnyRole(['sds', 'admin']) && ! $user->can_login_weekends)) {
                return Inertia::render('WeekendRestriction', [
                    'userName' => $user?->name,
                ])->toResponse($request)->setStatusCode(403);
            }
        }

        return $next($request);
    }
}
