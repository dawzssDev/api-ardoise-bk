<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Subscription extends Model
{
    protected $fillable = [
        'user_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'status',
        'current_period_start',
        'current_period_end',
        'cancel_at_period_end',
        'access_until',
        'trial_ends_at',
        'canceled_at',
    ];

    protected function casts(): array
    {
        return [
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancel_at_period_end' => 'boolean',
            'access_until' => 'datetime',
            'trial_ends_at' => 'datetime',
            'canceled_at' => 'datetime',
        ];
    }

    public function accessUntil(): ?Carbon
    {
        return $this->access_until ?? $this->current_period_end;
    }

    /**
     * Cancelación pedida por el titular (no impago / no renovación).
     */
    public function isUserCanceled(): bool
    {
        return (bool) $this->cancel_at_period_end;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
