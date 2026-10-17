<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;



class Specialty extends Model
{
    protected $fillable = ['name', 'slug'];
    protected static function booted(): void
    {
        static::saving(function (Specialty $specialty) {
            if ($specialty->isDirty('name')) {
                $specialty->slug = Str::slug($specialty->name);
            }
        });
    }

    public function doctors()
    {
        return $this->hasMany(Doctor::class);
    }
}
