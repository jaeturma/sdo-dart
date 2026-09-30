<?php

namespace App\Http\Controllers;

use App\Models\AsdsAvailabilitySetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AsdsAvailabilitySettingController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $available = AsdsAvailabilitySetting::toggle();

        AuditLogger::log($request->user(), null, 'auth.asds_availability_toggled', $request, [
            'available' => $available,
        ]);

        $message = $available
            ? 'ASDS marked available again. Outgoing documents will go through ASDS review as normal.'
            : 'ASDS marked unavailable. Outgoing documents will now skip ASDS review and go directly to the SDS.';

        return back()->with('success', $message);
    }
}
