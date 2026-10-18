<?php

namespace App\Console\Commands;

use App\Models\Campus;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class InstallCommand extends Command
{
    protected $signature = 'classroom:install';

    protected $description = 'Set up a new installation: run migrations, seed the default roles and a campus, and create the first super admin';

    public function handle(): int
    {
        $this->call('migrate', ['--force' => true]);
        $this->call('db:seed', ['--force' => true]);

        if (User::role('super_admin')->exists()) {
            $this->info('A super admin already exists. Installation is up to date.');

            return self::SUCCESS;
        }

        $this->line('Create the first super admin.');
        $name = $this->ask('Name', 'Administrator');
        $email = $this->ask('Email');
        $password = $this->secret('Password (at least 8 characters)');

        $validator = Validator::make(compact('email', 'password'), [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make($password)]);
        $admin->assignRole('super_admin');
        $admin->campuses()->syncWithoutDetaching(Campus::pluck('id'));

        $this->info("Done. Sign in as {$email}.");

        return self::SUCCESS;
    }
}
