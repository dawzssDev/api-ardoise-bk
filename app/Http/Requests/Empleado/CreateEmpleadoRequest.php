<?php

namespace App\Http\Requests\Empleado;

use App\Models\Empleado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateEmpleadoRequest extends FormRequest
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

        if (isset($merge['gender']) && is_string($merge['gender'])) {
            $merge['gender'] = strtolower(trim($merge['gender']));
        } elseif ($this->filled('gender') && is_string($this->input('gender'))) {
            $merge['gender'] = strtolower(trim((string) $this->input('gender')));
        }

        if (isset($merge['status']) && is_string($merge['status'])) {
            $merge['status'] = strtolower(trim($merge['status']));
        } elseif ($this->filled('status') && is_string($this->input('status'))) {
            $merge['status'] = strtolower(trim((string) $this->input('status')));
        }

        if (isset($merge['salary_frequency']) && is_string($merge['salary_frequency'])) {
            $merge['salary_frequency'] = strtolower(trim($merge['salary_frequency']));
        } elseif ($this->filled('salary_frequency') && is_string($this->input('salary_frequency'))) {
            $merge['salary_frequency'] = strtolower(trim((string) $this->input('salary_frequency')));
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

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'paternal_surname' => ['required', 'string', 'max:100'],
            'maternal_surname' => ['nullable', 'string', 'max:100'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', Rule::in(Empleado::GENDERS)],
            'curp' => ['nullable', 'string', 'max:18'],
            'rfc' => ['nullable', 'string', 'max:13'],
            'nss' => ['nullable', 'string', 'max:15'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:500'],
            'employee_number' => [
                'required',
                'string',
                'max:50',
                Rule::unique('empleados', 'employee_number')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'sucursal_id' => [
                'required',
                'integer',
                Rule::exists('sucursales', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'role_id' => [
                'required',
                'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($q) => $q->where('negocio_id', $negocioId)
                ),
            ],
            'supervisor_name' => ['nullable', 'string', 'max:150'],
            'hire_date' => ['nullable', 'date'],
            'contract_type' => ['nullable', 'string', 'max:50'],
            'shift' => ['nullable', 'string', 'max:50'],
            'status' => ['sometimes', 'string', Rule::in(Empleado::STATUSES)],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'salary_frequency' => [
                'nullable',
                'required_with:salary',
                'string',
                Rule::in(Empleado::SALARY_FREQUENCIES),
            ],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'emergency_contact_name' => ['nullable', 'string', 'max:150'],
            'emergency_contact_relationship' => ['nullable', 'string', 'max:80'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:30'],
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
            'sucursal_id.required' => 'La sucursal es obligatoria.',
            'sucursal_id.exists' => 'La sucursal no existe en tu negocio.',
            'role_id.required' => 'El rol es obligatorio.',
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
