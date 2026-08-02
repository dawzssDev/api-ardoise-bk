<?php

namespace App\Http\Requests\Empleado;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmpleadoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $aliases = [
            'first_name' => ['nombre', 'firstName'],
            'paternal_surname' => ['apellido_paterno', 'apellidoPaterno'],
            'maternal_surname' => ['apellido_materno', 'apellidoMaterno'],
            'birth_date' => ['fecha_nacimiento', 'fechaNacimiento'],
            'gender' => ['sexo'],
            'phone' => ['telefono'],
            'email' => ['correo', 'correo_electronico', 'correoElectronico'],
            'address' => ['domicilio'],
            'employee_number' => ['numero_empleado', 'numeroEmpleado'],
            'sucursal_id' => ['sucursalId', 'id_sucursal'],
            'role_id' => ['rol_id', 'rolId', 'roleId', 'puesto_id'],
            'supervisor_name' => ['jefe_inmediato', 'jefeInmediato'],
            'hire_date' => ['fecha_ingreso', 'fechaIngreso'],
            'contract_type' => ['tipo_contrato', 'tipoContrato'],
            'shift' => ['turno'],
            'status' => ['estatus'],
            'salary' => ['sueldo'],
            'salary_frequency' => ['frecuencia_sueldo', 'frecuenciaSueldo', 'periodo_sueldo'],
            'emergency_contact_name' => ['contacto_emergencia_nombre', 'emergencia_nombre'],
            'emergency_contact_relationship' => ['contacto_emergencia_parentesco', 'parentesco'],
            'emergency_contact_phone' => ['contacto_emergencia_telefono', 'emergencia_telefono'],
        ];

        $merge = [];

        foreach ($aliases as $field => $keys) {
            if ($this->exists($field)) {
                continue;
            }

            foreach ($keys as $alias) {
                if ($this->exists($alias)) {
                    $merge[$field] = $this->input($alias);
                    break;
                }
            }
        }

        if (! $this->hasFile('image') && $this->hasFile('imagen')) {
            $this->files->set('image', $this->file('imagen'));
        }

        foreach (['gender', 'status', 'salary_frequency'] as $field) {
            $value = $merge[$field] ?? $this->input($field);
            if (is_string($value) && $value !== '') {
                $merge[$field] = strtolower(trim($value));
            }
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $negocioId = $this->user()?->negocio?->id;
        $empleadoId = (int) $this->route('id');

        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'paternal_surname' => ['sometimes', 'required', 'string', 'max:100'],
            'maternal_surname' => ['sometimes', 'nullable', 'string', 'max:100'],
            'birth_date' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', Rule::in(Empleado::GENDERS)],
            'curp' => ['sometimes', 'nullable', 'string', 'max:18'],
            'rfc' => ['sometimes', 'nullable', 'string', 'max:13'],
            'nss' => ['sometimes', 'nullable', 'string', 'max:15'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'email' => ['sometimes', 'nullable', 'email', 'max:150'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'employee_number' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('empleados', 'employee_number')
                    ->where(fn ($q) => $q->where('negocio_id', $negocioId))
                    ->ignore($empleadoId),
            ],
            'sucursal_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'role_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'supervisor_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'hire_date' => ['sometimes', 'nullable', 'date'],
            'contract_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'shift' => ['sometimes', 'nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'string', Rule::in(Empleado::STATUSES)],
            'salary' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'salary_frequency' => [
                'sometimes',
                'nullable',
                'required_with:salary',
                'string',
                Rule::in(Empleado::SALARY_FREQUENCIES),
            ],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['sometimes', 'nullable', 'string', 'max:80'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre es obligatorio.',
            'paternal_surname.required' => 'El apellido paterno es obligatorio.',
            'employee_number.required' => 'El número de empleado es obligatorio.',
            'employee_number.unique' => 'Ya existe un empleado con ese número en tu negocio.',
            'sucursal_id.exists' => 'La sucursal no existe en tu negocio.',
            'role_id.exists' => 'El rol no existe en tu negocio.',
            'gender.in' => 'El sexo debe ser masculino, femenino u otro.',
            'status.in' => 'El estatus debe ser activo, inactivo o baja.',
            'salary_frequency.required_with' => 'Indica si el sueldo es diario, semanal o quincenal.',
            'salary_frequency.in' => 'La frecuencia del sueldo debe ser diario, semanal o quincenal.',
            'image.image' => 'El archivo debe ser una imagen.',
            'image.max' => 'La imagen no puede superar 2 MB.',
        ];
    }
}
