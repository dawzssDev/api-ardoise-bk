<?php

namespace App\Http\Requests\Negocio;

use Illuminate\Foundation\Http\FormRequest;

class UploadNegocioLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('logo')) {
            return;
        }

        foreach (['imagen', 'image'] as $alias) {
            if ($this->hasFile($alias)) {
                $this->files->set('logo', $this->file($alias));
                break;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.required' => 'El logo del negocio es obligatorio.',
            'logo.image' => 'El archivo debe ser una imagen.',
            'logo.mimes' => 'El logo debe ser jpg o png.',
            'logo.max' => 'El logo no puede superar 2 MB.',
        ];
    }
}
