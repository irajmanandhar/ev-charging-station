<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Notifications\EmailVerificationOtp;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'email_verified_at',
    ];

    /** Role is deliberately not fillable: only admins change it, explicitly. */
    protected $attributes = [
        'role' => 'driver',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
        ];
    }

    public function stations(): HasMany
    {
        return $this->hasMany(Station::class, 'operator_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isOperator(): bool
    {
        return $this->role === UserRole::Operator;
    }

    public function sendEmailVerificationNotification(): void
    {
        $otpRecord = UserOtp::generateOtp($this->email, 'email_verification');
        $this->notify(new EmailVerificationOtp($otpRecord->plain));
    }
}
