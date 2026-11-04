<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PlatformAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Brings an empty hosted database up to a usable state. The /setup page only
 * answers on localhost, by design, so a fresh deployment has no other way in.
 * The generated password is printed once, to the deploy log.
 */
class FirstRunSetup extends Command
{
    protected $signature = 'crm:first-run {--email=admin@crm.invalid} {--force : Add an administrator although users already exist}';

    protected $description = 'Seed an empty database and create a first administrator';

    public function handle(): int
    {
        if (User::query()->exists() && ! $this->option('force')) {
            $this->info('This database already has users; first-run setup skipped.');

            return self::SUCCESS;
        }

        $email = (string) $this->option('email');
        if (User::where('email', $email)->exists()) {
            $this->error('A user with '.$email.' already exists. Pass --email to use another address.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--force' => true]);

        $password = Str::password(16, symbols: false);
        $user = User::create(['name' => 'Administrator', 'email' => $email, 'password' => $password, 'is_active' => true]);
        $user->assignRole([PlatformAccess::ACCOUNTANT, PlatformAccess::DIRECTOR]);

        $this->newLine();
        $this->line(str_repeat('=', 56));
        $this->line('  First administrator created');
        $this->line('  Email:    '.$email);
        $this->line('  Password: '.$password);
        $this->line('  This is printed once. Change it in Settings -> Users.');
        $this->line(str_repeat('=', 56));
        $this->newLine();

        return self::SUCCESS;
    }
}
