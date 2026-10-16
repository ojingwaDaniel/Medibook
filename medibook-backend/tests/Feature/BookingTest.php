<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    private const SLOT_9AM = '2026-10-19T08:00:00Z';   // 09:00 Lagos, a Monday
    private const SLOT_930 = '2026-10-19T08:30:00Z';   // 09:30 Lagos

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'UTC')); // the Sunday before
    }

    private function makeUser(string $role = 'patient'): User
    {
        $user = new User([
            'name' => 'Some '.$role,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('080########'),
            'password' => 'password123',
        ]);
        $user->role = $role;
        $user->is_active = true;
        $user->save();

        return $user;
    }

    private function makeDoctor(string $status = 'approved'): Doctor
    {
        $specialty = Specialty::firstOrCreate(['slug' => 'general-practice'], ['name' => 'General Practice']);

        $doctor = Doctor::forceCreate([
            'user_id' => $this->makeUser('doctor')->id,
            'specialty_id' => $specialty->id,
            'clinic_name' => 'Test Clinic',
            'clinic_address' => '1 Test Street',
            'consultation_fee' => 5000,
            'slot_minutes' => 30,
            'timezone' => 'Africa/Lagos',
            'status' => $status,
        ]);

        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00']);
        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '13:00', 'end_time' => '16:00']);

        return $doctor;
    }

    private function insertAppointment(User $patient, Doctor $doctor, string $utcStart, string $status = 'confirmed'): Appointment
    {
        $start = Carbon::parse($utcStart, 'UTC');

        return Appointment::forceCreate([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'status' => $status,
            'slot_lock' => $status === 'cancelled' ? null : 1,
        ]);
    }

    private function book(Doctor $doctor, string $startsAt = self::SLOT_9AM)
    {
        return $this->postJson('/api/v1/appointments', [
            'doctor_id' => $doctor->id,
            'starts_at' => $startsAt,
        ]);
    }

    public function test_a_patient_can_book_a_free_slot(): void
    {
        $patient = $this->makeUser();
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($patient);

        $this->postJson('/api/v1/appointments', [
            'doctor_id' => $doctor->id,
            'starts_at' => self::SLOT_9AM,
            'reason' => 'Headache',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.starts_at', self::SLOT_9AM)
            ->assertJsonPath('data.ends_at', '2026-10-19T08:30:00Z')
            ->assertJsonPath('data.local_time', '09:00')
            ->assertJsonPath('data.can_cancel', true);

        $this->assertDatabaseHas('appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => 'confirmed',
            'slot_lock' => 1,
        ]);
    }

    public function test_a_second_patient_cannot_take_the_same_slot(): void
    {
        $doctor = $this->makeDoctor();

        Sanctum::actingAs($this->makeUser());
        $this->book($doctor)->assertCreated();

        Sanctum::actingAs($this->makeUser());
        $this->book($doctor)->assertStatus(409);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_time_that_is_not_a_real_slot_is_refused(): void
    {
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser());

        $this->book($doctor, '2026-10-19T08:10:00Z')->assertStatus(409); // 09:10 Lagos, off the grid
        $this->book($doctor, '2026-10-19T11:00:00Z')->assertStatus(409); // 12:00 Lagos, lunch break
        $this->book($doctor, '2026-10-20T08:00:00Z')->assertStatus(409); // Tuesday, no availability
    }

    public function test_a_time_in_the_past_is_refused(): void
    {
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser());

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'UTC'));

        $this->book($doctor, self::SLOT_9AM)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_it_cannot_book_further_than_60_days_ahead(): void
    {
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser());

        $this->book($doctor, '2027-03-01T08:00:00Z')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_a_pending_doctor_cannot_be_booked(): void
    {
        $doctor = $this->makeDoctor('pending');
        Sanctum::actingAs($this->makeUser());

        $this->book($doctor)->assertNotFound();
    }

    public function test_only_patients_can_book(): void
    {
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser('doctor'));

        $this->book($doctor)->assertForbidden();
    }

    public function test_required_fields_are_validated(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->postJson('/api/v1/appointments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['doctor_id', 'starts_at']);
    }

    public function test_a_patient_cannot_exceed_the_active_booking_limit(): void
    {
        config(['booking.max_active_per_patient' => 1]);
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser());

        $this->book($doctor, self::SLOT_9AM)->assertCreated();
        $this->book($doctor, self::SLOT_930)->assertUnprocessable();
    }

    public function test_cancelling_frees_the_slot_for_someone_else(): void
    {
        $doctor = $this->makeDoctor();
        $first = $this->makeUser();
        $second = $this->makeUser();

        Sanctum::actingAs($first);
        $id = $this->book($doctor)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/appointments/{$id}/cancel", ['reason' => 'Plans changed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('appointments', [
            'id' => $id,
            'status' => 'cancelled',
            'slot_lock' => null,
            'cancelled_by' => $first->id,
            'cancel_reason' => 'Plans changed',
        ]);

        Sanctum::actingAs($second);
        $this->book($doctor)->assertCreated();
    }

    public function test_an_appointment_cannot_be_cancelled_twice(): void
    {
        $doctor = $this->makeDoctor();
        Sanctum::actingAs($this->makeUser());
        $id = $this->book($doctor)->json('data.id');

        $this->patchJson("/api/v1/appointments/{$id}/cancel")->assertOk();
        $this->patchJson("/api/v1/appointments/{$id}/cancel")->assertUnprocessable();
    }

    public function test_cancelling_inside_the_cutoff_is_refused(): void
    {
        $patient = $this->makeUser();
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($patient, $doctor, '2026-10-19 08:00:00');

        $this->travelTo(Carbon::parse('2026-10-19 07:00:00', 'UTC')); // 1 hour before
        Sanctum::actingAs($patient);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/cancel")->assertUnprocessable();

        $this->assertSame('confirmed', $appointment->fresh()->status);
    }

    public function test_cancelling_exactly_at_the_cutoff_is_allowed(): void
    {
        $patient = $this->makeUser();
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($patient, $doctor, '2026-10-19 08:00:00');

        $this->travelTo(Carbon::parse('2026-10-19 06:00:00', 'UTC')); // exactly 2 hours before
        Sanctum::actingAs($patient);

        $this->patchJson("/api/v1/appointments/{$appointment->id}/cancel")->assertOk();
    }

    public function test_a_patient_cannot_see_or_cancel_someone_elses_appointment(): void
    {
        $owner = $this->makeUser();
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($owner, $doctor, '2026-10-19 08:00:00');

        Sanctum::actingAs($this->makeUser());

        $this->getJson("/api/v1/appointments/{$appointment->id}")->assertForbidden();
        $this->patchJson("/api/v1/appointments/{$appointment->id}/cancel")->assertForbidden();
    }

    public function test_the_list_shows_only_own_appointments_and_supports_scopes(): void
    {
        $me = $this->makeUser();
        $other = $this->makeUser();
        $doctor = $this->makeDoctor();

        $this->insertAppointment($me, $doctor, '2026-10-19 08:00:00');                // upcoming
        $this->insertAppointment($me, $doctor, '2026-10-10 08:00:00', 'completed');   // past
        $this->insertAppointment($other, $doctor, '2026-10-19 09:00:00');             // not mine

        Sanctum::actingAs($me);

        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/appointments?scope=upcoming')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/appointments?scope=past')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/appointments?scope=nonsense')->assertUnprocessable();
    }

    public function test_the_database_refuses_two_active_appointments_for_the_same_slot(): void
    {
        $doctor = $this->makeDoctor();
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->insertAppointment($a, $doctor, '2026-10-19 08:00:00', 'cancelled'); // cancelled rows never block
        $this->insertAppointment($b, $doctor, '2026-10-19 08:00:00');              // first active one is fine

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertAppointment($a, $doctor, '2026-10-19 08:00:00');              // second active one is refused
    }
}