<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'patient', bool $active = true): User
    {
        $user = new User([
            'name' => 'Some '.$role,
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('080########'),
            'password' => 'password123',
        ]);
        $user->role = $role;
        $user->is_active = $active;
        $user->save();

        return $user;
    }

    private function makeDoctor(string $status = 'pending'): Doctor
    {
        $specialty = Specialty::firstOrCreate(['slug' => 'general-practice'], ['name' => 'General Practice']);

        return Doctor::forceCreate([
            'user_id' => $this->makeUser('doctor')->id,
            'specialty_id' => $specialty->id,
            'clinic_name' => 'Test Clinic',
            'clinic_address' => '1 Test Street',
            'consultation_fee' => 5000,
            'slot_minutes' => 30,
            'timezone' => 'Africa/Lagos',
            'status' => $status,
        ]);
    }

    // ---- who can open the panel ----

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_only_active_admins_can_open_the_panel(): void
    {
        $this->actingAs($this->makeUser('patient'))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser('doctor'))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser('admin', active: false))->get('/admin')->assertForbidden();
        $this->actingAs($this->makeUser('admin'))->get('/admin')->assertOk();
    }

    public function test_every_admin_page_renders_with_data_in_it(): void
    {
        $doctor = $this->makeDoctor('approved');
        $this->travelTo(Carbon::parse('2026-10-18 08:00:00', 'UTC'));

        Appointment::forceCreate([
            'patient_id' => $this->makeUser()->id,
            'doctor_id' => $doctor->id,
            'starts_at' => '2026-10-19 08:00:00',
            'ends_at' => '2026-10-19 08:30:00',
            'status' => 'confirmed',
            'slot_lock' => 1,
        ]);

        $this->actingAs($this->makeUser('admin'));

        foreach (['doctors', 'appointments', 'users', 'specialties'] as $page) {
            $this->get("/admin/{$page}")->assertOk();
        }
    }

    // ---- doctor approval ----

    public function test_status_cannot_be_changed_by_mass_assignment(): void
    {
        $doctor = $this->makeDoctor('pending');

        $doctor->update(['status' => 'approved']);

        $this->assertSame('pending', $doctor->fresh()->status);
    }

    public function test_approving_a_doctor_makes_them_visible_to_patients(): void
    {
        $doctor = $this->makeDoctor('pending');
        Sanctum::actingAs($this->makeUser());

        $this->getJson('/api/v1/doctors')->assertJsonCount(0, 'data');

        $doctor->approve();

        $this->getJson('/api/v1/doctors')->assertJsonCount(1, 'data');
    }

    public function test_suspending_a_doctor_hides_them_and_locks_their_tools(): void
    {
        $doctor = $this->makeDoctor('approved');

        $doctor->suspend();

        Sanctum::actingAs($this->makeUser());
        $this->getJson("/api/v1/doctors/{$doctor->id}")->assertNotFound();

        Sanctum::actingAs($doctor->user);
        $this->getJson('/api/v1/doctor/profile')->assertForbidden();
    }

    // ---- user activation ----

    public function test_deactivating_a_user_revokes_their_tokens(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('mobile')->plainTextToken;

        $user->deactivate();

        $this->assertFalse($user->fresh()->is_active);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_a_deactivated_user_can_be_activated_again(): void
    {
        $user = $this->makeUser(active: false);

        $user->activate();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk();
    }

    // ---- specialties ----

    public function test_the_slug_follows_the_name(): void
    {
        $specialty = Specialty::create(['name' => 'Eye Care']);
        $this->assertSame('eye-care', $specialty->slug);

        $specialty->update(['name' => 'Ophthalmology']);
        $this->assertSame('ophthalmology', $specialty->fresh()->slug);
    }
}