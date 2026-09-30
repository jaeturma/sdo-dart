<?php

namespace App\Http\Controllers;

use App\Models\MagicLinkSecret;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MagicLinkController extends Controller
{
    public function generate(Request $request, User $targetUser): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);
        abort_unless($targetUser->hasAnyRole(['sds', 'asds']), 403, 'Magic links can only be generated for the SDS or ASDS.');

        $url = route('magic-login', [
            'uid'       => $targetUser->id,
            'signature' => self::sign($targetUser->id),
        ]);

        return back()->with('magic_link', $url);
    }

    public function consume(Request $request): RedirectResponse
    {
        $uid       = $request->query('uid');
        $signature = (string) $request->query('signature');

        abort_unless(
            $uid !== null && hash_equals(self::sign((int) $uid), $signature),
            403,
            'This link is invalid or has been revoked.',
        );

        $user = User::findOrFail($uid);
        abort_unless($user->hasAnyRole(['sds', 'asds']), 403);

        Auth::login($user);
        $request->session()->regenerate();

        AuditLogger::log($user, null, 'auth.magic_link_used', $request);

        // The SDS's magic link is mainly used to jump straight into the
        // documents awaiting her approval, rather than the general dashboard.
        return $user->hasRole('sds')
            ? redirect()->route('documents.index', ['tab' => 'for_approval'])
            : redirect()->route('dashboard');
    }

    /**
     * Admin-only: invalidate every previously generated magic link at once by
     * rotating the shared secret they're signed with.
     */
    public function rotateSecret(Request $request): RedirectResponse
    {
        abort_unless($request->user()->hasRole('admin'), 403);

        MagicLinkSecret::rotate();
        AuditLogger::log($request->user(), null, 'auth.magic_link_secret_rotated', $request);

        return back()->with('success', 'Magic link key rotated. All previously generated login links are now invalid.');
    }

    /**
     * HMAC over just the user id — deliberately host-independent (unlike
     * Laravel's built-in signed URLs, whose signature is bound to the exact
     * scheme+host they were generated against) so the link works regardless
     * of which domain/IP it's opened from, and keyed on a dedicated,
     * independently-rotatable secret rather than APP_KEY.
     */
    private static function sign(int $userId): string
    {
        return hash_hmac('sha256', (string) $userId, MagicLinkSecret::current());
    }
}
