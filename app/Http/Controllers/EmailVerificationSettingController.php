<?php

namespace App\Http\Controllers;

use App\Models\EmailVerificationSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationSettingController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $enforced = EmailVerificationSetting::toggle();

        AuditLogger::log($request->user(), null, 'auth.email_verification_enforcement_toggled', $request, [
            'enforced' => $enforced,
        ]);

        $message = $enforced
            ? 'Email verification is now required for all users.'
            : 'Email verification is no longer required. Users can access the app without verifying their email.';

        return back()->with('success', $message);
    }
}
