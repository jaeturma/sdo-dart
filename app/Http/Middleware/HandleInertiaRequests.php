<?php

namespace App\Http\Middleware;

use App\Models\UserSigningCredential;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'division_id' => $user->division_id,
                    'roles' => $user->getRoleNames()->toArray(),
                ] : null,
                'twoFactorEnabled' => $user?->hasEnabledTwoFactorAuthentication() ?? false,
                // Drives the "My Signature" nav link — shared globally (rather
                // than duplicating SigningCredentialPolicy::upload()'s allowed
                // roles as a hardcoded list in the layout) so the nav and the
                // policy can never drift apart, as has happened before in this
                // codebase.
                'canUploadSignature' => $user?->can('upload', UserSigningCredential::class) ?? false,
            ],
            'unreadNotificationsCount' => $user?->unreadNotifications()->count() ?? 0,
            'flash' => [
                'success' => $request->session()->get('success'),
                'warning' => $request->session()->get('warning'),
                'error' => $request->session()->get('error'),
                'magic_link' => $request->session()->get('magic_link'),
                'temporary_password' => $request->session()->get('temporary_password'),
            ],
        ];
    }
}
