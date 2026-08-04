<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingRegistration extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CHECKOUT = 'checkout';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'token',
        'email',
        'password',
        'name',
        'business_name',
        'phone',
        'needs_invoice',
        'rfc',
        'legal_name',
        'tax_regime',
        'tax_zip',
        'cfdi_use',
        'stripe_customer_id',
        'stripe_subscription_id',
        'stripe_price_id',
        'status',
        'expires_at',
        'completed_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'needs_invoice' => 'boolean',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            // Password already hashed before save; do not double-hash.
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast()
            || $this->status === self::STATUS_EXPIRED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED && $this->user_id !== null;
    }

    public function isOpen(): bool
    {
        return ! $this->isCompleted() && ! $this->isExpired();
    }
}
