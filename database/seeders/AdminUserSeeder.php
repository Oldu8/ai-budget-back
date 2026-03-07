<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Create or update the admin user. Email is fixed; password from ADMIN_PASSWORD env.
     * Run: php artisan db:seed --class=AdminUserSeeder
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD');
        if (empty($password)) {
            $this->command->warn('ADMIN_PASSWORD is empty. Skipping admin user.');

            return;
        }

        User::updateOrCreate(
            ['email' => 'oldu2002@gmail.com'],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ]
        );

        $this->command->info('Admin user created/updated (oldu2002@gmail.com).');
    }
}
