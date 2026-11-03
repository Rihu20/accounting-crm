<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class CreateAdmin extends Command
{
    protected $signature = 'crm:create-admin';

    protected $description = 'Create the first administrator interactively';

    public function handle(): int
    {
        $name = $this->ask('Your name', 'Himanshu');
        $email = $this->ask('Email');
        $password = $this->secret('Choose a password (at least 12 characters)');
        $v = Validator::make(compact('name', 'email', 'password'), ['name' => 'required', 'email' => 'required|email|unique:users,email', 'password' => 'required|min:12']);
        if ($v->fails()) {
            $this->error($v->errors()->first());

            return 1;
        }$u = User::create(compact('name', 'email', 'password'));
        $u->assignRole('Admin');
        $this->info('Administrator created. Open http://127.0.0.1:8000/admin');

        return 0;
    }
}
