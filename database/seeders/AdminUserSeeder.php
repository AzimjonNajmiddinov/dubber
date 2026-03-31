<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => '970010509'],
            [
                'name'     => 'Admin',
                'password' => Hash::make('970010509'),
            ]
        );
    }
}
