<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthProvider extends Model
{
    protected $fillable = ['user_id', 'provider', 'provider_id', 'email'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
