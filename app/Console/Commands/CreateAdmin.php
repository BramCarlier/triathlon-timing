<?php
namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email?} {--name=}';
    protected $description = 'Create or promote an administrator account';

    public function handle(): int
    {
        $email = strtolower((string) ($this->argument('email') ?: $this->ask('Email')));
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        if (strlen($password) < 12) { $this->error('Password must contain at least 12 characters.'); return self::FAILURE; }
        User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'role' => UserRole::Admin, 'is_active' => true]);
        $this->info("Administrator {$email} is ready.");
        return self::SUCCESS;
    }
}
