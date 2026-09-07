<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Creates or promotes an administrator.
 *
 * This exists because the database that matters has no admin in it. Every one
 * of the 543 accounts came from the legacy import, which maps `shipper` rows to
 * shippers and carriers — there is no legacy row that becomes an admin, so a
 * freshly imported database locks the console out entirely. `UserSeeder` makes
 * one, but seeding over real customer data is not a thing anybody should do to
 * fix a login.
 *
 * The password is generated, not chosen, and shown once. Two reasons: a person
 * asked to invent an admin password at the command line invents a memorable
 * one, and a password passed as an argument lands in shell history and process
 * listings.
 *
 * Promoting an existing account leaves its password alone. That distinction was
 * learned the hard way — overwriting the password of an account that was
 * already a real carrier locked its owner out of the login they had been using.
 */
class MakeAdmin extends Command
{
    protected $signature = 'make:admin
                            {email : The account to create or promote}
                            {--name= : Display name, when creating}
                            {--reset-password : Also set a new password on an existing account}';

    protected $description = 'Create an administrator, or promote an existing account to one';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("  '{$email}' is not an email address.");

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();
        $password = null;

        if ($user) {
            $wasRole = $user->role->value;

            $user->forceFill([
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
            ])->save();

            // Deliberately not touched unless asked. See the class docblock.
            if ($this->option('reset-password')) {
                $password = $this->newPassword();
                $user->forceFill(['password' => Hash::make($password)])->save();
            }

            $this->line('');
            $this->line("  Promoted {$email} from {$wasRole} to admin.");

            if (! $password) {
                $this->line('  Password unchanged — sign in with the one this account already had.');
            }
        } else {
            $password = $this->newPassword();

            $user = User::create([
                'name' => $this->option('name') ?: 'Administrator',
                'email' => $email,
                'password' => $password,
                'role' => UserRole::Admin,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            $this->line('');
            $this->line("  Created admin {$email}.");
        }

        if ($password) {
            $this->line('');
            $this->line("  Password: {$password}");
            $this->warn('  Shown once. Change it after signing in.');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** Random, not chosen — see the class docblock. */
    private function newPassword(): string
    {
        // Ambiguous characters left out: this gets read off a terminal and
        // typed into a browser, and 0/O and 1/l/I are where that goes wrong.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';

        for ($i = 0; $i < 20; $i++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }
}
