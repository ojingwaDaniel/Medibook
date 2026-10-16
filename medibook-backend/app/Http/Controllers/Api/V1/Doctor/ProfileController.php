<?php

namespace App\Http\Controllers\Api\V1\Doctor;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateDoctorProfileRequest;
use App\Http\Resources\DoctorResource;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): DoctorResource
    {
        return new DoctorResource($request->user()->doctorProfile()->load(['user:id,name', 'specialty']));
    }

    public function update(UpdateDoctorProfileRequest $request): DoctorResource
    {
        $doctor = $request->user()->doctorProfile();
        $doctor->update($request->validated()); // status is not fillable, so it can't be changed here

        return new DoctorResource($doctor->load(['user:id,name', 'specialty']));
    }
}