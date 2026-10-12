<?php

use App\Http\Controllers\AsdsAvailabilitySettingController;
use App\Http\Controllers\Auth\ForcedPasswordChangeController;
use App\Http\Controllers\Auth\TwoFactorSetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DivisionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\DocumentReleaseStampController;
use App\Http\Controllers\DocumentReviewController;
use App\Http\Controllers\DocumentRoutingController;
use App\Http\Controllers\DocumentRoutingRecipientController;
use App\Http\Controllers\DocumentSignatureController;
use App\Http\Controllers\EmailVerificationSettingController;
use App\Http\Controllers\IncomingDocumentController;
use App\Http\Controllers\IncomingDocumentLogController;
use App\Http\Controllers\MagicLinkController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OriginatorUploadSettingController;
use App\Http\Controllers\OutgoingDocumentController;
use App\Http\Controllers\PasswordPolicyController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReleasedDocumentLogController;
use App\Http\Controllers\SigningCredentialController;
use App\Http\Controllers\TwoFactorSettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
    ]);
});

// SDS magic login — public, no auth middleware, cryptographically signed
Route::get('/magic-login', [MagicLinkController::class, 'consume'])->name('magic-login');

// 2FA mandatory setup + forced password change — require auth but are
// intentionally exempt from EnsureTwoFactorEnabled/RequirePasswordChange so
// the flows themselves are reachable
Route::middleware('auth')->group(function () {
    Route::get('/two-factor/setup', [TwoFactorSetupController::class, 'show'])->name('two-factor.setup');
    Route::post('/two-factor/confirm', [TwoFactorSetupController::class, 'confirm'])->name('two-factor.enroll');

    Route::get('/password/change', [ForcedPasswordChangeController::class, 'show'])->name('password.change');
    Route::put('/password/change', [ForcedPasswordChangeController::class, 'update'])->name('password.change.update');
});

// All protected routes require auth + verified email + a current password + 2FA enabled + weekday-only access
Route::middleware(['auth', 'email.verified.enforced', 'password.change.required', 'two-factor.enabled', 'weekend.restrict'])->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Document listing and detail
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');

    // Create forms — must come before {document} wildcard routes to avoid shadowing
    Route::get('/documents/outgoing/create', [OutgoingDocumentController::class, 'create'])
        ->name('documents.outgoing.create');
    Route::get('/documents/incoming/create', [IncomingDocumentController::class, 'create'])
        ->name('documents.incoming.create');

    // Records Section: printable daily log of incoming documents by date (must also come before {document} wildcard)
    Route::get('/documents/incoming-log', [IncomingDocumentLogController::class, 'index'])
        ->name('documents.incoming-log');

    // Records Section: printable daily log + statistics of released documents (same {document} wildcard caveat)
    Route::get('/documents/released-log', [ReleasedDocumentLogController::class, 'index'])
        ->name('documents.released-log');

    // Document detail and routing form (routing form is a child of {document})
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/documents/{document}/route', [DocumentRoutingController::class, 'create'])
        ->name('documents.routings.create');
    Route::get('/documents/{document}/routings/candidates', [DocumentRoutingController::class, 'candidates'])
        ->name('documents.routings.candidates');

    // Outgoing document submission
    Route::post('/documents/outgoing', [OutgoingDocumentController::class, 'store'])
        ->name('documents.outgoing.store');

    // Correct and resubmit a returned/commented outgoing document
    Route::get('/documents/{document}/resubmit', [OutgoingDocumentController::class, 'editResubmit'])
        ->name('documents.outgoing.resubmit.edit');
    Route::put('/documents/{document}/resubmit', [OutgoingDocumentController::class, 'updateResubmit'])
        ->name('documents.outgoing.resubmit.update');

    // Incoming document upload
    Route::post('/documents/incoming', [IncomingDocumentController::class, 'store'])
        ->name('documents.incoming.store');

    // Serve the original document file (authorized users only — used by pdf.js on the sign page)
    Route::get('/documents/{document}/file', [DocumentController::class, 'file'])
        ->name('documents.file');

    // SDS "Approve & Sign" — interactive signature placement page + sign action
    // (the secretary attaches the SDS's own credential, not one of her own)
    Route::get('/documents/{document}/sign', [DocumentSignatureController::class, 'create'])
        ->name('documents.sign.create');
    Route::get('/documents/{document}/sign/esig', [DocumentSignatureController::class, 'esig'])
        ->name('documents.sign.esig');
    Route::post('/documents/{document}/sign', [DocumentSignatureController::class, 'store'])
        ->name('documents.sign.store');
    // Undo a signature to correct a mistake and sign again
    Route::delete('/documents/{document}/sign', [DocumentSignatureController::class, 'destroy'])
        ->name('documents.sign.destroy');

    // Download the signed PDF
    Route::get('/documents/{document}/signed-pdf', [DocumentSignatureController::class, 'download'])
        ->name('documents.signed-pdf');

    // Records Section marks a signed outgoing document as physically released —
    // interactive release-stamp placement page + release action (mirrors the
    // SDS's sign.create/sign.store pair above), then download the stamped PDF.
    Route::get('/documents/{document}/release', [DocumentReleaseStampController::class, 'create'])
        ->name('documents.release.create');
    Route::post('/documents/{document}/release', [DocumentReleaseStampController::class, 'store'])
        ->name('documents.release.store');
    Route::get('/documents/{document}/released-pdf', [DocumentReleaseStampController::class, 'download'])
        ->name('documents.released-pdf');
    // Confirms Records Section downloaded the released PDF and emailed it out
    Route::post('/documents/{document}/release/emailed', [DocumentReleaseStampController::class, 'markEmailed'])
        ->name('documents.release.emailed');

    // Review actions on outgoing documents
    Route::post('/documents/{document}/reviews', [DocumentReviewController::class, 'store'])
        ->name('documents.reviews.store');
    // Secretary: send an Approved document back to its owner when the SDS's own
    // remarks were overlooked instead of enforced as a return (see DocumentPolicy::returnToOwner())
    Route::post('/documents/{document}/return-to-owner', [DocumentReviewController::class, 'returnToOwner'])
        ->name('documents.return-to-owner');
    // Secretary: return an Approved document for a QC problem — missing pages, wrong
    // format, etc. — found while preparing to sign it (see DocumentPolicy::returnForCorrection())
    Route::post('/documents/{document}/return-for-correction', [DocumentReviewController::class, 'returnForCorrection'])
        ->name('documents.return-for-correction');

    // Route an incoming document to recipients
    Route::post('/documents/{document}/routings', [DocumentRoutingController::class, 'store'])
        ->name('documents.routings.store');
    // Undo a routing round issued by mistake (division chief, or SDS/her OSDS secretary, before anyone's acted on it)
    Route::delete('/documents/{document}/routings/{routing}', [DocumentRoutingController::class, 'cancel'])
        ->name('documents.routings.cancel');
    // SDS's OSDS secretary: file a Records-Section-uploaded incoming document that doesn't need routing (see DocumentPolicy::archive())
    Route::post('/documents/{document}/archive', [DocumentRoutingController::class, 'archive'])
        ->name('documents.archive');

    // Recipient lifecycle actions
    Route::patch('/routing-recipients/{recipient}/acknowledge', [DocumentRoutingRecipientController::class, 'acknowledge'])
        ->name('routing-recipients.acknowledge');
    Route::patch('/routing-recipients/{recipient}/complete', [DocumentRoutingRecipientController::class, 'complete'])
        ->name('routing-recipients.complete');
    // Undo one recipient's assignment without touching its siblings under the same routing round
    Route::delete('/routing-recipients/{recipient}/cancel', [DocumentRoutingRecipientController::class, 'cancel'])
        ->name('routing-recipients.cancel');

    // Signing credentials — self-managed (upload form must come before {user} wildcard)
    Route::get('/my-signature', [SigningCredentialController::class, 'show'])->name('my-signature.show');
    Route::get('/my-signature/esig', [SigningCredentialController::class, 'esig'])->name('my-signature.esig');
    Route::post('/my-signature', [SigningCredentialController::class, 'store'])->name('my-signature.store');
    Route::delete('/my-signature', [SigningCredentialController::class, 'destroy'])->name('my-signature.destroy');

    // Signing credentials — admin overview + admin revoke
    Route::get('/signing-credentials', [SigningCredentialController::class, 'index'])->name('signing-credentials.index');
    Route::delete('/signing-credentials/{user}', [SigningCredentialController::class, 'destroyFor'])->name('signing-credentials.destroy');

    // In-app notifications (header bell)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{id}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

    // User management (admin only — authorization enforced in controller/policy)
    Route::resource('users', UserController::class)->except(['show']);

    // Office/Division management (admin only — authorization enforced in controller/policy)
    Route::resource('divisions', DivisionController::class)->except(['show']);

    // Reset a user's password to the default (admin only)
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');

    // Activate/deactivate a user account (admin only)
    Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');

    // Allow/revoke a user's weekend login access (admin only) — disabled by default
    Route::post('/users/{user}/toggle-weekend-access', [UserController::class, 'toggleWeekendAccess'])->name('users.toggle-weekend-access');

    // Generate magic login link for SDS (admin only)
    Route::post('/users/{targetUser}/magic-link', [MagicLinkController::class, 'generate'])->name('users.magic-link');

    // Rotate the magic-link signing secret, invalidating all outstanding links (admin only)
    Route::post('/magic-link-secret/rotate', [MagicLinkController::class, 'rotateSecret'])->name('magic-link-secret.rotate');

    // Toggle whether 2FA is mandatory app-wide (admin only)
    Route::post('/two-factor-setting/toggle', [TwoFactorSettingController::class, 'toggle'])->name('two-factor-setting.toggle');

    // Force every user to change their password on next login, e.g. suspected compromise (admin only)
    Route::post('/password-policy/force-reset-all', [PasswordPolicyController::class, 'forceResetAll'])->name('password-policy.force-reset-all');

    // Toggle whether email verification is required app-wide (admin only)
    Route::post('/email-verification-setting/toggle', [EmailVerificationSettingController::class, 'toggle'])->name('email-verification-setting.toggle');

    // Toggle whether ASDS review is skipped for outgoing documents (admin only)
    Route::post('/asds-availability-setting/toggle', [AsdsAvailabilitySettingController::class, 'toggle'])->name('asds-availability-setting.toggle');

    // Toggle whether every originator (not just KMT Focal) may create outgoing documents (admin only)
    Route::post('/originator-upload-setting/toggle', [OriginatorUploadSettingController::class, 'toggle'])->name('originator-upload-setting.toggle');
});
