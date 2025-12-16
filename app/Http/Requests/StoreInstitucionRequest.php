<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreInstitucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // solo administrador puede crear instituciones
        return $this->user()->rol === 'administrador';
    }

    public function rules(): array
    {
        return [
            'codigo_modular_ie' => 'required|string|max:20|unique:instituciones,codigo_modular_ie',
            'nombre' => 'required|string|max:255',
            'distrito' => 'required|string|max:100',
            'nivel_educativo' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:150',
            'direccion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
            'radio' => 'nullable|numeric|min:1',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048', // máx 2MB
        ];
    }
}
