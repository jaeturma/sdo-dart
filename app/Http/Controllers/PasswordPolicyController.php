<?php

namespace App\Http\Controllers;

use App\Models\PasswordPolicySetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PasswordPolicyController extends Controller
{
    public function forceResetAll(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        PasswordPolicySetting::forceResetAll();
        AuditLogger::log($request->user(), null, 'auth.password_reset_forced_all', $request);

        return back()->with('success', 'All users will be required to change their password on their next login.');
    }
}
