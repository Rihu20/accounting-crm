<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The create_demo_login_users migration seeds four accounts with a published password.
 * They are meant for trying the four roles out, and must be switched off before real use.
 */
class DisableDemoLogins extends Command
{
    public const EMAILS = ['accountant@demo.test', 'assistant@demo.test', 'director@demo.test', 'paralegal@demo.test'];

    protected $signature = 'crm:disable-demo-logins {--list : Only report which demo accounts are still active}';

    protected $description = 'Switch off the built-in demo logins before the CRM is used for real work';

    public function handle(): int
    {
        $accounts = User::whereIn('email', self::EMAILS)->get();
        if ($accounts->isEmpty()) {
            $this->info('No demo logins exist in this database.');

            return self::SUCCESS;
        }

        $active = $accounts->where('is_active', true);
        $this->table(['Email', 'Role', 'Active'], $accounts->map(fn (User $user) => [
            $user->email, $user->getRoleNames()->implode(', '), $user->is_active ? 'yes' : 'no',
        ]));

        if ($this->option('list')) {
            return $active->isEmpty() ? self::SUCCESS : self::FAILURE;
        }
        if ($active->isEmpty()) {
            $this->info('Every demo login is already switched off.');

            return self::SUCCESS;
        }

        User::whereIn('id', $active->pluck('id'))->update(['is_active' => false]);
        $this->info('Switched off '.$active->count().' demo login(s). Turn one back on in Settings → Users if you need it.');

        return self::SUCCESS;
    }
}
