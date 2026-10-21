<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    public const INITIATED = 'initiated';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const REFUNDED = 'refunded';
    public const REFUND_PENDING = 'refund_pending';

    protected $fillable = [
        'user_id', 'booking_id', 'provider', 'reference', 'amount', 'refunded_amount', 'currency', 'status', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'refunded_amount' => 'float',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
