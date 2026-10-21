<?php

namespace App\Models;

use App\Enums\BookingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'charger_id',
        'starts_at',
        'ends_at',
        'status',
        'vehicle',
        'estimated_kwh',
        'estimated_cost',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_kwh' => 'float',
            'estimated_cost' => 'float',
            'status' => BookingStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Booking $booking) {
            $booking->reference ??= 'EV-'.strtoupper(Str::random(8));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function charger(): BelongsTo
    {
        return $this->belongsTo(Charger::class);
    }

    public function chargingSession(): HasOne
    {
        return $this->hasOne(ChargingSession::class);
    }

    /** Bookings that hold the charger and intersect [start, end). Half-open intervals. */
    public function scopeOverlapping(Builder $query, int $chargerId, $start, $end): Builder
    {
        return $query
            ->where('charger_id', $chargerId)
            ->whereIn('status', BookingStatus::blocking())
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start);
    }
}
