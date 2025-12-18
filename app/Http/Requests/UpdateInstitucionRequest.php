<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInstitucionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // ✅ Super admin y administrador pueden editar cualquier institución
        if (in_array($this->user()->rol, ['super_admin', 'administrador'])) {
            return true;
        }

        // Supervisor solo puede editar instituciones asignadas
        return $this->user()->instituciones->pluck('id')->contains($this->route('id'));
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'codigo_modular_ie' => 'sometimes|required|string|max:20|unique:instituciones,codigo_modular_ie,' . $id,
            'nombre' => 'sometimes|required|string|max:255',
            'distrito' => 'sometimes|required|string|max:100',
            'nivel_educativo' => 'nullable|string|max:100',
            'centro_poblado' => 'nullable|string|max:150',
            'direccion' => 'nullable|string|max:255',
            'latitud' => 'nullable|numeric',
            'longitud' => 'nullable|numeric',
            'radio' => 'nullable|numeric|min:1',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,svg|max:2048',
        ];
    }
}