<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\DoctorTimeOff;
use App\Models\Specialty;
use App\Models\User;
use App\Services\SlotGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class SlotGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const MONDAY = '2026-10-19';
    private const TUESDAY = '2026-10-20';

    protected function setUp(): void
    {
        parent::setUp();
        // Sunday 18 Oct 2026, so Monday is in the future
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'UTC'));
    }

    private function makeDoctor(int $slotMinutes = 30): Doctor
    {
        $user = User::forceCreate([
            'name' => 'Dr. Test',
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('080########'),
            'role' => 'doctor',
            'is_active' => true,
            'password' => 'password',
        ]);

        $specialty = Specialty::firstOrCreate(
            ['slug' => 'general-practice'],
            ['name' => 'General Practice']
        );

        $doctor = Doctor::forceCreate([
            'user_id' => $user->id,
            'specialty_id' => $specialty->id,
            'clinic_name' => 'Test Clinic',
            'clinic_address' => '1 Test Street',
            'slot_minutes' => $slotMinutes,
            'timezone' => 'Africa/Lagos', // UTC+1, no daylight saving
            'status' => 'approved',
        ]);

        // Mondays: 09:00-12:00 and 13:00-16:00
        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00']);
        $doctor->availabilities()->create(['weekday' => 1, 'start_time' => '13:00', 'end_time' => '16:00']);

        return $doctor;
    }

    /** $utcStart is UTC, so 09:30 in Lagos is '08:30:00' here. */
    private function book(Doctor $doctor, string $utcStart, int $minutes = 30, bool $cancelled = false): Appointment
    {
        $patient = User::forceCreate([
            'name' => 'Patient',
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('081########'),
            'role' => 'patient',
            'is_active' => true,
            'password' => 'password',
        ]);

        $start = CarbonImmutable::parse($utcStart, 'UTC');

        return Appointment::forceCreate([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($minutes),
            'status' => $cancelled ? 'cancelled' : 'confirmed',
            'slot_lock' => $cancelled ? null : 1,
        ]);
    }

    private function times(Collection $slots): array
    {
        return $slots->pluck('local_time')->all();
    }

    public function test_it_splits_each_working_range_into_slots(): void
    {
        $slots = (new SlotGenerator)->forDate($this->makeDoctor(), self::MONDAY);

        $this->assertCount(12, $slots);
        $this->assertSame('09:00', $slots->first()['local_time']);
        $this->assertSame('15:30', $slots->last()['local_time']);
        $this->assertNotContains('12:00', $this->times($slots)); // lunch break
    }

    public function test_it_returns_nothing_on_a_day_without_availability(): void
    {
        $slots = (new SlotGenerator)->forDate($this->makeDoctor(), self::TUESDAY);

        $this->assertTrue($slots->isEmpty());
    }

    public function test_slot_length_follows_the_doctors_setting(): void
    {
        $slots = (new SlotGenerator)->forDate($this->makeDoctor(45), self::MONDAY);

        $this->assertSame(
            ['09:00', '09:45', '10:30', '11:15', '13:00', '13:45', '14:30', '15:15'],
            $this->times($slots)
        );
    }

    public function test_a_booked_slot_is_removed(): void
    {
        $doctor = $this->makeDoctor();
        $this->book($doctor, '2026-10-19 08:30:00'); // 09:30 Lagos

        $times = $this->times((new SlotGenerator)->forDate($doctor, self::MONDAY));

        $this->assertNotContains('09:30', $times);
        $this->assertContains('09:00', $times);
        $this->assertContains('10:00', $times);
    }

    public function test_a_cancelled_appointment_frees_the_slot(): void
    {
        $doctor = $this->makeDoctor();
        $this->book($doctor, '2026-10-19 08:30:00', 30, cancelled: true);

        $times = $this->times((new SlotGenerator)->forDate($doctor, self::MONDAY));

        $this->assertContains('09:30', $times);
    }

    public function test_time_off_removes_overlapping_slots(): void
    {
        $doctor = $this->makeDoctor();
        DoctorTimeOff::create([
            'doctor_id' => $doctor->id,
            'starts_at' => '2026-10-19 09:00:00', // 10:00 Lagos
            'ends_at' => '2026-10-19 10:00:00',   // 11:00 Lagos
        ]);

        $times = $this->times((new SlotGenerator)->forDate($doctor, self::MONDAY));

        $this->assertNotContains('10:00', $times);
        $this->assertNotContains('10:30', $times);
        $this->assertContains('09:30', $times);
        $this->assertContains('11:00', $times);
    }

    public function test_slots_in_the_past_are_removed(): void
    {
        $doctor = $this->makeDoctor();
        $this->travelTo(Carbon::parse('2026-10-19 09:15:00', 'UTC')); // 10:15 Lagos

        $slots = (new SlotGenerator)->forDate($doctor, self::MONDAY);

        $this->assertSame('10:30', $slots->first()['local_time']);
    }

    public function test_is_slot_free_checks_the_exact_slot(): void
    {
        $doctor = $this->makeDoctor();
        $generator = new SlotGenerator;

        $nineAm = CarbonImmutable::parse('2026-10-19 08:00:00', 'UTC');   // 09:00 Lagos
        $offGrid = CarbonImmutable::parse('2026-10-19 08:10:00', 'UTC');  // 09:10 Lagos
        $lunch = CarbonImmutable::parse('2026-10-19 11:00:00', 'UTC');    // 12:00 Lagos

        $this->assertTrue($generator->isSlotFree($doctor, $nineAm));
        $this->assertFalse($generator->isSlotFree($doctor, $offGrid));
        $this->assertFalse($generator->isSlotFree($doctor, $lunch));

        $this->book($doctor, '2026-10-19 08:00:00');

        $this->assertFalse($generator->isSlotFree($doctor, $nineAm));
    }
}