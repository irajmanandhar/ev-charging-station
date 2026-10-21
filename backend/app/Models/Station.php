<?php

namespace App\Models;

use App\Enums\StationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

class Station extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
        'timezone' => 'Asia/Kathmandu',
        'is_24_hours' => false,
    ];

    protected $fillable = [
        'operator_id',
        'name',
        'description',
        'address',
        'city',
        'latitude',
        'longitude',
        'place_id',
        'timezone',
        'is_24_hours',
        'opens_at',
        'closes_at',
        'amenities',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_24_hours' => 'boolean',
            'amenities' => 'array',
            'status' => StationStatus::class,
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function chargers(): HasMany
    {
        return $this->hasMany(Charger::class)->orderBy('label');
    }

    public function bookings(): HasManyThrough
    {
        return $this->hasManyThrough(Booking::class, Charger::class);
    }

    public function chargingSessions(): HasManyThrough
    {
        return $this->hasManyThrough(ChargingSession::class, Charger::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', StationStatus::Active->value);
    }

    /**
     * Cheap bounding-box prefilter so the exact haversine distance only runs on nearby rows.
     * Works on MySQL and SQLite alike.
     */
    public function scopeWithinBox(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        $latDelta = $radiusKm / 111.0;
        $lngDelta = $radiusKm / max(0.0001, 111.0 * cos(deg2rad($lat)));

        return $query
            ->whereBetween('latitude', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('longitude', [$lng - $lngDelta, $lng + $lngDelta]);
    }

    public function distanceKmTo(float $lat, float $lng): float
    {
        $earth = 6371.0;
        $dLat = deg2rad($this->latitude - $lat);
        $dLng = deg2rad($this->longitude - $lng);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat)) * cos(deg2rad($this->latitude)) * sin($dLng / 2) ** 2;

        return round($earth * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /** Is the station open over the whole [start, end) window, in its own time zone? */
    public function isOpenBetween(Carbon $start, Carbon $end): bool
    {
        if ($this->is_24_hours || ! $this->opens_at || ! $this->closes_at) {
            return true;
        }

        $localStart = $start->copy()->setTimezone($this->timezone);
        $localEnd = $end->copy()->setTimezone($this->timezone);

        // Bookings may not straddle two local days at a non-24h station.
        if ($localStart->toDateString() !== $localEnd->copy()->subSecond()->toDateString()) {
            return false;
        }

        $opens = $localStart->copy()->setTimeFromTimeString($this->opens_at);
        $closes = $localStart->copy()->setTimeFromTimeString($this->closes_at);

        return $localStart->gte($opens) && $localEnd->lte($closes);
    }
}
