<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ProvisionAdminCommand extends Command
{
    protected $signature = 'app:provision-admin';
    protected $description = 'Provision initial admin user from environment variables';

    public function handle(): int
    {
        $username = strtolower(trim((string) env('ADMIN_USERNAME', '')));
        $password = (string) env('ADMIN_PASSWORD', '');

        if ($username === '' || $password === '') {
            $this->error('ADMIN_USERNAME and ADMIN_PASSWORD environment variables are required.');
            return 1;
        }

        $existing = User::where('username', $username)->first();
        if ($existing) {
            $this->info("Admin user '{$username}' already exists.");
            return 0;
        }

        User::create([
            'id' => (string) Str::uuid(),
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'role' => 'admin',
        ]);

        $this->info("Admin user '{$username}' provisioned successfully.");
        return 0;
    }
}
