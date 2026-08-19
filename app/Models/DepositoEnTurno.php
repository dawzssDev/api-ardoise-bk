<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositoEnTurno extends Model
{
    protected $table = 'tb_deposito_efectivo_en_turno';

    protected $fillable = [
        'turno_caja_id',
        'id_user',
        'user_id',
        'negocio_id',
        'sucursal_id',
        'descripcion',
        'monto',
        'fecha_registro',
    ];

    protected function casts(): array
    {
        return [
            'monto' => 'decimal:2',
            'fecha_registro' => 'datetime',
        ];
    }

    public function turnoCaja(): BelongsTo
    {
        return $this->belongsTo(TurnoCaja::class, 'turno_caja_id');
    }

    public function cajero(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'id_user');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }
}
