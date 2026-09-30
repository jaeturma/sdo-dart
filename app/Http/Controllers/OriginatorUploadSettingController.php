<?php

namespace App\Http\Controllers;

use App\Models\OriginatorUploadSetting;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OriginatorUploadSettingController extends Controller
{
    public function toggle(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        $allOriginators = OriginatorUploadSetting::toggle();

        AuditLogger::log($request->user(), null, 'auth.originator_upload_toggled', $request, [
            'all_originators' => $allOriginators,
        ]);

        $message = $allOriginators
            ? 'All Staff / Originators can now create outgoing documents, whether or not they are KMT Focal.'
            : 'Only KMT Focal Staff / Originators can create outgoing documents again.';

        return back()->with('success', $message);
    }
}
