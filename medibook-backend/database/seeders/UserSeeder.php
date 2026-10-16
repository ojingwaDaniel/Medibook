<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\PatientProfile;
use App\Models\User;

class UserSeeder extends Seeder
{
   

    public function run(): void
    {
        User::forceCreate([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'phone' => '08000000001',
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        $patient = User::forceCreate([
            'name' => 'Test Patient',
            'email' => 'patient@example.com',
            'phone' => '08000000002',
            'role' => 'patient',
            'is_active' => true,
            'email_verified_at' => now(),
            'password' => 'password',
        ]);

        PatientProfile::create([
            'user_id' => $patient->id,
            'date_of_birth' => '1995-05-15',
            'gender' => 'female',
        ]);
    }
}
