<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Models\User;
class PatientProfile extends Model
{
    protected $fillable = ['user_id', 'date_of_birth', 'gender'];
    protected $casts = ['date_of_birth' => 'date'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
