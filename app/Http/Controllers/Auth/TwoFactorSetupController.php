<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorSetupController extends Controller
{
    /**
     * Show the mandatory 2FA enrollment page.
     * Enables 2FA automatically if not yet started so the QR code is immediately visible.
     */
    public function show(Request $request, EnableTwoFactorAuthentication $enable): Response
    {
        $user = $request->user();

        if (! $user->two_factor_secret) {
            $enable($user);
        }

        return Inertia::render('Auth/TwoFactorSetup', [
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'secretKey' => decrypt($user->two_factor_secret),
            'recoveryCodes' => json_decode(decrypt($user->two_factor_recovery_codes), true),
        ]);
    }

    /**
     * Confirm the 2FA code and complete enrollment.
     */
    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);

        $confirm($request->user(), $request->code);

        return redirect()->intended('/dashboard');
    }
}
