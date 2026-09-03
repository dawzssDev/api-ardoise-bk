<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'name',
    'email',
    'password',
    'stripe_customer_id',
    'user_ardo_vip',
    'limit_sucursales',
    'limit_insumos',
    'limit_stock_insumos',
    'limit_proveedores',
    'limit_productos',
    'limit_stock_productos',
    'limit_personal',
    'limit_cuentas_contables',
    'limit_roles',
    'limit_staff',
    'block_POS'
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_ardo_vip' => 'integer',
            'limit_sucursales' => 'integer',
            'limit_insumos' => 'integer',
            'limit_stock_insumos' => 'integer',
            'limit_proveedores' => 'integer',
            'limit_productos' => 'integer',
            'limit_stock_productos' => 'integer',
            'limit_personal' => 'integer',
            'limit_cuentas_contables' => 'integer',
            'limit_roles' => 'integer',
            'limit_staff' => 'integer',
            'block_POS' => 'integer',
        ];
    }

    public function isArdoVip(): bool
    {
        return (int) $this->user_ardo_vip === 1;
    }

    public function isPosBlocked(): bool
    {
        return (int) $this->block_POS === 1;
    }

    /**
     * Enlace de recuperación apunta al frontend (/reset-password).
     * Solo aplica a usuarios de la tabla users (no staff).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification((string) $token));
    }

    /**
     * Negocio ligado al usuario maestro (1:1).
     */
    public function negocio(): HasOne
    {
        return $this->hasOne(Negocio::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
