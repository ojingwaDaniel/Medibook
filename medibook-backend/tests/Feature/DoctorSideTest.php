<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorTimeOff;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DoctorSideTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-19';
    private const SLOT_9AM = '2026-10-19 08:00:00'; // 09:00 Lagos

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'UTC')); // the Sunday before
    }

    private function makeUser(string $role = 'patient', string $name = 'Some User'): User
    {
        $user = new User([
            'name' => $name,
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
            'user_id' => $this->makeUser('doctor', 'Dr. Test')->id,
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

    private function actAsDoctor(Doctor $doctor): void
    {
        Sanctum::actingAs($doctor->user);
    }

    // ---- access ----

    public function test_a_patient_cannot_use_doctor_endpoints(): void
    {
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/doctor/profile')->assertForbidden();
        $this->getJson('/api/v1/doctor/appointments')->assertForbidden();
    }

    public function test_a_suspended_doctor_is_locked_out(): void
    {
        $this->actAsDoctor($this->makeDoctor('suspended'));

        $this->getJson('/api/v1/doctor/profile')->assertForbidden();
    }

    // ---- profile ----

    public function test_a_pending_doctor_can_view_their_profile_and_status(): void
    {
        $this->actAsDoctor($this->makeDoctor('pending'));

        $this->getJson('/api/v1/doctor/profile')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.clinic_name', 'Test Clinic');
    }

    public function test_a_doctor_can_update_their_profile(): void
    {
        $doctor = $this->makeDoctor();
        $this->actAsDoctor($doctor);

        $this->putJson('/api/v1/doctor/profile', [
            'clinic_name' => 'New Clinic',
            'consultation_fee' => 9000,
            'slot_minutes' => 45,
            'status' => 'suspended', // must be ignored
        ])->assertOk()->assertJsonPath('data.clinic_name', 'New Clinic');

        $doctor->refresh();
        $this->assertSame(45, $doctor->slot_minutes);
        $this->assertSame('approved', $doctor->status);
    }

    public function test_slot_length_must_be_an_allowed_value(): void
    {
        $this->actAsDoctor($this->makeDoctor());

        $this->putJson('/api/v1/doctor/profile', ['slot_minutes' => 25])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('slot_minutes');
    }

    // ---- availability ----

    public function test_a_doctor_can_replace_the_weekly_schedule(): void
    {
        $doctor = $this->makeDoctor();
        $this->actAsDoctor($doctor);

        $this->putJson('/api/v1/doctor/availability', [
            'availability' => [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '14:00']],
        ])->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.weekday', 2)
            ->assertJsonPath('data.0.start_time', '10:00');

        $this->assertSame(1, $doctor->availabilities()->count()); // the old Monday ranges are gone
    }

    public function test_overlapping_ranges_are_rejected(): void
    {
        $this->actAsDoctor($this->makeDoctor());

        $this->putJson('/api/v1/doctor/availability', [
            'availability' => [
                ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
                ['weekday' => 1, 'start_time' => '11:00', 'end_time' => '13:00'],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('availability');
    }

    public function test_the_end_time_must_be_after_the_start_time(): void
    {
        $this->actAsDoctor($this->makeDoctor());

        $this->putJson('/api/v1/doctor/availability', [
            'availability' => [['weekday' => 1, 'start_time' => '12:00', 'end_time' => '09:00']],
        ])->assertUnprocessable()->assertJsonValidationErrors('availability.0.end_time');
    }

    public function test_new_availability_changes_the_slots_patients_see(): void
    {
        $doctor = $this->makeDoctor();
        $this->actAsDoctor($doctor);

        $this->putJson('/api/v1/doctor/availability', [
            'availability' => [['weekday' => 1, 'start_time' => '10:00', 'end_time' => '11:00']],
        ])->assertOk();

        Sanctum::actingAs($this->makeUser());

        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=".self::MONDAY)
            ->assertOk()
            ->assertJsonCount(2, 'data.slots');
    }

    // ---- time off ----

    public function test_time_off_removes_slots_from_patients(): void
    {
        $doctor = $this->makeDoctor();
        $this->actAsDoctor($doctor);

        $this->postJson('/api/v1/doctor/time-off', [
            'starts_at' => '2026-10-19T09:00:00Z', // 10:00 Lagos
            'ends_at' => '2026-10-19T10:00:00Z',   // 11:00 Lagos
            'reason' => 'Conference',
        ])->assertCreated();

        Sanctum::actingAs($this->makeUser());

        $this->getJson("/api/v1/doctors/{$doctor->id}/slots?date=".self::MONDAY)
            ->assertJsonCount(10, 'data.slots'); // 12 minus the 10:00 and 10:30 slots
    }

    public function test_time_off_is_refused_when_it_would_hide_a_booking(): void
    {
        $doctor = $this->makeDoctor();
        $this->insertAppointment($this->makeUser(), $doctor, self::SLOT_9AM);
        $this->actAsDoctor($doctor);

        $this->postJson('/api/v1/doctor/time-off', [
            'starts_at' => '2026-10-19T07:30:00Z',
            'ends_at' => '2026-10-19T08:30:00Z',
        ])->assertUnprocessable();

        $this->assertDatabaseCount('doctor_time_offs', 0);
    }

    public function test_a_doctor_can_remove_only_their_own_time_off(): void
    {
        $mine = $this->makeDoctor();
        $theirs = $this->makeDoctor();

        $timeOff = DoctorTimeOff::create([
            'doctor_id' => $theirs->id,
            'starts_at' => '2026-10-20 08:00:00',
            'ends_at' => '2026-10-20 12:00:00',
        ]);

        $this->actAsDoctor($mine);
        $this->deleteJson("/api/v1/doctor/time-off/{$timeOff->id}")->assertNotFound();

        $this->actAsDoctor($theirs);
        $this->deleteJson("/api/v1/doctor/time-off/{$timeOff->id}")->assertOk();
        $this->assertDatabaseCount('doctor_time_offs', 0);
    }

    // ---- schedule ----

    public function test_the_schedule_uses_the_doctors_local_day(): void
    {
        $doctor = $this->makeDoctor();
        $patient = $this->makeUser('patient', 'Ada Patient');

        $this->insertAppointment($patient, $doctor, '2026-10-18 23:30:00'); // 00:30 Lagos on the 19th
        $this->insertAppointment($patient, $doctor, self::SLOT_9AM);        // 09:00 Lagos on the 19th
        $this->insertAppointment($patient, $doctor, '2026-10-19 23:30:00'); // 00:30 Lagos on the 20th

        $this->actAsDoctor($doctor);

        $this->getJson('/api/v1/doctor/appointments?date='.self::MONDAY)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.local_time', '00:30')
            ->assertJsonPath('data.1.local_time', '09:00')
            ->assertJsonPath('data.1.patient.name', 'Ada Patient');
    }

    public function test_a_doctor_only_sees_their_own_appointments(): void
    {
        $mine = $this->makeDoctor();
        $other = $this->makeDoctor();

        $this->insertAppointment($this->makeUser(), $other, self::SLOT_9AM);

        $this->actAsDoctor($mine);

        $this->getJson('/api/v1/doctor/appointments?date='.self::MONDAY)->assertJsonCount(0, 'data');
    }

    // ---- complete / no-show ----

    public function test_an_appointment_can_be_completed_only_after_it_starts(): void
    {
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($this->makeUser(), $doctor, self::SLOT_9AM);
        $this->actAsDoctor($doctor);

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/complete")->assertUnprocessable();

        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'UTC'));

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/complete")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/complete")->assertUnprocessable();
    }

    public function test_an_appointment_can_be_marked_no_show(): void
    {
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($this->makeUser(), $doctor, self::SLOT_9AM);
        $this->actAsDoctor($doctor);

        $this->travelTo(Carbon::parse('2026-10-19 09:00:00', 'UTC'));

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/no-show")
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
    }

    // ---- doctor cancels ----

    public function test_a_doctor_cancels_with_a_reason_and_the_slot_reopens(): void
    {
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($this->makeUser(), $doctor, self::SLOT_9AM);
        $this->actAsDoctor($doctor);

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/cancel", ['reason' => 'Emergency'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'slot_lock' => null,
            'cancelled_by' => $doctor->user_id,
            'cancel_reason' => 'Emergency',
        ]);

        Sanctum::actingAs($this->makeUser());
        $this->postJson('/api/v1/appointments', [
            'doctor_id' => $doctor->id,
            'starts_at' => '2026-10-19T08:00:00Z',
        ])->assertCreated();
    }

    public function test_a_doctor_cannot_cancel_after_the_appointment_has_started(): void
    {
        $doctor = $this->makeDoctor();
        $appointment = $this->insertAppointment($this->makeUser(), $doctor, self::SLOT_9AM);
        $this->actAsDoctor($doctor);

        $this->travelTo(Carbon::parse('2026-10-19 08:30:00', 'UTC'));

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/cancel", ['reason' => 'Late'])
            ->assertUnprocessable();
    }

    public function test_a_doctor_cannot_manage_another_doctors_appointment(): void
    {
        $owner = $this->makeDoctor();
        $appointment = $this->insertAppointment($this->makeUser(), $owner, self::SLOT_9AM);

        $this->actAsDoctor($this->makeDoctor());

        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/cancel", ['reason' => 'x'])->assertForbidden();
        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/complete")->assertForbidden();
        $this->patchJson("/api/v1/doctor/appointments/{$appointment->id}/no-show")->assertForbidden();
    }
}