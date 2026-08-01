<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sucursal extends Model
{
    public const TYPE_SUCURSAL = 'sucursal';

    public const TYPE_BODEGA = 'bodega';

    public const TYPES = [
        self::TYPE_SUCURSAL,
        self::TYPE_BODEGA,
    ];

    protected $table = 'sucursales';

    protected $fillable = [
        'negocio_id',
        'type',
        'name',
        'is_active',
        'street',
        'neighborhood',
        'city',
        'state',
        'postal_code',
        'opened_year',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opened_year' => 'integer',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }
}
