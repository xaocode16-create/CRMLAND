<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(CrmCoreSeeder::class);

        $asesorRole = Role::query()->where('slug', UserRole::Asesor->value)->first();

        User::query()->firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'username' => 'test',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'role_id' => $asesorRole?->getKey(),
            ],
        );
    }
}
