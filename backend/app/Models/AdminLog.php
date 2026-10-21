<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLog extends Model
{
    protected $fillable = ['admin_id', 'action', 'subject_type', 'subject_id', 'details'];

    protected function casts(): array
    {
        return ['details' => 'array'];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public static function record(User $admin, string $action, ?Model $subject = null, array $details = []): self
    {
        return self::create([
            'admin_id' => $admin->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'details' => $details ?: null,
        ]);
    }
}
