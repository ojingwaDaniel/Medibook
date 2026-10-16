<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\SpecialtyResource;
use App\Models\Specialty;

class SpecialtyController extends Controller
{
    public function index()
    {
        return SpecialtyResource::collection(Specialty::orderBy('name')->get());
    }
}