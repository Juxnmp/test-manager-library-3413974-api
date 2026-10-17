<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Services\ProvisionAdminService;
use Illuminate\Console\Command;

class ProvisionAdminCommand extends Command
{
    protected $signature = 'app:provision-admin';
    protected $description = 'Provision initial admin user from environment variables';

    public function handle(ProvisionAdminService $provisionAdmin): int
    {
        $username = strtolower(trim((string) config('app.admin_username', env('ADMIN_USERNAME', ''))));
        $password = (string) config('app.admin_password', env('ADMIN_PASSWORD', ''));

        if ($username === '' || $password === '') {
            $this->error('ADMIN_USERNAME and ADMIN_PASSWORD environment variables are required.');
            return 1;
        }

        $provisionAdmin->ensureProvisioned($username, $password);
        $this->info("Admin user '{$username}' provisioned (or already present).");

        return 0;
    }
}
