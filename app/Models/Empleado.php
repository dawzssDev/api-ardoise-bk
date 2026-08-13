<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Empleado extends Model
{
    protected $table = 'empleados';

    public const GENDERS = ['masculino', 'femenino', 'otro'];

    public const STATUSES = ['activo', 'inactivo', 'baja'];

    public const SALARY_FREQUENCIES = ['diario', 'semanal', 'quincenal'];

    protected $fillable = [
        'negocio_id',
        'sucursal_id',
        'role_id',
        'first_name',
        'paternal_surname',
        'maternal_surname',
        'birth_date',
        'gender',
        'curp',
        'rfc',
        'nss',
        'phone',
        'email',
        'address',
        'employee_number',
        'supervisor_name',
        'hire_date',
        'contract_type',
        'shift',
        'status',
        'salary',
        'salary_frequency',
        'image',
        'emergency_contact_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
        'datos_beneficiarios',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'hire_date' => 'date',
            'salary' => 'decimal:2',
            'datos_beneficiarios' => 'array',
        ];
    }

    /**
     * Normaliza el JSON de beneficiarios a una lista de objetos canónicos.
     *
     * @return list<array{nombre_completo: string, contacto: string, parentesco: string, porcentaje: float|int|string|null}>|mixed
     */
    public static function normalizeBeneficiarios(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return $value;
            }
            $value = $decoded;
        }

        if (! is_array($value)) {
            return $value;
        }

        $items = array_is_list($value) ? $value : array_values($value);
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $normalized[] = [
                'nombre_completo' => trim((string) (
                    $item['nombre_completo']
                    ?? $item['nombreCompleto']
                    ?? $item['nombre']
                    ?? $item['Nombre completo Beneficiario']
                    ?? $item['nombre_completo_beneficiario']
                    ?? ''
                )),
                'contacto' => trim((string) (
                    $item['contacto']
                    ?? $item['telefono']
                    ?? $item['Contacto beneficiario']
                    ?? $item['contacto_beneficiario']
                    ?? ''
                )),
                'parentesco' => trim((string) (
                    $item['parentesco']
                    ?? $item['Parentesco']
                    ?? $item['relacion']
                    ?? ''
                )),
                'porcentaje' => $item['porcentaje']
                    ?? $item['Porcentaje']
                    ?? $item['percent']
                    ?? null,
            ];
        }

        return $normalized;
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }

    public function sucursal(): BelongsTo
    {
        return $this->belongsTo(Sucursal::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function fullName(): string
    {
        return trim(implode(' ', array_filter([
            $this->first_name,
            $this->paternal_surname,
            $this->maternal_surname,
        ])));
    }

    public function imageUrl(): ?string
    {
        if (! $this->image) {
            return null;
        }

        $path = $this->image;
        if (str_starts_with($path, 'empleados/')) {
            $path = substr($path, strlen('empleados/'));
        }

        $parts = explode('/', $path, 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return route('empleados.image', [
            'negocioId' => $parts[0],
            'filename' => $parts[1],
        ]);
    }
}
