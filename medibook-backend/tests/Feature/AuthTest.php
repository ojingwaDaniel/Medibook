<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role = 'patient', bool $active = true): User
    {
        $user = new User([
            'name' => 'Test User',
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->unique()->numerify('080########'),
            'password' => 'password123',
        ]);
        $user->role = $role;
        $user->is_active = $active;
        $user->save();

        return $user;
    }

    public function test_patient_can_register_and_receives_a_token(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Ada Obi',
            'email' => 'ada@example.com',
            'phone' => '08011111111',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('user.role', 'patient')
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email', 'role']]);

        $this->assertDatabaseHas('patient_profiles', ['user_id' => $response->json('user.id')]);
    }

    public function test_registration_ignores_a_role_sent_by_the_client(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Sneaky',
            'email' => 'sneaky@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'admin',
            'is_active' => false,
        ])->assertCreated()->assertJsonPath('user.role', 'patient');

        $this->assertTrue(User::where('email', 'sneaky@example.com')->first()->is_active);
    }

    public function test_registration_rejects_a_duplicate_email(): void
    {
        $existing = $this->makeUser();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Copy',
            'email' => $existing->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_doctor_registers_as_pending(): void
    {
        $specialty = Specialty::create(['name' => 'Pediatrics', 'slug' => 'pediatrics']);

        $this->postJson('/api/v1/auth/register-doctor', [
            'name' => 'Dr. New',
            'email' => 'new.doctor@example.com',
            'phone' => '08022222222',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'specialty_id' => $specialty->id,
            'clinic_name' => 'New Clinic',
            'clinic_address' => '1 Test Road',
            'consultation_fee' => 7000,
        ])->assertCreated()
            ->assertJsonPath('user.role', 'doctor')
            ->assertJsonPath('user.doctor_status', 'pending');

        $this->assertSame('pending', Doctor::first()->status);
    }

    public function test_user_can_log_in_with_correct_credentials(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_login_fails_with_a_wrong_password(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = $this->makeUser(active: false);

        $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_returns_the_current_user(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.email', $user->email);
    }

    public function test_a_user_deactivated_after_login_is_blocked(): void
    {
        $user = $this->makeUser();
        Sanctum::actingAs($user);

        $user->is_active = false;
        $user->save();

        $this->getJson('/api/v1/me')->assertForbidden();
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->makeUser();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_role_middleware_blocks_the_wrong_role(): void
    {
        Route::middleware(['auth:sanctum', 'active', 'role:doctor'])
            ->get('/api/v1/_test/doctor-only', fn () => response()->json(['ok' => true]));

        Sanctum::actingAs($this->makeUser('patient'));
        $this->getJson('/api/v1/_test/doctor-only')->assertForbidden();

        Sanctum::actingAs($this->makeUser('doctor'));
        $this->getJson('/api/v1/_test/doctor-only')->assertOk();
    }
}