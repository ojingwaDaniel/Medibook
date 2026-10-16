<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
class DeviceToken extends Model
{
    protected $fillable = ['user_id', 'token', 'platform'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
