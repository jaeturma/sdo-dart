<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Models\ArdAvailabilitySetting;
use App\Models\Division;
use App\Models\EmailVerificationSetting;
use App\Models\MagicLinkSecret;
use App\Models\PasswordPolicySetting;
use App\Models\TwoFactorSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    private const ROLES = [
        'admin' => 'Admin',
        'rd' => 'Schools Division Superintendent',
        'ard' => 'Assistant Schools Division Superintendent',
        'division_chief' => 'Division Chief',
        'unit_head' => 'Unit / Section Head',
        'originator' => 'Staff / Originator',
        'secretary' => 'Secretary',
        'detailed_staff' => 'Detailed Official',
    ];

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $search = trim((string) $request->query('search', ''));

        $users = User::with(['roles', 'division.parent'])
            ->when($search !== '', fn ($q) => $q->where(function ($sub) use ($search) {
                $sub->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->getRoleNames()->reject(fn ($r) => $r === 'kmt_focal')->first(),
                'kmtFocal' => $u->hasRole('kmt_focal'),
                'division' => $u->division ? [
                    'name' => $u->division->name,
                    'parent' => $u->division->parent?->name,
                ] : null,
                'isActive' => $u->is_active,
                'canLoginWeekends' => $u->can_login_weekends,
            ]);

        return Inertia::render('Users/Index', [
            'users' => $users,
            'search' => $search,
            'roleLabels' => self::ROLES,
            'magicLinkSecretRotatedAt' => MagicLinkSecret::lastRotatedAt()?->toIso8601String(),
            'twoFactorEnforced' => TwoFactorSetting::isEnforced(),
            'passwordForceResetAt' => PasswordPolicySetting::forceResetAt()?->toIso8601String(),
            'emailVerificationEnforced' => EmailVerificationSetting::isEnforced(),
            'ardAvailable' => ArdAvailabilitySetting::isAvailable(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Users/Form', [
            'user' => null,
            'divisions' => $this->divisionOptions(),
            'roles' => self::ROLES,
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'division_id' => $request->division_id,
        ]);

        // Admin-set password is a temporary one — force a change on first login.
        $user->forceFill(['must_change_password' => true])->save();

        $user->syncRoles($this->rolesToSync($request));

        return redirect()->route('users.index')
            ->with('success', "User {$user->name} created successfully.");
    }

    public function edit(User $user): Response
    {
        $this->authorize('update', $user);

        return Inertia::render('Users/Form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->getRoleNames()->reject(fn ($r) => $r === 'kmt_focal')->first(),
                'kmt_focal' => $user->hasRole('kmt_focal'),
                'division_id' => $user->division_id,
            ],
            'divisions' => $this->divisionOptions(),
            'roles' => self::ROLES,
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $data = [
            'name' => $request->name,
            'email' => $request->email,
            'division_id' => $request->division_id,
        ];

        if ($request->filled('password')) {
            $data['password'] = $request->password;
        }

        $user->update($data);

        if ($request->filled('password')) {
            // Admin-set password is a temporary one — force a change on next login.
            $user->forceFill(['must_change_password' => true])->save();
        }

        $user->syncRoles($this->rolesToSync($request));

        return redirect()->route('users.index')
            ->with('success', "User {$user->name} updated successfully.");
    }

    public function resetPassword(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        // A random per-reset password rather than a fixed, source-visible
        // default — must_change_password still forces a real password on
        // next login, but this closes the window where the fixed default
        // would otherwise be a known, static credential.
        $temporaryPassword = Str::password(12);

        $user->forceFill([
            'password' => Hash::make($temporaryPassword),
            'must_change_password' => true,
        ])->save();

        AuditLogger::log($request->user(), null, 'user.password_reset_by_admin', $request, [
            'target_user_id' => $user->id,
        ]);

        return redirect()->route('users.index')
            ->with('success', "Password for {$user->name} has been reset. They will be required to change it on next login.")
            ->with('temporary_password', ['user' => $user->name, 'password' => $temporaryPassword]);
    }

    public function toggleActive(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        abort_if($user->id === $request->user()->id, 422, 'You cannot deactivate your own account.');

        $user->forceFill(['is_active' => ! $user->is_active])->save();

        AuditLogger::log($request->user(), null, $user->is_active ? 'user.activated' : 'user.deactivated', $request, [
            'target_user_id' => $user->id,
        ]);

        $status = $user->is_active ? 'activated' : 'deactivated';

        return redirect()->route('users.index')
            ->with('success', "User {$user->name} {$status}.");
    }

    public function toggleWeekendAccess(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        $user->forceFill(['can_login_weekends' => ! $user->can_login_weekends])->save();

        AuditLogger::log($request->user(), null, $user->can_login_weekends ? 'user.weekend_access_granted' : 'user.weekend_access_revoked', $request, [
            'target_user_id' => $user->id,
        ]);

        $status = $user->can_login_weekends ? 'can now log in on weekends' : 'can no longer log in on weekends';

        return redirect()->route('users.index')
            ->with('success', "{$user->name} {$status}.");
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        $this->authorize('delete', $user);

        $name = $user->name;
        $user->delete();

        return redirect()->route('users.index')
            ->with('success', "User {$name} deleted.");
    }

    /**
     * The full desired role set for a store()/update() request: the single
     * primary role plus, optionally, 'kmt_focal' layered on top — synced as
     * one set so a primary-role change never accidentally drops/keeps a
     * stale kmt_focal state, and vice versa.
     */
    private function rolesToSync(FormRequest $request): array
    {
        $roles = [$request->role];

        if ($request->boolean('kmt_focal')) {
            $roles[] = 'kmt_focal';
        }

        return $roles;
    }

    private function divisionOptions(): array
    {
        $options = [];

        Division::whereNull('parent_id')
            ->with('children')
            ->orderBy('name')
            ->each(function (Division $div) use (&$options) {
                $options[] = [
                    'id' => $div->id,
                    'label' => $div->code ? "{$div->name} ({$div->code})" : $div->name,
                    'depth' => 0,
                ];
                foreach ($div->children as $child) {
                    $options[] = [
                        'id' => $child->id,
                        'label' => $child->name,
                        'depth' => 1,
                    ];
                }
            });

        return $options;
    }
}
