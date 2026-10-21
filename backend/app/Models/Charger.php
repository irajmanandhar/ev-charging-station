<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\ChargerStatus;
use App\Enums\ConnectorType;
use App\Enums\LiveStatus;
use App\Enums\SessionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Charger extends Model
{
    use HasFactory;

    /** A confirmed booking starting within this many minutes marks the charger "reserved". */
    public const RESERVED_LEAD_MINUTES = 15;

    protected $attributes = [
        'status' => 'available',
    ];

    protected $fillable = [
        'station_id',
        'label',
        'connector_type',
        'power_kw',
        'price_per_kwh',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'power_kw' => 'float',
            'price_per_kwh' => 'float',
            'connector_type' => ConnectorType::class,
            'status' => ChargerStatus::class,
        ];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(Station::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function chargingSessions(): HasMany
    {
        return $this->hasMany(ChargingSession::class);
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(ChargingSession::class)->where('status', SessionStatus::Active->value);
    }

    /** The booking covering "now" or starting within the reserved lead time. */
    public function currentBooking(): HasOne
    {
        $now = now();

        return $this->hasOne(Booking::class)
            ->where('status', BookingStatus::Confirmed->value)
            ->where('starts_at', '<=', $now->copy()->addMinutes(self::RESERVED_LEAD_MINUTES))
            ->where('ends_at', '>', $now)
            ->orderBy('starts_at');
    }

    /**
     * Live status shown to drivers. Eager-load liveStateRelations() for list views
     * to avoid N+1 queries.
     */
    public function liveStatus(): LiveStatus
    {
        if ($this->status !== ChargerStatus::Available) {
            return LiveStatus::Offline;
        }

        if ($this->relationLoaded('station') && $this->station->status->value !== 'active') {
            return LiveStatus::Offline;
        }

        if ($this->activeSession) {
            return LiveStatus::Occupied;
        }

        if ($this->currentBooking) {
            return LiveStatus::Reserved;
        }

        return LiveStatus::Available;
    }

    public static function liveStateRelations(): array
    {
        return ['activeSession.booking:id,ends_at', 'currentBooking'];
    }
}
