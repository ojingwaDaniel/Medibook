<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;

class DoctorSeeder extends Seeder
{


    public function run(): void
    {
        $doctors = [
            [
                'name' => 'Dr. Amaka Obi',
                'email' => 'amaka@example.com',
                'phone' => '08000000011',
                'specialty' => 'General Practice',
                'fee' => 5000,
                'years' => 8,
                'clinic' => 'Obi Family Clinic',
                'address' => '12 Aminu Kano Crescent, Wuse 2',
                'slot' => 30,
                'days' => [1, 2, 3, 4, 5]
            ],
            [
                'name' => 'Dr. Tunde Bello',
                'email' => 'tunde@example.com',
                'phone' => '08000000012',
                'specialty' => 'Pediatrics',
                'fee' => 8000,
                'years' => 12,
                'clinic' => 'Bello Children Care',
                'address' => '5 Gimbiya Street, Garki',
                'slot' => 20,
                'days' => [1, 3, 5]
            ],
            [
                'name' => 'Dr. Ngozi Eze',
                'email' => 'ngozi@example.com',
                'phone' => '08000000013',
                'specialty' => 'Dermatology',
                'fee' => 10000,
                'years' => 6,
                'clinic' => 'ClearSkin Centre',
                'address' => '27 Ademola Adetokunbo Way, Wuse 2',
                'slot' => 30,
                'days' => [2, 4, 6]
            ],
            [
                'name' => 'Dr. Ibrahim Musa',
                'email' => 'ibrahim@example.com',
                'phone' => '08000000014',
                'specialty' => 'Cardiology',
                'fee' => 15000,
                'years' => 15,
                'clinic' => 'Heartline Specialist Clinic',
                'address' => '3 Ahmadu Bello Way, Central Area',
                'slot' => 45,
                'days' => [1, 2, 3, 4]
            ],
        ];

        foreach ($doctors as $d) {
            $user = User::forceCreate([
                'name' => $d['name'],
                'email' => $d['email'],
                'phone' => $d['phone'],
                'role' => 'doctor',
                'is_active' => true,
                'email_verified_at' => now(),
                'password' => 'password',
            ]);

            $doctor = Doctor::forceCreate([
                'user_id' => $user->id,
                'specialty_id' => Specialty::where('name', $d['specialty'])->value('id'),
                'bio' => 'Experienced ' . strtolower($d['specialty']) . ' doctor.',
                'years_experience' => $d['years'],
                'consultation_fee' => $d['fee'],
                'clinic_name' => $d['clinic'],
                'clinic_address' => $d['address'],
                'slot_minutes' => $d['slot'],
                'timezone' => 'Africa/Lagos',
                'status' => 'approved',
            ]);

            foreach ($d['days'] as $weekday) {
                // morning and afternoon ranges, with a lunch break between
                $doctor->availabilities()->create(['weekday' => $weekday, 'start_time' => '09:00', 'end_time' => '12:00']);
                $doctor->availabilities()->create(['weekday' => $weekday, 'start_time' => '13:00', 'end_time' => '16:00']);
            }
        }
    }
}
