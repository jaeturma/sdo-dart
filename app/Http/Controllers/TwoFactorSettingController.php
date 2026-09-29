<?php

namespace App\Http\Controllers;

use App\Models\TwoFactorSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TwoFactorSettingController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $enforced = TwoFactorSetting::toggle();

        AuditLogger::log($request->user(), null, 'auth.two_factor_enforcement_toggled', $request, [
            'enforced' => $enforced,
        ]);

        $message = $enforced
            ? 'Two-factor authentication is now mandatory for all users.'
            : 'Two-factor authentication is no longer mandatory. Users who already enabled it can still use it.';

        return back()->with('success', $message);
    }
}
