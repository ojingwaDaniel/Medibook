<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Specialty;
use Illuminate\Support\Str;

class SpecialtySeeder extends Seeder
{


    public function run(): void
    {
        $names = [
            'General Practice',
            'Pediatrics',
            'Dermatology',
            'Cardiology',
            'Gynecology',
            'Dentistry',
            'Ophthalmology',
            'Orthopedics',
        ];

        foreach ($names as $name) {
            Specialty::create(['name' => $name, 'slug' => Str::slug($name)]);
        }
    }
}
