<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-19';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'UTC')); // a Sunday

        $patient = new User([
            'name' => 'Patient',
            'email' => 'patient@example.com',
            'phone' => '08011111111',
            'password' => 'password123',
        ]);
        $patient->role = 'patient';
        $patient->is_active = true;
        $patient->save();

        Sanctum::actingAs($patient);
    }

    private function specialty(string $name): Specialty
    {
        return Specialty::firstOrCreate(
            ['slug' => str($name)->slug()->toString()],
            ['name' => $name]
        );
    }

    private function makeDoctor(
        string $name = 'Dr. Test',
        string $specialty = 'General Practice',
        string $status = 'approved',
        string $clinic = 'Test Clinic',
    ): Doctor {
        $user = new User([
            'name' => $name,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('080########'),
            'password' => 'password123',
        ]);
        $user->role = 'doctor';
        $user->is_active = true;
        $user->save();

        $doctor = Doctor::forceCreate([
            'user_id' => $user->id,
            'specialty_id' => $this->specialty($specialty)->id,
            'clinic_name' => $clinic,
            'clinic_address' => '1 Test Street',
            'consultation_fee' => 5000,
            'slot_minutes' => 30,
            'timezone' => 'Africa/Lagos',
            'status' => $status,
        ]);

        // Mondays 09:00-12:00 and 13:00-16:00 (12 slots)
        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00']);
        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '13:00', 'end_time' => '16:00']);

        return $doctor;
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/specialties')->assertUnauthorized();
        $this->getJson('/api/v1/doctors')->assertUnauthorized();
    }

    public function test_it_lists_specialties_alphabetically(): void
    {
        $this->specialty('Pediatrics');
        $this->specialty('Cardiology');

        $this->getJson('/api/v1/specialties')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Cardiology')
            ->assertJsonPath('data.1.name', 'Pediatrics');
    }

    public function test_the_list_only_shows_approved_doctors(): void
    {
        $this->makeDoctor('Dr. Approved');
        $this->makeDoctor('Dr. Pending', status: 'pending');
        $this->makeDoctor('Dr. Suspended', status: 'suspended');

        $this->getJson('/api/v1/doctors')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dr. Approved');
    }

    public function test_it_filters_by_specialty(): void
    {
        $this->makeDoctor('Dr. Heart', 'Cardiology');
        $this->makeDoctor('Dr. Skin', 'Dermatology');

        $this->getJson('/api/v1/doctors?specialty_id='.$this->specialty('Cardiology')->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dr. Heart');
    }

    public function test_it_searches_by_doctor_name_or_clinic(): void
    {
        $this->makeDoctor('Dr. Amaka Obi', clinic: 'Obi Family Clinic');
        $this->makeDoctor('Dr. Tunde Bello', clinic: 'Sunrise Care');

        $this->getJson('/api/v1/doctors?search=amaka')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/doctors?search=sunrise')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Dr. Tunde Bello');
        $this->getJson('/api/v1/doctors?search=nobody')->assertJsonCount(0, 'data');
    }

    public function test_it_rejects_an_unknown_specialty_filter(): void
    {
        $this->getJson('/api/v1/doctors?specialty_id=999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('specialty_id');
    }

    public function test_it_shows_one_doctor(): void
    {
        $doctor = $this->makeDoctor('Dr. Detail', 'Cardiology');

        $this->getJson("/api/v1/doctors/{$doctor->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Dr. Detail')
            ->assertJsonPath('data.specialty.name', 'Cardiology')
            ->assertJsonPath('data.consultation_fee', 5000);
    }

    public function test_a_pending_doctor_returns_404(): void
    {
        $doctor = $this->makeDoctor(status: 'pending');

        $this->getJson("/api/v1/doctors/{$doctor->id}")->assertNotFound();
        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=".self::MONDAY)->assertNotFound();
    }

    public function test_it_returns_slots_for_a_date(): void
    {
        $doctor = $this->makeDoctor();

        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=".self::MONDAY)
            ->assertOk()
            ->assertJsonPath('data.timezone', 'Africa/Lagos')
            ->assertJsonCount(12, 'data.slots')
            ->assertJsonPath('data.slots.0.local_time', '09:00')
            ->assertJsonPath('data.slots.0.starts_at', '2026-10-19T08:00:00Z'); // 09:00 Lagos in UTC
    }

    public function test_slots_need_a_valid_date_in_range(): void
    {
        $doctor = $this->makeDoctor();
        $url = "/api/v1/doctors/{$doctor->id}/slots";

        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson("$url?date=19-10-2026")->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson("$url?date=2026-10-10")->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->getJson("$url?date=2027-06-01")->assertUnprocessable()->assertJsonValidationErrors('date');
    }
}