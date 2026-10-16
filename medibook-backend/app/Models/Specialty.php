<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Specialty extends Model
{
    protected $fillable = ['name', 'slug'];

    public function doctors()
    {
        return $this->hasMany(Doctor::class);
    }
}
