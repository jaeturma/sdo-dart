<?php

namespace App\Http\Controllers;

use App\Models\ArdAvailabilitySetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ArdAvailabilitySettingController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $available = ArdAvailabilitySetting::toggle();

        AuditLogger::log($request->user(), null, 'auth.ard_availability_toggled', $request, [
            'available' => $available,
        ]);

        $message = $available
            ? 'ARD marked available again. Outgoing documents will go through ARD review as normal.'
            : 'ARD marked unavailable. Outgoing documents will now skip ARD review and go directly to the RD.';

        return back()->with('success', $message);
    }
}
