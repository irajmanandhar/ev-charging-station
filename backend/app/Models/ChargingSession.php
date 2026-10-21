<?php

namespace App\Models;

use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargingSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'charger_id',
        'booking_id',
        'started_at',
        'ended_at',
        'energy_kwh',
        'cost',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'energy_kwh' => 'float',
            'cost' => 'float',
            'status' => SessionStatus::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
