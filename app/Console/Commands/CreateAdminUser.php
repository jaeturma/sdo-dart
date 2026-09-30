<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

/**
 * Creates an admin account from the command line — the only way to get the
 * first login on a production install: self-registration is disabled, and
 * RoleAndDivisionSeeder only creates its demo admin@drs.local account when
 * APP_ENV=local. Every other user is then created by this admin from /users.
 */
class CreateAdminUser extends Command
{
    protected $signature = 'users:create-admin
        {email : Email address the admin signs in with}
        {--name=System Administrator : Display name}';

    protected $description = 'Create an admin account (use once on a fresh install to get the first login).';

    public function handle(): int
    {
        if (! Role::where('name', 'admin')->where('guard_name', 'web')->exists()) {
            $this->error('The "admin" role does not exist yet — run `php artisan db:seed --force` first.');

            return self::FAILURE;
        }

        $email = (string) $this->argument('email');
        $name = (string) $this->option('name');

        $identity = Validator::make(compact('email', 'name'), [
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        if ($identity->fails()) {
            foreach ($identity->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (min. 10 characters, mixed case, a number and a symbol)');
        $confirmation = (string) $this->secret('Confirm password');

        $passwordCheck = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::defaults(), 'confirmed']],
        );

        if ($passwordCheck->fails()) {
            foreach ($passwordCheck->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);

        // No verification email is ever sent for admin-created accounts —
        // mark it verified so EnsureEmailVerifiedIfEnforced can't lock this one out.
        $user->forceFill(['email_verified_at' => now()])->save();
        $user->assignRole('admin');

        AuditLogger::log(null, null, 'user.admin_created_via_cli', null, [
            'target_user_id' => $user->id,
        ]);

        $this->info("Admin account {$email} created. Two-factor setup will be required on first sign-in.");

        return self::SUCCESS;
    }
}
