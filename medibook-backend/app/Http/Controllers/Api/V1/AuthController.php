<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterDoctorRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Doctor;
use App\Models\PatientProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = new User($data);       
            $user->role = 'patient';        
            $user->is_active = true;
            $user->save();

            PatientProfile::create([
                'user_id' => $user->id,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'gender' => $data['gender'] ?? null,
            ]);

            return $user;
        });

        return $this->tokenResponse($user, 'mobile', 201);
    }

    public function registerDoctor(RegisterDoctorRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = new User($data);
            $user->role = 'doctor';
            $user->is_active = true;
            $user->save();

            Doctor::forceCreate([
                'user_id' => $user->id,
                'specialty_id' => $data['specialty_id'],
                'clinic_name' => $data['clinic_name'],
                'clinic_address' => $data['clinic_address'],
                'consultation_fee' => $data['consultation_fee'],
                'years_experience' => $data['years_experience'] ?? 0,
                'bio' => $data['bio'] ?? null,
                'timezone' => $data['timezone'] ?? 'Africa/Lagos',
                'status' => 'pending', // hidden from patients until an admin approves
            ]);

            return $user;
        });

        return $this->tokenResponse($user, 'mobile', 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The email or password is incorrect.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'This account has been deactivated.'], 403);
        }

        return $this->tokenResponse($user, $request->input('device_name', 'mobile'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('doctor'));
    }

    private function tokenResponse(User $user, string $deviceName, int $status = 200): JsonResponse
    {
        return response()->json([
            'token' => $user->createToken($deviceName)->plainTextToken,
            'user' => new UserResource($user->load('doctor')),
        ], $status);
    }
}