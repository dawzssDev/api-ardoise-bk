<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Proveedor extends Model
{
    protected $table = 'proveedores';

    public const STATUS_BAJA = 0;

    public const STATUS_ACTIVO = 1;

    protected $fillable = [
        'negocio_id',
        'name',
        'legal_name',
        'rfc',
        'phone',
        'email',
        'address',
        'contact_name',
        'notes',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'integer',
        ];
    }

    public function isActivo(): bool
    {
        return (int) $this->status === self::STATUS_ACTIVO;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function gastosEnTurno(): HasMany
    {
        return $this->hasMany(GastoEnTurno::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
