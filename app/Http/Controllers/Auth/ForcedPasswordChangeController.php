<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class ForcedPasswordChangeController extends Controller
{
    public function show(): Response
    {
        return Inertia::render('Auth/ForcedPasswordChange');
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password'          => ['required', 'confirmed', Password::default()],
        ]);

        $request->user()->forceFill([
            'password'              => Hash::make($request->password),
            'must_change_password'  => false,
            'password_changed_at'   => now(),
        ])->save();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Your password has been updated.');
    }
}
